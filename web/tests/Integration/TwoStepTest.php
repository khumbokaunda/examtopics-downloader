<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use App\Services\TwoStep;
use Tests\Support\HttpTestCase;

/** Two-step sign-in, recovery codes, turning it off, and confirming it is you before backups. */
final class TwoStepTest extends HttpTestCase
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

    private function id(string $username): int
    {
        return (int) $this->s->users()->findByUsername($username)['id'];
    }

    private function secret(string $username): string
    {
        return (string) $this->app->db()->value('SELECT secret FROM user_totp WHERE user_id = ?', [$this->id($username)]);
    }

    /** The code the app shows $offset steps from now. */
    private static function code(string $secret, int $offset = 0): string
    {
        return TwoStep::code($secret, intdiv(time(), TwoStep::PERIOD) + $offset);
    }

    /** Turn it on for someone directly; returns the recovery codes. @return list<string> */
    private function enable(string $username): array
    {
        $secret = $this->s->twoStep()->begin($this->id($username));
        return (array) $this->s->twoStep()->confirm($this->id($username), self::code($secret, -1));
    }

    private function passwordStep(string $username, string $password): \App\Core\Http\Response
    {
        $this->newSession();
        return $this->post('/login', ['username' => $username, 'password' => $password, 'next' => '/library']);
    }

    public function testCodesMatchTheStandard(): void
    {
        $secret = TwoStep::base32Encode('12345678901234567890');
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $t => $want) {
            self::assertSame($want, TwoStep::code($secret, intdiv($t, 30)), 'RFC 6238 at ' . $t);
        }
        self::assertSame(intdiv(59, 30), TwoStep::match($secret, '287 082', null, 59));
        self::assertSame(intdiv(59, 30) + 1, TwoStep::match($secret, TwoStep::code($secret, 2), null, 59), 'one step of leeway');
        self::assertNull(TwoStep::match($secret, TwoStep::code($secret, 3), null, 59));
        self::assertNull(TwoStep::match($secret, '287082', intdiv(59, 30), 59), 'not twice');
        self::assertStringStartsWith('otpauth://totp/Exam%20Hub:ruth?secret=ABC', TwoStep::uri('ABC', 'ruth', 'Exam: Hub'));
    }

    public function testSettingItUpThenSigningInWithAppAndRecoveryCodes(): void
    {
        $this->login('ruth', self::RUTH);
        self::assertStringContainsString('Set up two-step sign-in', $this->get('/account')->body);
        $this->post('/account/two-step/begin');
        $page = $this->get('/account')->body;
        $secret = $this->secret('ruth');
        self::assertStringContainsString('data-qr="otpauth://totp/', $page);
        self::assertStringContainsString(TwoStep::grouped($secret), $page);
        self::assertStringContainsString('qrcode-generator/qrcode.js', $page);
        self::assertFalse($this->s->twoStep()->enabled($this->id('ruth')), 'not until a code proves the app has it');

        $this->post('/account/two-step/confirm', ['code' => '000000']);
        self::assertStringContainsString('That code is not right', $this->flashes()[0]['message']);
        $step = intdiv(time(), TwoStep::PERIOD); // fixed, so a 30-second boundary mid-test changes nothing
        $r = $this->post('/account/two-step/confirm', ['code' => TwoStep::code($secret, $step)]);
        self::assertSame('/account/two-step/codes', $r->headers['Location']);
        self::assertTrue($this->s->twoStep()->enabled($this->id('ruth')));
        $codesPage = $this->get('/account/two-step/codes')->body;
        preg_match_all('#<li class="mono">([A-Z2-9]{5}-[A-Z2-9]{5})</li>#', $codesPage, $m);
        $codes = $m[1];
        self::assertCount(10, $codes);
        $txt = $this->get('/account/two-step/codes.txt');
        self::assertStringContainsString('attachment; filename="recovery-codes-ruth.txt"', $txt->headers['Content-Disposition']);
        self::assertStringContainsString($codes[0], $txt->body);
        $this->post('/account/two-step/codes/done');
        self::assertSame(303, $this->get('/account/two-step/codes')->status, 'shown once');
        self::assertSame(404, $this->get('/account/two-step/codes.txt')->status);
        self::assertSame(['Two-step sign-in is on'], array_column($this->app->db()->all("SELECT title FROM notifications WHERE kind = 'security' AND user_id = ?", [$this->id('ruth')]), 'title'));
        self::assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE details LIKE ?", ['%' . $codes[0] . '%']), 'codes never stored');

        // The password alone is not enough now.
        $r = $this->passwordStep('ruth', self::RUTH);
        self::assertSame('/login/two-step', $r->headers['Location']);
        self::assertSame(303, $this->get('/library')->status, 'not signed in yet');
        self::assertStringContainsString('Two-step sign-in', $this->get('/login/two-step')->body);
        $r = $this->post('/login/two-step', ['code' => TwoStep::code($secret, $step)]);
        self::assertStringContainsString('That code was already used', $r->body, 'the code that turned it on cannot sign in');
        self::assertStringContainsString('That code is not right', $this->post('/login/two-step', ['code' => '123456'])->body);
        $r = $this->post('/login/two-step', ['code' => TwoStep::code($secret, $step + 1)]);
        self::assertSame('/library', $r->headers['Location'], 'back to where they were going');
        self::assertSame(200, $this->get('/library')->status);

        // A recovery code works once, and the owner is told.
        $this->passwordStep('ruth', self::RUTH);
        self::assertSame('/library', $this->post('/login/two-step', ['code' => strtolower($codes[3])])->headers['Location']);
        self::assertStringContainsString('You signed in with a recovery code; 9 left', $this->flashes()[0]['message']);
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE title = 'A recovery code was used to sign in'"));
        $this->passwordStep('ruth', self::RUTH);
        self::assertStringContainsString('That code is not right', $this->post('/login/two-step', ['code' => $codes[3]])->body);
        self::assertStringContainsString('9 recovery codes left', $this->accountAs('ruth', $secret));
    }

    /** Sign in fully as someone with two-step on, and return their account page. */
    private function accountAs(string $username, string $secret): string
    {
        $this->passwordStep($username, $username === 'ruth' ? self::RUTH : self::PW);
        $step = intdiv(time(), TwoStep::PERIOD);
        $this->app->db()->run('UPDATE user_totp SET last_used_step = ? WHERE user_id = ?', [$step - 2, $this->id($username)]);
        $this->post('/login/two-step', ['code' => TwoStep::code($secret, $step - 1)]);
        return $this->get('/account')->body;
    }

    public function testWrongCodesAreLimitedAndAStolenPasswordIsReported(): void
    {
        $this->enable('ruth');
        $this->passwordStep('ruth', self::RUTH);
        foreach (range(1, 4) as $i) {
            self::assertSame(422, $this->post('/login/two-step', ['code' => '00000' . $i])->status);
        }
        $r = $this->post('/login/two-step', ['code' => '000005']);
        self::assertSame('/login', $r->headers['Location'], 'five wrong: the password again');
        self::assertStringContainsString('Five wrong codes', $this->flashes()[0]['message']);

        $this->passwordStep('ruth', self::RUTH);
        foreach (range(6, 9) as $i) {
            $this->post('/login/two-step', ['code' => '00000' . $i]);
        }
        $this->post('/login/two-step', ['code' => '000010']);
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE title = 'Someone may know your password' AND user_id = ?", [$this->id('ruth')]));
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE title LIKE 'Possible stolen password%' AND user_id = ?", [$this->id('admin')]));
        $this->passwordStep('ruth', self::RUTH);
        $secret = $this->secret('ruth');
        self::assertStringContainsString('Too many wrong codes for this account', $this->post('/login/two-step', ['code' => self::code($secret, 1)])->body,
            'even the right code, until the window passes');
        self::assertSame(10, (int) $this->app->db()->value("SELECT COUNT(*) FROM login_attempts WHERE reason = 'wrong two-step code'"));
        self::assertSame(10, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.two_step_failed'"));
        // A pending sign-in expires.
        $this->passwordStep('ruth', self::RUTH);
        $this->session->set('two_step', ['user_id' => $this->id('ruth'), 'at' => time() - 301, 'tries' => 0]);
        self::assertSame('/login', $this->post('/login/two-step', ['code' => '000000'])->headers['Location']);
    }

    public function testTurningItOffYourselfByAnAdministratorOrFromTheCommandLine(): void
    {
        $codes = $this->enable('ruth');
        $secret = $this->secret('ruth');

        // An administrator, for someone who lost their phone: confirm it is them first.
        $this->login('admin', self::PW);
        self::assertStringContainsString('Two-step sign-in is on', $this->get('/users/ruth')->body);
        $r = $this->post('/users/ruth/two-step/off');
        self::assertSame('/confirm?next=%2Fusers%2Fruth', $r->headers['Location']);
        self::assertTrue($this->s->twoStep()->enabled($this->id('ruth')));
        self::assertStringContainsString('Type your password', $this->get('/confirm', ['next' => '/users/ruth'])->body);
        self::assertSame(422, $this->post('/confirm', ['password' => 'wrong', 'next' => '/users/ruth'])->status);
        self::assertSame('/users/ruth', $this->post('/confirm', ['password' => self::PW, 'next' => '/users/ruth'])->headers['Location']);
        $this->post('/users/ruth/two-step/off');
        self::assertFalse($this->s->twoStep()->enabled($this->id('ruth')));
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM totp_recovery_codes WHERE user_id = ?', [$this->id('ruth')]));
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE title = 'Two-step sign-in is off' AND user_id = ?", [$this->id('ruth')]));
        self::assertStringContainsString('admin turned off two-step sign-in for ruth',
            \App\Services\AuditText::describe((array) $this->app->db()->one("SELECT * FROM audit_log WHERE action = 'security.two_step_disabled'"))['text']);
        self::assertSame(303, $this->post('/confirm', ['password' => self::PW, 'next' => '//evil.example'])->status);
        self::assertSame('/', $this->post('/confirm', ['password' => self::PW, 'next' => '//evil.example'])->headers['Location']);

        // Yourself: a code from the app, or a recovery code.
        $codes = $this->enable('ruth');
        $secret = $this->secret('ruth');
        $this->newSession();
        $this->passwordStep('ruth', self::RUTH);
        $this->post('/login/two-step', ['code' => $codes[0]]);
        $this->flashes();
        $this->post('/account/two-step/off', ['code' => '000000']);
        self::assertStringContainsString('That code is not right', $this->flashes()[0]['message']);
        $this->post('/account/two-step/off', ['code' => $codes[1]]);
        self::assertFalse($this->s->twoStep()->enabled($this->id('ruth')));

        // The command line, when the administrator is the one locked out.
        $this->enable('admin');
        $proc = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/unlock.php', 'two-step', 'admin'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        self::assertSame(0, proc_close($proc));
        self::assertStringContainsString('Two-step sign-in is off for admin', (string) $out);
        self::assertFalse($this->s->twoStep()->enabled($this->id('admin')));
    }

    public function testBackupsAndDeletingAccountsAskToConfirmItIsYou(): void
    {
        $this->login('admin', self::PW);
        $r = $this->post('/backups', ['documents' => '1']);
        self::assertSame('/confirm?next=%2Fbackups', $r->headers['Location']);
        self::assertSame([], $this->s->backup()->list(), 'nothing made');
        self::assertStringContainsString('Confirm it is you to make, download, upload, restore or delete backups', $this->get('/backups')->body);
        $json = $this->kernel()->handle(new \App\Core\Http\Request('POST', '/backups/uploads', [], ['_csrf' => $this->session->csrfToken(), 'name' => 'b.zip', 'size' => '10'],
            ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT' => 'application/json']));
        self::assertSame(403, $json->status);
        self::assertStringContainsString('Confirm it is you first', $json->body);
        self::assertSame('/confirm?next=%2Fusers%2Fruth', $this->post('/users/ruth/delete')->headers['Location']);
        self::assertNotNull($this->s->users()->findByUsername('ruth'));

        $this->post('/confirm', ['password' => self::PW, 'next' => '/backups']);
        self::assertStringContainsString('Confirmed for 10 minutes more', $this->get('/backups')->body);
        $this->flashes();
        $this->post('/backups', ['documents' => '1']);
        self::assertSame('success', $this->flashes()[0]['type']);
        $this->post('/users/ruth/delete');
        self::assertNull($this->s->users()->findByUsername('ruth'));

        // It runs out.
        $this->session->set('_confirmed', ['user' => $this->id('admin'), 'until' => time() - 1]);
        self::assertSame('/confirm?next=%2Fbackups', $this->post('/backups', ['documents' => '1'])->headers['Location']);

        // With two-step sign-in on, the code from the app is what confirms it, not the password.
        $this->enable('admin');
        $secret = $this->secret('admin');
        $page = $this->get('/confirm', ['next' => '/backups'])->body;
        self::assertStringContainsString('Type the code from your authenticator app', $page);
        self::assertStringNotContainsString('name="password"', $page);
        self::assertSame(422, $this->post('/confirm', ['password' => self::PW, 'next' => '/backups'])->status);
        self::assertSame('/backups', $this->post('/confirm', ['code' => self::code($secret, 1), 'next' => '/backups'])->headers['Location']);
        self::assertSame(2, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'security.confirmed'"), 'the password, then the code');
    }
}
