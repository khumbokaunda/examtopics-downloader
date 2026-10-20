<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\ClientIp;
use App\Core\Config;
use App\Services\Access;
use Tests\Support\HttpTestCase;

/** Accounts lock after wrong passwords, guessing networks are refused, and the review page says whether anything got in. */
final class SignInProtectionTest extends HttpTestCase
{
    private const PW = 'correct horse battery';
    private const RUTH = 'ruth password long';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->s->users()->create('admin', self::PW, Access::ADMIN, ['email' => 'admin@example.com', 'email_verified' => true]);
        $this->s->users()->create('ruth', self::RUTH, Access::MEMBER, ['email' => 'ruth@example.com', 'email_verified' => true]);
    }

    /** A sign-in from this test's current address, in a fresh browser. Returns the error shown, or null when it got in. */
    private function signIn(string $username, string $password): ?string
    {
        $this->newSession();
        $r = $this->post('/login', ['username' => $username, 'password' => $password]);
        if ($r->status === 303) {
            return null;
        }
        preg_match('#<div class="alert alert-danger[^"]*"[^>]*>.*?<div class="alert-body">(.*?)</div>#s', $r->body, $m);
        return html_entity_decode(trim(strip_tags($m[1] ?? 'refused')));
    }

    private function asAdmin(): void
    {
        $this->ip = '192.0.2.1';
        $this->newSession();
        $this->login('admin', self::PW);
    }

    public function testTheVisitorsAddressIsOnlyTakenFromProxiesThatAreTrusted(): void
    {
        $cfg = static fn(array $security): Config => Config::fromArray(['security' => $security]);
        $forged = ['REMOTE_ADDR' => '93.184.216.34', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8'];
        self::assertSame('93.184.216.34', ClientIp::of($forged, $cfg([])), 'headers from an unknown peer are ignored');

        $cloudflare = ['REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '41.70.1.2', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'];
        self::assertSame('41.70.1.2', ClientIp::of($cloudflare, $cfg([])));
        self::assertSame('104.16.0.1', ClientIp::of($cloudflare, $cfg(['trust_cloudflare' => false])));
        self::assertTrue(ClientIp::isCloudflare('2606:4700::1'));

        $proxied = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 41.70.1.2, 10.0.0.9'];
        self::assertSame('41.70.1.2', ClientIp::of($proxied, $cfg(['trusted_proxies' => ['127.0.0.1', '10.0.0.0/8']])),
            'read from the right; the left was written by the client');
        self::assertSame('127.0.0.1', ClientIp::of($proxied, $cfg([])));
        self::assertSame('127.0.0.1', ClientIp::of(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip'], $cfg(['trusted_proxies' => ['127.0.0.1']])));
        self::assertSame('10.0.0.9', ClientIp::of($proxied, $cfg(['trust_forwarded_for' => true])), 'the older switch trusts one hop');
        self::assertFalse(ClientIp::isPublic('10.0.0.5'));
    }

    public function testAnAccountLocksAfterWrongPasswordsAndCanBeUnlocked(): void
    {
        $this->ip = '93.184.216.34';
        // A right password in between starts the count again.
        foreach (range(1, 3) as $i) {
            self::assertSame('Incorrect username or password.', $this->signIn('ruth', 'wrong ' . $i));
        }
        self::assertNull($this->signIn('ruth', self::RUTH));
        foreach (range(1, 4) as $i) {
            self::assertSame('Incorrect username or password.', $this->signIn('ruth', 'wrong ' . $i));
        }
        self::assertStringContainsString('the account is now locked for 15 minutes', (string) $this->signIn('ruth', 'wrong 5'));
        self::assertStringContainsString('This account is locked after too many wrong passwords. Try again in 15 minutes',
            (string) $this->signIn('ruth', self::RUTH), 'even the right password');

        // The owner and the reviewers are told; the history says so.
        $ruth = (int) $this->s->users()->findByUsername('ruth')['id'];
        self::assertSame(['Your account was locked'], array_column($this->app->db()->all("SELECT title FROM notifications WHERE user_id = ? AND kind = 'security'", [$ruth]), 'title'));
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.username = 'admin' AND n.kind = 'security'"));
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM mail_queue WHERE to_email = 'ruth@example.com'"), 'by email too');
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.account_locked' AND subject_id = 'ruth'"));

        // The review page shows it, and unlocks it.
        $this->asAdmin();
        $page = $this->get('/security/sign-ins')->body;
        self::assertStringContainsString('Locked until', $page);
        self::assertStringContainsString('Locked', $this->get('/users')->body);
        self::assertStringContainsString('Unlock now', $this->get('/users/ruth')->body);
        $r = $this->post('/security/sign-ins/unlock', ['username' => 'ruth', 'return' => 'user']);
        self::assertSame('/users/ruth', $r->headers['Location']);
        $this->ip = '93.184.216.34';
        self::assertNull($this->signIn('ruth', self::RUTH));
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'security.account_unlocked'"));

        // Their account page lists their own sign-ins and the wrong passwords.
        $account = $this->get('/account')->body;
        self::assertStringContainsString('Recent sign-ins', $account);
        self::assertStringContainsString('Refused: account locked', $account);
        self::assertStringContainsString('Wrong password', $account);
    }

    public function testANetworkGuessingNamesIsRefusedButSharedAndPrivateOnesNeverAre(): void
    {
        $this->ip = '93.184.216.34';
        foreach (range(1, 20) as $i) {
            self::assertSame('Incorrect username or password.', $this->signIn('guess' . $i, 'x'));
        }
        self::assertStringContainsString('Too many failed sign-ins came from your network. Try again in 30 minutes', (string) $this->signIn('ruth', self::RUTH));
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'security.network_blocked'"), 'recorded once');
        self::assertStringContainsString('Too many', (string) $this->signIn('guess21', 'x'));
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'security.network_blocked'"));
        $this->ip = '41.70.1.2';
        self::assertNull($this->signIn('ruth', self::RUTH), 'another network is unaffected');

        // Unblocked from the page: allowed again, and the old failures do not block it again at once.
        $this->asAdmin();
        self::assertStringContainsString('Blocked until', $this->get('/security/sign-ins')->body);
        $this->post('/security/sign-ins/unblock', ['ip' => '93.184.216.34']);
        $this->ip = '93.184.216.34';
        self::assertSame('Incorrect username or password.', $this->signIn('guess22', 'x'));
        self::assertNull($this->signIn('ruth', self::RUTH));

        // An address three people sign in from (an office) is never blocked, nor a private one.
        $this->ip = '41.70.9.9';
        $this->s->users()->create('sam', 'sam password long', Access::MEMBER);
        foreach ([['admin', self::PW], ['ruth', self::RUTH], ['sam', 'sam password long']] as [$u, $p]) {
            self::assertNull($this->signIn($u, $p));
        }
        foreach (range(1, 25) as $i) {
            $this->signIn('office' . $i, 'x');
        }
        self::assertNull($this->signIn('ruth', self::RUTH), 'shared: never blocked');
        $this->ip = '10.0.0.5';
        foreach (range(1, 25) as $i) {
            $this->signIn('inside' . $i, 'x');
        }
        self::assertNull($this->signIn('ruth', self::RUTH), 'private: never blocked');
        $this->asAdmin();
        $page = $this->get('/security/sign-ins')->body;
        self::assertStringContainsString('Shared, never blocked', $page);
        self::assertStringContainsString('Private address, never blocked', $page);
    }

    public function testThePageSaysWhetherAnyAttemptGotIn(): void
    {
        // Three wrong passwords for ruth from one network, then the right one: flagged.
        $this->ip = '93.184.216.34';
        foreach (range(1, 3) as $i) {
            $this->signIn('ruth', 'wrong ' . $i);
        }
        $this->signIn('MyLongPasswordTypedHere', 'x');
        self::assertNull($this->signIn('ruth', self::RUTH));
        // A sign-in with no guessing before it: not flagged.
        $this->ip = '41.70.1.2';
        self::assertNull($this->signIn('admin', self::PW));

        $this->asAdmin();
        $page = $this->get('/security/sign-ins', ['period' => '24h'])->body;
        self::assertStringContainsString('ruth signed in from <span class="mono">93.184.216.34</span>', $page);
        self::assertStringContainsString('This network had typed 3 wrong passwords for it first', $page);
        self::assertStringNotContainsString('signed in from <span class="mono">41.70.1.2</span>', $page);
        self::assertStringContainsString('(23 characters, possibly a password)', $page, 'a name that may be a password is shown as its length');
        self::assertStringNotContainsString('MyLongPasswordTypedHere', $page);

        $id = (int) $this->app->db()->value("SELECT id FROM login_attempts WHERE username = 'ruth' AND succeeded = 1 AND ip = '93.184.216.34'");
        $this->post('/security/sign-ins/flags/' . $id . '/clear', ['period' => '24h']);
        $page = $this->get('/security/sign-ins', ['period' => '24h'])->body;
        self::assertStringContainsString('Flag again', $page);
        self::assertStringNotContainsString('It was them', $page);
        $this->post('/security/sign-ins/flags/' . $id . '/restore');
        self::assertStringContainsString('It was them', $this->get('/security/sign-ins')->body);
        $this->post('/security/sign-ins/flags/clear-all');
        self::assertStringContainsString('Flag again', $this->get('/security/sign-ins')->body);
        self::assertSame(404, $this->post('/security/sign-ins/flags/999999/clear')->status);
        self::assertSame(2, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'security.flag_cleared'"));

        // Only those allowed.
        $this->newSession();
        $this->login('ruth', self::RUTH);
        self::assertSame(403, $this->get('/security/sign-ins')->status);
        self::assertSame(403, $this->post('/security/sign-ins/unlock', ['username' => 'admin'])->status);
    }

    public function testTheCommandLineUnlocks(): void
    {
        $this->ip = '93.184.216.34';
        foreach (range(1, 5) as $i) {
            $this->signIn('admin', 'wrong ' . $i);
        }
        $run = function (array $args): array {
            $proc = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/unlock.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            return [proc_close($proc), $out];
        };
        self::assertStringContainsString('Locked:  admin', $run(['list'])[1]);
        self::assertSame([0, "Unlocked admin.\n"], $run(['account', 'admin']));
        self::assertNull($this->signIn('admin', self::PW));
        self::assertSame(64, $run(['network', 'nope'])[0]);
    }
}
