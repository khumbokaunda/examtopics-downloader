<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Mailer;
use Tests\Support\ArrayTransport;
use Tests\Support\HttpTestCase;

/** The public page where someone without an account registers an institution. */
final class JoinTest extends HttpTestCase
{
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

    /** @return array<string,string> */
    private function form(array $extra = []): array
    {
        $profile = ['name' => 'Zomba Skills Centre', 'short_name' => '', 'type' => 'tevet', 'address' => 'Old Town Road', 'region' => 'Southern', 'district' => 'Zomba',
            'email' => 'office@zsc.example', 'phone' => '+265 1 525 000', 'website' => '', 'registration_info' => 'TEVETA 123', 'contact_name' => 'Grace Phiri',
            'contact_position' => 'Principal', 'contact_phone' => '+265 999 000 222', 'intended_use' => 'End of term tests for our ICT students.'];
        $out = ['display_name' => 'Grace Phiri', 'username' => 'grace', 'email' => 'grace@zsc.example', 'password' => 'a long enough password', 'repeat_password' => 'a long enough password'];
        foreach ($profile as $k => $v) {
            $out['inst_' . $k] = $v;
        }
        return $extra + $out;
    }

    private function code(): string
    {
        $m = end($this->mail->sent);
        self::assertSame(1, preg_match('/>(\d{6})</', $m->html, $match));
        return $match[1];
    }

    public function testRegisteringWithoutAnAccount(): void
    {
        $page = $this->get('/join');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Register your institution', $page->body);
        self::assertStringContainsString('href="/join"', $this->get('/login')->body, 'the sign-in page points to it');

        $bad = $this->post('/join', $this->form(['inst_type' => '', 'inst_phone' => 'call me']));
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Choose the kind of institution', $bad->body);
        self::assertStringContainsString('Grace Phiri', $bad->body, 'the form comes back filled in');
        self::assertNull($this->s->users()->findByUsername('grace'), 'no account is made while the institution has problems');

        $r = $this->post('/join', $this->form());
        self::assertSame('/verify', $r->headers['Location']);
        self::assertSame('unverified', $this->s->users()->findByUsername('grace')['status']);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM institutions'), 'nothing is registered before the address is confirmed');

        $this->post('/verify', ['code' => $this->code()]);
        $i = $this->app->db()->one('SELECT * FROM institutions');
        self::assertSame(['Zomba Skills Centre', 'draft'], [$i['name'], $i['status']], 'an application starts as a draft');
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM policy_acceptances WHERE policy = 'account_notice' AND context = 'account'"),
            'the account notice is recorded when the account is made');
        $grace = $this->s->users()->findByUsername('grace');
        self::assertSame('owner', $this->app->db()->value('SELECT role FROM institution_memberships WHERE user_id = ?', [(int) $grace['id']]));
        self::assertSame(200, $this->get('/institutions/' . $i['uuid'])->status, 'signed in, the owner lands on it');
        $admin = $this->s->users()->findByUsername('admin');
        self::assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND kind = 'institution'", [(int) $admin['id']]),
            'reviewers are told once it is sent, not before');

        self::assertSame('/institutions/register', $this->get('/join')->headers['Location'] ?? null, 'signed in, it goes to the ordinary form');
    }

    public function testClosedSignUpExplainsTheWayIn(): void
    {
        $this->s->registration()->setMode('closed');
        $page = $this->get('/join');
        self::assertStringContainsString('cannot register here', $page->body);
        self::assertSame(403, $this->post('/join', $this->form())->status);
        self::assertNull($this->s->users()->findByUsername('grace'));
    }
}
