<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Mailer;
use Tests\Support\ArrayTransport;
use Tests\Support\HttpTestCase;

/** Creating an account with an emailed code, approval, and resetting a password. */
final class SignupTest extends HttpTestCase
{
    private const PW = 'a long enough password';
    private ArrayTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->mail = new ArrayTransport();
        $this->app->set(Mailer::class, new Mailer($this->app, $this->s->branding(), $this->mail));
        $this->s->mailer()->save(['host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls', 'username' => 'sender@example.com',
            'password' => 'x', 'site_url' => 'https://exams.example.com']);
        $this->s->users()->create('admin', 'correct horse battery', 'admin', ['email' => 'owner@example.com', 'email_verified' => true]);
        $this->newSession();
    }

    /** The code in the newest email. */
    private function lastCode(): string
    {
        $m = end($this->mail->sent);
        self::assertNotFalse($m, 'an email was sent');
        self::assertSame(1, preg_match('/>(\d{6})</', $m->html, $match), 'the email carries a code');
        return $match[1];
    }

    private function signUp(string $username = 'chikondi', string $email = 'chikondi@example.com'): void
    {
        $r = $this->post('/register', ['display_name' => 'Chikondi Banda', 'username' => $username, 'email' => $email,
            'password' => self::PW, 'repeat_password' => self::PW]);
        self::assertSame(303, $r->status, $r->body);
        self::assertSame('/verify', $r->headers['Location']);
    }

    public function testSignUpVerifyAndSignIn(): void
    {
        self::assertStringContainsString('Create an account', $this->get('/login')->body);
        self::assertSame(200, $this->get('/register')->status);
        $this->signUp();
        $u = $this->s->users()->findByUsername('chikondi');
        self::assertSame(['member', 'unverified', null], [$u['role'], $u['status'], $u['email_verified_at']]);
        self::assertSame('chikondi@example.com', $this->mail->sent[0]->to);
        self::assertSame('Your verification code', $this->mail->sent[0]->subject);
        $code = $this->lastCode();
        $stored = $this->app->db()->one('SELECT * FROM account_codes');
        self::assertSame(hash('sha256', 'verify:' . $u['id'] . ':' . $code), $stored['code_hash'], 'only a hash is stored');

        $wrong = $code === '000000' ? '111111' : '000000';
        self::assertStringContainsString('That code is not right', $this->post('/verify', ['code' => $wrong])->body);
        self::assertSame(303, $this->get('/library')->status, 'not signed in yet');

        $r = $this->post('/verify', ['code' => substr($code, 0, 3) . ' ' . substr($code, 3)]);
        self::assertSame('/', $r->headers['Location']);
        self::assertSame(200, $this->get('/library')->status, 'signed in straight after verifying');
        $u = $this->s->users()->findByUsername('chikondi');
        self::assertSame('active', $u['status']);
        self::assertNotNull($u['email_verified_at']);

        // The administrator hears about it in the inbox and by email.
        $n = $this->app->db()->one("SELECT * FROM notifications WHERE kind = 'account'");
        self::assertStringContainsString('Chikondi Banda', $n['title']);
        self::assertSame('/users/chikondi', $n['link']);
        self::assertSame('owner@example.com', $this->app->db()->value('SELECT to_email FROM mail_queue'));

        // A code works once.
        $this->newSession();
        $this->session->set('pending_user_id', (int) $u['id']);
        self::assertSame(303, $this->post('/verify', ['code' => $code])->status, 'an active account has nothing to verify');
    }

    public function testSignUpChecksAndLimits(): void
    {
        $r = $this->post('/register', ['display_name' => 'X', 'username' => 'admin', 'email' => 'x@example.com', 'password' => self::PW, 'repeat_password' => self::PW]);
        self::assertStringContainsString('That username is taken', $r->body);
        $r = $this->post('/register', ['display_name' => 'X', 'username' => 'newone', 'email' => 'Owner@Example.com', 'password' => self::PW, 'repeat_password' => self::PW]);
        self::assertStringContainsString('already uses that email', $r->body);
        $r = $this->post('/register', ['display_name' => 'X', 'username' => 'newone', 'email' => 'n@example.com', 'password' => 'short', 'repeat_password' => 'short']);
        self::assertStringContainsString('at least 12', $r->body);

        // Five wrong codes and the code stops working, even the right one.
        $this->signUp();
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->post('/verify', ['code' => $wrong]);
        }
        self::assertStringContainsString('can no longer be used', $this->post('/verify', ['code' => $code])->body);

        // A new code cannot be sent within a minute of the last.
        $this->post('/verify/resend');
        self::assertStringContainsString('less than a minute ago', $this->flashes()[0]['message']);

        // Closed: no sign-up page or link.
        $this->s->registration()->setMode('closed');
        self::assertStringNotContainsString('Create an account', $this->get('/login')->body);
        self::assertSame(403, $this->post('/register', ['display_name' => 'Y', 'username' => 'yes', 'email' => 'y@example.com', 'password' => self::PW, 'repeat_password' => self::PW])->status);
    }

    public function testApprovalMode(): void
    {
        $this->s->registration()->setMode('approval');
        $this->signUp();
        $r = $this->post('/verify', ['code' => $this->lastCode()]);
        self::assertSame('/login', $r->headers['Location']);
        self::assertSame('pending', $this->s->users()->findByUsername('chikondi')['status']);
        self::assertStringContainsString('waiting for an administrator', $this->post('/login', ['username' => 'chikondi', 'password' => self::PW])->body);
        self::assertStringContainsString('waiting for approval', (string) $this->app->db()->value("SELECT title FROM notifications WHERE kind = 'account'"));

        $this->login();
        self::assertStringContainsString('Approve', $this->get('/users')->body);
        $this->post('/users/chikondi/approve');
        self::assertSame('active', $this->s->users()->findByUsername('chikondi')['status']);
        self::assertSame('Your account is ready', end($this->mail->sent)->subject);
        self::assertStringContainsString('https://exams.example.com/login', end($this->mail->sent)->html);
        self::assertNotNull($this->app->db()->value("SELECT read_at FROM notifications WHERE kind = 'account'"), 'the approval notification is resolved');
    }

    public function testAnUnverifiedSignInAsksForTheCode(): void
    {
        $this->signUp();
        $this->newSession();
        $r = $this->post('/login', ['username' => 'chikondi@example.com', 'password' => self::PW]);
        self::assertSame('/verify', $r->headers['Location']);
        self::assertStringContainsString('chikondi@example.com', $this->get('/verify')->body);
        self::assertStringContainsString('Incorrect username or password', $this->post('/login', ['username' => 'chikondi', 'password' => 'wrong password!'])->body);
    }

    public function testForgottenPassword(): void
    {
        $r = $this->post('/forgot', ['email' => 'nobody@example.com']);
        self::assertSame('/reset', $r->headers['Location']);
        $unknown = $this->flashes()[0]['message'];
        self::assertSame([], $this->mail->sent);
        self::assertStringContainsString('That code is not right', $this->post('/reset', ['code' => '123456', 'password' => self::PW, 'repeat_password' => self::PW])->body);

        $this->post('/forgot', ['email' => 'OWNER@example.com']);
        self::assertSame($unknown, $this->flashes()[0]['message'], 'the same answer whether or not the address exists');
        self::assertSame('Your password reset code', end($this->mail->sent)->subject);
        $code = $this->lastCode();

        self::assertStringContainsString('do not match', $this->post('/reset', ['code' => $code, 'password' => self::PW, 'repeat_password' => 'something else!!'])->body);
        $this->app->db()->run("UPDATE account_codes SET expires_at = '2000-01-01 00:00:00'");
        self::assertStringContainsString('expired', $this->post('/reset', ['code' => $code, 'password' => self::PW, 'repeat_password' => self::PW])->body);
        $this->app->db()->run('UPDATE account_codes SET expires_at = ?', [gmdate('Y-m-d H:i:s', time() + 600)]);
        $r = $this->post('/reset', ['code' => $code, 'password' => self::PW, 'repeat_password' => self::PW]);
        self::assertSame('/login', $r->headers['Location']);
        self::assertSame(303, $this->post('/login', ['username' => 'admin', 'password' => self::PW])->status, 'the new password works');

        $this->newSession();
        $this->session->set('reset_email', 'owner@example.com');
        self::assertStringContainsString('can no longer be used', $this->post('/reset', ['code' => $code, 'password' => self::PW, 'repeat_password' => self::PW])->body, 'a code works once');

        for ($i = 0; $i < 4; $i++) {
            $this->post('/forgot', ['email' => 'x' . $i . '@example.com']);
        }
        self::assertStringContainsString('Too many reset requests', $this->post('/forgot', ['email' => 'y@example.com'])->body);
    }

    public function testConfirmingAChangedEmailAddress(): void
    {
        $this->login();
        $this->post('/account/profile', ['display_name' => 'Owner', 'email' => 'new@example.com']);
        self::assertNull($this->s->users()->findByUsername('admin')['email_verified_at'], 'a changed address needs confirming');
        self::assertSame('new@example.com', end($this->mail->sent)->to);
        self::assertStringContainsString('Confirm your email address', $this->get('/account')->body);
        $this->post('/account/email/verify', ['code' => $this->lastCode()]);
        self::assertNotNull($this->s->users()->findByUsername('admin')['email_verified_at']);
    }
}
