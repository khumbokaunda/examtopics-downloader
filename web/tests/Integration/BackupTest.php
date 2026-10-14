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
        $this->s->users()->create('member', 'member password long', Access::MEMBER, ['email' => 'm@example.com', 'email_verified' => true]);
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        $this->post('/settings/branding', ['name' => 'Kaunda Exams', 'accent' => 'teal']);
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
        sleep(1);
        $lean = $this->makeBackup(['documents' => '1']);
        self::assertSame(0, $lean['manifest']['tables']['media']);
        self::assertNull($lean['manifest']['files']['media']);
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
        $b = $this->makeBackup();
        $cacheFile = (string) $this->app->db()->value('SELECT version FROM cache_versions');
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

        $this->flashes();
        self::assertSame(303, $this->post('/backups/' . $b['name'] . '/restore', ['password' => 'wrong password'])->status);
        self::assertStringContainsString('password was not right', $this->flashes()[0]['message']);
        self::assertNotNull($this->s->users()->findByUsername('intruder'), 'nothing restored');

        $r = $this->post('/backups/' . $b['name'] . '/restore', ['password' => self::PW]);
        self::assertSame('/login?restored=1', $r->headers['Location']);
        self::assertSame(303, $this->get('/library')->status, 'everyone is signed out');
        self::assertStringContainsString('restored from a backup', $this->get('/login', ['restored' => '1'])->body);

        self::assertNull($this->s->users()->findByUsername('intruder'));
        self::assertSame((int) $member['id'], (int) $this->s->users()->findByUsername('member')['id'], 'ids are kept');
        self::assertSame('Kaunda Exams', $this->s->settings()->get('brand.name'));
        self::assertNotEmpty(glob($this->app->paths()->cacheRoot() . '/huawei/h12-811/*'), 'the exam files are back');
        self::assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running')"));
        $names = array_column($this->s->backup()->list(), 'name');
        self::assertNotEmpty(array_filter($names, static fn($n) => str_contains($n, '-before-restore')), 'a safety backup was made first');

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
