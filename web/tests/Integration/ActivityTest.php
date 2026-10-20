<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use App\Services\AuditLog;
use App\Services\AuditText;
use Tests\Support\HttpTestCase;

/** The activity history: what is recorded, how it reads, who can see it, and that it cannot be quietly changed. */
final class ActivityTest extends HttpTestCase
{
    private const PW = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
    }

    /** @return list<array<string,mixed>> */
    private function entries(?string $action = null): array
    {
        return $action === null
            ? $this->app->db()->all('SELECT * FROM audit_log ORDER BY id')
            : $this->app->db()->all('SELECT * FROM audit_log WHERE action = ? ORDER BY id', [$action]);
    }

    private function text(array $row): string
    {
        return AuditText::describe($row)['text'];
    }

    public function testWhatPeopleDoIsRecordedInPlainEnglish(): void
    {
        // Failed sign-ins: a real account named, a password typed into the username box hidden.
        $this->s->users()->create('admin', self::PW, Access::ADMIN);
        $this->post('/login', ['username' => 'admin', 'password' => 'wrong password here']);
        $this->post('/login', ['username' => 'MySecretPassword99', 'password' => 'x']);
        $failed = $this->entries('auth.sign_in_failed');
        self::assertCount(2, $failed);
        self::assertSame(['visitor', '', 'user', 'admin'], [$failed[0]['actor_kind'], $failed[0]['actor'], $failed[0]['subject_type'], $failed[0]['subject_id']],
            'whoever typed it is not the account owner');
        self::assertSame('Failed sign-in as admin (wrong password)', $this->text($failed[0]));
        self::assertSame('visitor', $failed[1]['actor_kind']);
        self::assertSame('Failed sign-in as (18 characters, possibly a password) (no such account)', $this->text($failed[1]));
        self::assertSame('203.0.113.7', $failed[1]['ip']);
        self::assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM login_attempts WHERE username LIKE '%Secret%'"), 'not kept in the attempts table either');

        $this->login();
        self::assertSame('admin signed in', $this->text($this->entries('auth.signed_in')[0]));

        // Accounts: created, changed (role, extra permissions, a password set), deleted.
        $this->post('/users', ['username' => 'reader', 'display_name' => 'Ruth Reader', 'email' => 'r@example.com', 'role' => 'member',
            'status' => 'active', 'password' => 'reader password long']);
        $this->post('/users/reader', ['display_name' => 'Ruth Reader', 'email' => 'r@example.com', 'role' => 'member', 'status' => 'disabled',
            'permissions' => ['documents.build'], 'password' => 'a new password for ruth']);
        $this->post('/confirm', ['password' => self::PW, 'next' => '/users']); // deleting an account asks for this first
        $this->post('/users/reader/delete');
        self::assertSame('admin created the account reader (Member)', $this->text($this->entries('user.created')[0]));
        $updated = $this->text($this->entries('user.updated')[0]);
        self::assertStringContainsString('admin changed reader: status "Active" to "Disabled"', $updated);
        self::assertStringContainsString('extra permissions (added documents.build)', $updated);
        self::assertStringContainsString('password set by an administrator', $updated);
        self::assertSame('admin deleted the account reader', $this->text($this->entries('user.deleted')[0]));

        // Settings: which fields changed, never a password's value.
        $this->post('/settings/branding', ['name' => 'Kaunda Exams', 'accent' => 'teal']);
        $this->post('/settings/email', ['host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls', 'username' => 'mailer',
            'password' => 'smtp-secret-value', 'from_address' => 'exams@example.com', 'from_name' => 'Exams', 'site_url' => 'https://exams.example.com']);
        self::assertStringContainsString('admin changed the branding: name', $this->text($this->entries('settings.branding_changed')[0]));
        $email = $this->entries('settings.email_changed')[0];
        self::assertStringContainsString('password', $this->text($email));
        foreach ($this->entries() as $row) {
            self::assertStringNotContainsString('smtp-secret-value', (string) $row['details']);
            self::assertStringNotContainsString('a new password for ruth', (string) $row['details']);
            self::assertStringNotContainsString('MySecretPassword99', (string) $row['details'] . $row['actor']);
        }

        // Collecting runs in the background, so the system is credited with what it finished.
        $this->post('/providers/add', ['slug' => 'huawei']);
        $this->drain();
        $this->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'source_mode' => 'live', 'rps' => '50']);
        $this->drain();
        self::assertSame('admin added the provider huawei', $this->text($this->entries('provider.added')[0]));
        self::assertSame('system', $this->entries('provider.refreshed')[0]['actor_kind']);
        self::assertStringStartsWith('admin started collecting H12-811', $this->text($this->entries('collect.queued')[0]));
        $done = $this->entries('collect.completed');
        self::assertSame('system', $done[0]['actor_kind']);
        self::assertStringStartsWith('H12-811 (huawei) was collected', $this->text($done[0]));

        // The page, its filters, and the CSV of everything that matches.
        $page = $this->get('/activity')->body;
        self::assertStringContainsString('admin deleted the account reader', $page);
        self::assertStringContainsString('Failed sign-in as admin', $page);
        $mine = $this->get('/activity', ['group' => 'backups'])->body;
        self::assertStringContainsString('Nothing recorded for these filters', $mine);
        $system = $this->get('/activity', ['who' => 'system'])->body;
        self::assertStringContainsString('was collected', $system);
        self::assertStringNotContainsString('admin deleted the account', $system);
        $csv = $this->get('/activity.csv', ['from' => 'all']);
        self::assertSame('text/csv; charset=utf-8', $csv->headers['Content-Type']);
        $lines = array_map('str_getcsv', explode("\n", trim((string) file_get_contents((string) $csv->file))));
        self::assertSame(['Time (UTC)', 'Who', 'Account', 'What', 'Action', 'Address', 'Details'], $lines[0]);
        self::assertContains('admin deleted the account reader', array_column($lines, 3));

        // The dashboard sums failed sign-ins in one line and lists the rest.
        $dash = $this->get('/')->body;
        self::assertStringContainsString('2 failed sign-ins in the last 24 hours', $dash);
        self::assertStringContainsString('Recent activity', $dash);
    }

    public function testOnlyThoseAllowedSeeIt(): void
    {
        $this->s->users()->create('member', 'member password long', Access::MEMBER);
        $this->login('member', 'member password long');
        self::assertSame(403, $this->get('/activity')->status);
        self::assertSame(403, $this->get('/activity.csv')->status);
        self::assertStringNotContainsString('href="/activity"', $this->get('/library')->body);
        self::assertStringNotContainsString('Recent activity', $this->get('/')->body);

        $this->s->users()->update((int) $this->s->users()->findByUsername('member')['id'], ['permissions' => ['activity.view']]);
        self::assertSame(200, $this->get('/activity')->status, 'a member can be given it');
    }

    public function testAChangedEntryShows(): void
    {
        $this->login();
        $audit = $this->s->audit();
        foreach (range(1, 5) as $i) {
            $audit->recordBySystem('collect.completed', ['provider' => 'huawei', 'exam' => 'h12-80' . $i]);
        }
        $check = $audit->verify();
        self::assertTrue($check['ok']);
        self::assertSame(6, $check['checked'], 'the sign-in and the five');

        $this->flashes();
        $this->post('/activity/verify');
        self::assertStringContainsString('The history is intact: all', $this->flashes()[0]['message']);

        $id = (int) $this->app->db()->value("SELECT id FROM audit_log WHERE details LIKE '%h12-803%'");
        $this->app->db()->run("UPDATE audit_log SET details = REPLACE(details, 'h12-803', 'h12-999') WHERE id = ?", [$id]);
        self::assertSame(['ok' => false, 'broken_at' => $id], array_intersect_key($audit->verify(), ['ok' => 1, 'broken_at' => 1]));
        $this->post('/activity/verify');
        self::assertStringContainsString('The history was changed: entry #' . $id, $this->flashes()[0]['message']);

        // The command line says the same, with an exit code for scripts.
        $proc = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/verify-audit.php', '--json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        self::assertSame(1, proc_close($proc));
        self::assertSame($id, json_decode((string) $out, true)['broken_at']);

        // Removing a row shows too.
        $this->app->db()->run("UPDATE audit_log SET details = REPLACE(details, 'h12-999', 'h12-803') WHERE id = ?", [$id]);
        self::assertTrue($audit->verify()['ok']);
        $this->app->db()->run('DELETE FROM audit_log WHERE id = ?', [$id]);
        self::assertSame($id + 1, $audit->verify()['broken_at']);
    }

    public function testWritersRunningAtOnceKeepOneChain(): void
    {
        $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$a = (new App\Services\Services(App\Core\App::boot()))->audit();'
            . 'for ($i = 0; $i < 25; $i++) { $a->recordBySystem("collect.completed", ["exam" => "w" . getmypid() . "-" . $i]); }';
        $procs = [];
        foreach (range(1, 4) as $n) {
            $procs[] = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }
        foreach ($procs as $p) {
            self::assertSame(0, proc_close($p));
        }
        $check = $this->s->audit()->verify();
        self::assertSame(100, $check['checked']);
        self::assertTrue($check['ok'], 'no two entries chained off the same one');
    }

    /** Every action the code records reads as a sentence, not as its name. */
    public function testEveryRecordedActionHasASentence(): void
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app'));
        foreach ($files as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                preg_match_all("/(?:record|recordBySystem|audit)\\(\\s*'([a-z_]+\\.[a-z_]+)'/", (string) file_get_contents($f->getPathname()), $m);
                $found = array_merge($found, $m[1]);
            }
        }
        $found = array_values(array_unique($found));
        self::assertGreaterThan(30, count($found));
        foreach ($found as $action) {
            self::assertTrue(AuditText::known($action), $action . ' has no sentence in AuditText');
            $grouped = false;
            foreach (AuditLog::GROUPS as $g) {
                foreach ($g['prefixes'] as $p) {
                    $grouped = $grouped || str_starts_with($action, $p);
                }
            }
            self::assertTrue($grouped, $action . ' belongs to no filter group');
        }
    }
}
