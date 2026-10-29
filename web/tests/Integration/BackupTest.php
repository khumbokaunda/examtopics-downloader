<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use App\Services\Backup;
use Tests\Support\HttpTestCase;
use ZipArchive;

/** Backups: what goes in, what is refused, and restoring here or onto an empty server. */
final class BackupTest extends HttpTestCase
{
    private const PW = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
        $this->confirmIt();
        $this->s->users()->create('member', 'member password long', Access::MEMBER, ['email' => 'm@example.com', 'email_verified' => true]);
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        $this->post('/settings/branding', ['name' => 'Kaunda Exams', 'accent' => 'teal']);
    }

    /** Backups ask the person to confirm it is them; here that was just done. */
    private function confirmIt(): void
    {
        $this->session->set('_confirmed', ['user' => (int) $this->s->users()->findByUsername('admin')['id'], 'until' => time() + 3600]);
    }

    private function makeBackup(array $options = ['media' => '1', 'documents' => '1']): array
    {
        $this->flashes();
        $r = $this->post('/backups', $options);
        self::assertSame(303, $r->status);
        $flash = $this->flashes()[0];
        self::assertSame('success', $flash['type'], $flash['message']);
        $list = $this->s->backup()->list();
        self::assertNotEmpty($list);
        return $list[0];
    }

    /** A small PNG file in storage, as if uploaded. */
    private function png(): string
    {
        $img = imagecreatetruecolor(3, 2);
        $path = $this->app->paths()->storage() . '/picture-' . bin2hex(random_bytes(3)) . '.png';
        imagepng($img, $path);
        return $path;
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $path = $this->app->paths()->storage() . '/crafted-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }

    public function testABackupHoldsTheDataAndTheFiles(): void
    {
        $b = $this->makeBackup();
        self::assertMatchesRegularExpression('/^examtopics-backup-\d{8}-\d{6}\.zip$/', $b['name']);
        $m = $b['manifest'];
        self::assertSame(Backup::FORMAT, $m['format_version']);
        self::assertSame('admin', $m['exported_by']);
        self::assertSame(2, $m['tables']['users']);
        self::assertGreaterThan(0, $m['tables']['cache_versions']);
        self::assertGreaterThan(0, $m['files']['cache']['count'], 'the exam data travels');
        self::assertContains('012_signup_requests_notifications', $m['schema_migrations']);

        $zip = new ZipArchive();
        $zip->open($this->s->backup()->path($b['name']));
        foreach (Backup::SKIP as $skipped) {
            self::assertFalse($zip->getFromName('data/' . $skipped . '.json'), $skipped . ' is not exported');
        }
        $settings = json_decode((string) $zip->getFromName('data/settings.json'), true);
        self::assertContains('Kaunda Exams', array_column($settings, 'value'));
        $zip->close();

        self::assertSame(200, $this->get('/backups')->status);
        $download = $this->get('/backups/' . $b['name'] . '/download');
        self::assertSame('application/zip', $download->headers['Content-Type']);
        foreach (['../../config.php', 'examtopics-backup-1.zip', 'x.zip'] as $bad) {
            self::assertSame(404, $this->get('/backups/' . rawurlencode($bad) . '/download')->status, $bad);
        }

        // Without images, the image rows stay behind too, so nothing points at missing files.
        $this->app->db()->run("INSERT INTO media (url_hash, url, status, updated_at) VALUES (?, 'https://x/y.png', 'failed', NOW())", [str_repeat('a', 64)]);
        // Pictures added by hand cannot be fetched again, so they always travel.
        self::assertIsArray($this->s->media()->storeUpload($this->png(), null));
        sleep(1);
        $lean = $this->makeBackup(['documents' => '1']);
        self::assertSame(0, $lean['manifest']['tables']['media']);
        self::assertNull($lean['manifest']['files']['media']);
        self::assertSame(1, $lean['manifest']['tables']['uploaded_images']);
        self::assertSame(1, $lean['manifest']['files']['uploads']['count']);
    }

    public function testProgressCancelAndDelete(): void
    {
        $b = $this->makeBackup();
        $status = json_decode($this->get('/api/backups/status')->body, true);
        self::assertSame(['completed', true, 100, $b['name']], [$status['status'], $status['final'], $status['percent'], $status['name']]);
        self::assertStringContainsString('Backup ready', $this->get('/backups')->body);

        // A backup whose process died is reported as failed, not as running for ever.
        $backup = $this->s->backup();
        $backup->writeStatus(['status' => 'running', 'phase' => 'writing the archive', 'percent' => 40, 'message' => 'Packing', 'pid' => 999999, 'started_at' => time() - 30]);
        self::assertSame('failed', $backup->status()['status']);
        self::assertStringContainsString('stopped before it finished', $this->get('/backups')->body);

        // Cancelling one in progress deletes its unfinished file; the page shows its progress until then.
        $backup->writeStatus(['status' => 'running', 'phase' => 'writing the archive', 'percent' => 40, 'message' => 'Packing 10 files: 33% written',
            'pid' => getmypid(), 'name' => 'examtopics-backup-20261014-120000.zip', 'started_at' => time()]);
        $page = $this->get('/backups')->body;
        self::assertStringContainsString('Making a backup', $page);
        self::assertStringContainsString('data-percent="40"', $page);
        self::assertStringContainsString('disabled><i class="bi bi-archive"', $page, 'no second backup while one is being made');
        touch($backup->dir() . '/examtopics-backup-20261014-120000.zip.part.Ab12Cd');
        $this->post('/backups/cancel');
        self::assertSame('cancelled', $backup->status()['status']);
        self::assertSame([], glob($backup->dir() . '/*.part*'));

        // Delete.
        self::assertStringContainsString('>Delete</button>', $this->get('/backups')->body);
        $this->post('/backups/' . $b['name'] . '/delete');
        self::assertNull($backup->path($b['name']));
        self::assertSame(404, $this->post('/backups/' . $b['name'] . '/delete')->status);
    }

    /** One piece of a browser upload, as the page sends it: the offset in the address, the bytes as a file. */
    private function piece(string $id, int $offset, string $bytes, int $error = UPLOAD_ERR_OK): \App\Core\Http\Response
    {
        $tmp = $this->app->paths()->storage() . '/piece-' . bin2hex(random_bytes(3));
        file_put_contents($tmp, $bytes);
        return $this->kernel()->handle(new \App\Core\Http\Request('POST', '/backups/uploads/' . $id, ['offset' => (string) $offset],
            ['_csrf' => $this->session->csrfToken()], ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT' => 'application/json'],
            ['chunk' => ['name' => 'piece', 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($bytes)]]));
    }

    /** @return array<string,mixed> */
    private function startUpload(string $name, int $size, int $modified = 1700000000): array
    {
        $r = $this->post('/backups/uploads', ['name' => $name, 'size' => (string) $size, 'modified' => (string) $modified]);
        self::assertSame(200, $r->status, $r->body);
        return json_decode($r->body, true);
    }

    public function testUploadsArriveInPiecesAndCarryOnWhereTheyStopped(): void
    {
        $b = $this->makeBackup(['documents' => '1']);
        $bytes = (string) file_get_contents((string) $this->s->backup()->path($b['name']));
        $this->s->backup()->delete($b['name']);
        $half = intdiv(strlen($bytes), 2);

        $start = $this->startUpload('old-server.zip', strlen($bytes));
        self::assertSame(0, $start['received']);
        self::assertGreaterThan(0, $start['chunk']);
        $id = $start['id'];
        $r = $this->piece($id, 0, substr($bytes, 0, $half));
        self::assertSame(['received' => $half, 'size' => strlen($bytes)], json_decode($r->body, true));

        // A piece for the wrong place is refused with what the server has, so the page resends from there.
        $r = $this->piece($id, 0, substr($bytes, 0, 10));
        self::assertSame(409, $r->status);
        self::assertSame($half, json_decode($r->body, true)['received']);
        // A piece PHP refused as too large, and more bytes than the file has.
        self::assertSame(413, $this->piece($id, $half, '', UPLOAD_ERR_INI_SIZE)->status);
        self::assertSame(422, $this->piece($id, $half, str_repeat('x', strlen($bytes)))->status);
        // Finishing early says how much is missing.
        $r = $this->post('/backups/uploads/' . $id . '/finish');
        self::assertSame(409, $r->status);

        // The page lists the unfinished upload; choosing the same file again carries on from the half.
        $page = $this->get('/backups')->body;
        self::assertStringContainsString('Unfinished uploads', $page);
        self::assertStringContainsString('old-server.zip', $page);
        self::assertStringContainsString('data-chunked-upload', $page);
        self::assertSame(['id' => $id, 'received' => $half], array_intersect_key($this->startUpload('old-server.zip', strlen($bytes)), ['id' => 1, 'received' => 1]));
        self::assertNotSame($id, $this->startUpload('old-server.zip', strlen($bytes), 1700000001)['id'], 'another file is another upload');

        self::assertSame(200, $this->piece($id, $half, substr($bytes, $half))->status);
        $this->flashes();
        $r = $this->post('/backups/uploads/' . $id . '/finish');
        self::assertSame(200, $r->status, $r->body);
        $name = json_decode($r->body, true)['name'];
        self::assertMatchesRegularExpression('/^examtopics-backup-\d{8}-\d{6}-uploaded\.zip$/', $name);
        self::assertSame($bytes, file_get_contents((string) $this->s->backup()->path($name)));
        self::assertStringContainsString('Uploaded as ' . $name, $this->flashes()[0]['message']);
        self::assertSame(404, $this->post('/backups/uploads/' . $id . '/finish')->status, 'the pieces are gone');

        // A file that is not a backup is refused when it is complete, and not kept.
        $junk = $this->startUpload('junk.zip', 12);
        $this->piece($junk['id'], 0, 'not a backup');
        $r = $this->post('/backups/uploads/' . $junk['id'] . '/finish');
        self::assertSame(422, $r->status);
        self::assertStringContainsString('cannot be used', json_decode($r->body, true)['error']);
        self::assertSame([$name], array_column($this->s->backup()->list(), 'name'));

        // Someone else's upload is not reachable, and ids are checked.
        $theirs = $this->s->backupUploads()->start(999, 'theirs.zip', 100, 1);
        self::assertSame(404, $this->piece($theirs['id'], 0, 'x')->status);
        self::assertSame(404, $this->piece('../../x', 0, 'x')->status);

        // Discard, and the cleanup of uploads nobody has added to for a week.
        $this->post('/backups/uploads/' . $this->startUpload('other.zip', 50, 5)['id'] . '/discard');
        $uploads = $this->s->backupUploads();
        self::assertSame(['old-server.zip'], array_column($uploads->pending((int) $this->s->users()->findByUsername('admin')['id']), 'name'),
            'only the upload of the other copy is left');
        foreach (glob($uploads->dir() . '/*') ?: [] as $f) {
            touch($f, time() - 8 * 86400);
        }
        self::assertSame(2, $uploads->prune(7), 'the upload of another file, and the other person\'s');
        self::assertSame([], glob($uploads->dir() . '/*'));
    }

    public function testRestoreFromThePageRunsInTheBackground(): void
    {
        $b = $this->makeBackup(['documents' => '1']);
        $this->s->users()->create('intruder', 'intruder password', 'admin');
        $this->configure($this->startFixture(), null, ['backup' => ['background' => true]]);
        // Signed in before the restore: the restore ends this sign-in, wherever the session is kept.
        $this->session->set('_started_at', time() - 10);

        $r = $this->post('/backups/' . $b['name'] . '/restore', ['password' => self::PW]);
        self::assertSame(303, $r->status);
        self::assertStringEndsWith('/backups', $r->headers['Location']);
        $status = null;
        for ($i = 0; $i < 120; $i++) {
            $status = $this->s->backup()->status();
            if (($status['final'] ?? false) === true) {
                break;
            }
            if ($i === 0) {
                self::assertSame('restore', $status['kind']);
                $page = $this->get('/backups')->body;
                self::assertStringContainsString('Restoring a backup', $page);
                self::assertStringContainsString('data-signed-out-url=', $page);
                self::assertStringNotContainsString('/backups/cancel', $page, 'a restore is not cancelled half way');
            }
            usleep(250000);
        }
        self::assertSame(['restore', 'completed', 100], [$status['kind'], $status['status'], $status['percent']], (string) $status['message']);
        self::assertNull($this->s->users()->findByUsername('intruder'));
        self::assertSame('/login?restored=1&next=%2Fbackups', $this->get('/backups')->headers['Location'], 'the sign-in form says why');
        self::assertSame(401, $this->kernel()->handle(new \App\Core\Http\Request('GET', '/api/backups/status', [], [], ['REMOTE_ADDR' => $this->ip]))->status,
            'the page polling the progress learns that it was signed out');
        $this->login();
        self::assertStringContainsString('Last restore', $this->get('/backups')->body);
        self::assertStringNotContainsString('Backup ready', $this->get('/backups')->body);
    }

    public function testHostileOrUnusableArchivesAreRefused(): void
    {
        $backup = $this->s->backup();
        $manifest = json_encode(['format_version' => 1, 'app' => 'Examtopics', 'schema_migrations' => []]);
        $admin = json_encode([['id' => 1, 'username' => 'a', 'role' => 'admin', 'status' => 'active']]);
        foreach ([
            'files/cache/../../../public/x.php' => 'not allowed',
            'files/sessions/sess_x' => 'not allowed',
            '/etc/passwd' => 'not allowed',
            'data/../users.json' => 'not allowed',
        ] as $entry => $expect) {
            $check = $backup->inspect($this->zip(['manifest.json' => $manifest, 'data/users.json' => $admin, $entry => 'x']));
            self::assertStringContainsString($expect, implode(' ', $check['errors']), $entry);
        }
        self::assertStringContainsString('no Examtopics manifest', implode(' ', $backup->inspect($this->zip(['data/users.json' => $admin]))['errors']));
        self::assertStringContainsString('cannot be opened', implode(' ', $backup->inspect(__FILE__)['errors']));
        $noAdmin = $backup->inspect($this->zip(['manifest.json' => $manifest, 'data/users.json' => json_encode([['role' => 'member', 'status' => 'active']])]));
        self::assertStringContainsString('no active administrator', implode(' ', $noAdmin['errors']));
        $newer = $backup->inspect($this->zip(['manifest.json' => json_encode(['format_version' => 1, 'app' => 'Examtopics', 'schema_migrations' => ['999_future']]), 'data/users.json' => $admin]));
        self::assertStringContainsString('newer version', implode(' ', $newer['errors']));

        // An upload is checked the same way before it is kept.
        $this->flashes();
        $file = $this->zip(['manifest.json' => $manifest, 'data/users.json' => $admin, 'files/cache/../x' => 'x']);
        $this->postFiles('/backups/upload', [], ['archive' => ['name' => 'b.zip', 'tmp_name' => $file, 'size' => filesize($file), 'error' => UPLOAD_ERR_OK]]);
        self::assertSame('danger', $this->flashes()[0]['type']);
        self::assertSame([], $backup->list());
    }

    public function testRestoreBringsEverythingBack(): void
    {
        // A job running when the backup is made is not running after a restore.
        $this->app->db()->run("UPDATE jobs SET status = 'running', active_slot = 'collect' ORDER BY id DESC LIMIT 1");
        $this->flashes();
        $upload = $this->s->media()->storeUpload($this->png(), null);
        self::assertIsArray($upload);
        $b = $this->makeBackup();
        $cacheFile = (string) $this->app->db()->value('SELECT version FROM cache_versions');
        $uploaded = $this->app->paths()->uploadFile($upload['sha256'], 'png');
        unlink($uploaded);
        $member = $this->s->users()->findByUsername('member');

        // Things change after the backup.
        $this->s->users()->delete((int) $member['id']);
        $this->s->users()->create('intruder', 'intruder password', 'admin');
        $this->post('/settings/branding', ['name' => 'Changed', 'accent' => 'rose']);
        $files = glob($this->app->paths()->cacheRoot() . '/huawei/h12-811/*') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }

        // Session files: one last used before the restore, one used since the accounts were
        // replaced (someone sent to the sign-in form while the files were still being written).
        $sessions = $this->app->paths()->storage() . '/sessions';
        @mkdir($sessions, 0700, true);
        touch($sessions . '/sess_before', time() - 100);
        touch($sessions . '/sess_since', time() + 30);

        $this->flashes();
        // Not confirmed (it ran out): sent to confirm it is them, and nothing is restored.
        $this->session->set('_confirmed', ['user' => (int) $this->s->users()->findByUsername('admin')['id'], 'until' => time() - 1]);
        self::assertSame('/confirm?next=%2Fbackups', $this->post('/backups/' . $b['name'] . '/restore')->headers['Location']);
        self::assertNotNull($this->s->users()->findByUsername('intruder'), 'nothing restored');
        $this->confirmIt();

        $r = $this->post('/backups/' . $b['name'] . '/restore', ['password' => self::PW]);
        self::assertSame('/login?restored=1', $r->headers['Location']);
        self::assertSame(303, $this->get('/library')->status, 'everyone is signed out');
        self::assertStringContainsString('restored from a backup', $this->get('/login', ['restored' => '1'])->body);

        self::assertNull($this->s->users()->findByUsername('intruder'));
        self::assertFileDoesNotExist($sessions . '/sess_before');
        self::assertFileExists($sessions . '/sess_since', 'a sign-in form opened part way through still works');
        self::assertSame((int) $member['id'], (int) $this->s->users()->findByUsername('member')['id'], 'ids are kept');
        self::assertSame('Kaunda Exams', $this->s->settings()->get('brand.name'));
        self::assertNotEmpty(glob($this->app->paths()->cacheRoot() . '/huawei/h12-811/*'), 'the exam files are back');
        self::assertFileExists($uploaded, 'and the pictures added by hand');
        self::assertNotNull($this->s->media()->file($upload['sha256']));
        self::assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running')"));
        $names = array_column($this->s->backup()->list(), 'name');
        self::assertNotEmpty(array_filter($names, static fn($n) => str_contains($n, '-before-restore')), 'a safety backup was made first');
        // The history is the backup's, still one unbroken chain, with the restore added to it.
        self::assertTrue($this->s->audit()->verify()['ok']);
        $last = $this->app->db()->one('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
        self::assertSame(['backup.restored', 'admin'], [$last['action'], $last['actor']]);
        self::assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'user.created' AND details LIKE '%intruder%'"));

        $this->newSession();
        $this->login('member', 'member password long');
        self::assertSame(200, $this->get('/library/huawei/h12-811/view')->status, 'the restored library reads');
        self::assertNotSame('', $cacheFile);
        $this->newSession();
        $this->login();
        self::assertStringContainsString('Last restore', $this->get('/backups')->body);
    }

    /** A new server: empty tables, nobody to sign in. The command line restores the backup. */
    public function testCommandLineRestoreOntoAnEmptyServer(): void
    {
        $b = $this->makeBackup(['documents' => '1']);
        $path = $this->s->backup()->path($b['name']);
        $copy = $this->app->paths()->storage() . '/moved.zip';
        copy((string) $path, $copy);

        $db = $this->app->db();
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->all('SHOW TABLES') as $row) {
            $t = array_values($row)[0];
            if ($t !== 'schema_migrations') {
                $db->pdo()->exec('TRUNCATE TABLE `' . $t . '`');
            }
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        \Tests\Support\DbTestCase::rmrf($this->app->paths()->cacheRoot());
        self::assertSame(0, $this->s->users()->count());

        $proc = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/backup.php', 'restore', $copy, '--yes'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($proc), $out);
        self::assertStringContainsString('Restored.', $out);

        self::assertSame(2, $this->s->users()->count());
        $this->newSession();
        $this->login();
        self::assertSame(200, $this->get('/library/huawei/h12-811')->status);
    }
}
