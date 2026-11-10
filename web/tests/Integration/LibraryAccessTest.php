<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use App\Services\Entitlements;
use App\Services\Mailer;
use Tests\Support\ArrayTransport;
use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/**
 * The library is something an account has or does not have. Accounts that arrive through an
 * institution start without it; home opens for everyone and shows what the account has; the
 * institution pages are grouped into sections.
 */
final class LibraryAccessTest extends HttpTestCase
{
    use InstitutionFixtures;

    private ArrayTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->people();
    }

    /** Email that works, so people can sign up for themselves (invitations then go by email). */
    private function withEmail(): void
    {
        $this->mail = new ArrayTransport();
        $this->app->set(Mailer::class, new Mailer($this->app, $this->s->branding(), $this->mail));
        $this->s->mailer()->save(['host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls', 'username' => 'sender@example.com',
            'password' => 'x', 'site_url' => 'https://exams.example.com']);
    }

    /** Sign up in this browser session and confirm the address. @return array<string,mixed> the account */
    private function signUp(string $username): array
    {
        $r = $this->post('/register', ['display_name' => ucfirst($username), 'username' => $username, 'email' => $username . '@new.example',
            'password' => 'a long enough password', 'repeat_password' => 'a long enough password']);
        self::assertSame('/verify', $r->headers['Location'] ?? null, $r->body);
        self::assertSame(1, preg_match('/>(\d{6})</', end($this->mail->sent)->html, $m));
        $this->post('/verify', ['code' => $m[1]]);
        return (array) $this->s->users()->findByUsername($username);
    }

    private function library(string $username): bool
    {
        return Entitlements::has($this->s->users()->findByUsername($username), Entitlements::LIBRARY);
    }

    public function testWhoStartsWithTheLibrary(): void
    {
        $uuid = $this->institution('olive', 'Lilongwe ICT Academy');
        $this->withEmail();
        // Signing up for yourself: the library comes with the account.
        $this->newSession();
        $this->signUp('solo');
        self::assertTrue($this->library('solo'));
        self::assertTrue(Access::can($this->s->users()->findByUsername('solo'), 'library.view'));

        // Following an invitation: the institution's doing, so no library.
        $this->as('olive');
        $this->post('/institutions/' . $uuid . '/invitations', ['email' => 'guest@new.example', 'role' => 'student']);
        $queued = (string) $this->app->db()->value("SELECT html FROM mail_queue WHERE to_email = 'guest@new.example' ORDER BY id DESC LIMIT 1");
        self::assertSame(1, preg_match('#/invitations/([a-f0-9]{48})#', $queued, $m), 'the invitation is emailed');
        $this->newSession();
        self::assertSame(200, $this->get('/invitations/' . $m[1])->status);
        $this->signUp('viainvite');
        self::assertFalse($this->library('viainvite'));
        self::assertFalse(Access::can($this->s->users()->findByUsername('viainvite'), 'library.view'));
        self::assertFalse(Access::can($this->s->users()->findByUsername('viainvite'), 'exams.request'));

        // An invitation waiting for the address (a student import sends these), opened or not.
        $this->newSession();
        $this->signUp('guest');
        self::assertFalse($this->library('guest'), 'an open invitation is addressed to guest@new.example');

        // Sent to sign in by an enrolment code.
        $this->newSession();
        self::assertSame(200, $this->get('/login', ['next' => '/enrol'])->status);
        $this->signUp('viacode');
        self::assertFalse($this->library('viacode'));

        // The mark does not outlive the sign-up it was for.
        $this->newSession();
        $this->signUp('later');
        self::assertTrue($this->library('later'));
    }

    public function testRegisteringAnInstitutionGivesNoLibrary(): void
    {
        $this->withEmail();
        $this->newSession();
        $form = ['display_name' => 'Grace Phiri', 'username' => 'grace', 'email' => 'grace@zsc.example', 'password' => 'a long enough password',
            'repeat_password' => 'a long enough password'];
        foreach ($this->profile('Zomba Skills Centre') as $k => $v) {
            $form['inst_' . $k] = $v;
        }
        self::assertSame('/verify', $this->post('/join', $form)->headers['Location'] ?? null);
        self::assertFalse($this->library('grace'));
    }

    public function testHomeShowsWhatTheAccountHas(): void
    {
        $uuid = $this->institution('olive', 'Lilongwe ICT Academy');
        $db = $this->app->db();
        $db->run("UPDATE users SET library_access = 0 WHERE username IN ('carol', 'dan')");

        // No library, no institution: how to get into one, and nothing of the library.
        $this->as('dan');
        $home = $this->get('/');
        self::assertSame(200, $home->status, 'home opens for every signed-in person');
        self::assertStringContainsString('You are not in an institution yet', $home->body);
        self::assertStringContainsString('href="/enrol"', $home->body);
        foreach (['href="/library"', 'href="/search"', 'href="/progress"', 'Your streak', 'New in the library', 'Go to a page or exam'] as $library) {
            self::assertStringNotContainsString($library, $home->body, 'no library for this account: ' . $library);
        }
        self::assertStringContainsString('Go to a page', $home->body);
        self::assertSame(403, $this->get('/library')->status);
        self::assertSame(403, $this->get('/api/palette')->status);
        self::assertSame(200, $this->get('/certificates')->status, 'what a person holds stays theirs');
        self::assertStringContainsString('Certificates your institution issues', $this->get('/certificates')->body);

        // An institution's student: their institution and what is set for them, still no library.
        $this->join($uuid, 'olive', 'carol', 'student');
        $home = $this->get('/')->body;
        self::assertStringContainsString('Your institutions', $home);
        self::assertStringContainsString('Lilongwe ICT Academy', $home);
        self::assertStringContainsString('Set for you', $home);
        self::assertStringNotContainsString('href="/library"', $home, 'an institution-only student never sees the library');
        self::assertStringNotContainsString('Your study', $home);
        self::assertStringNotContainsString('You are not in an institution yet', $home);

        // The library only: no institution sections at all.
        $this->as('bob');
        $home = $this->get('/')->body;
        self::assertStringContainsString('href="/library"', $home);
        self::assertStringNotContainsString('Your institutions', $home, 'a library-only member never sees empty institution sections');
        self::assertStringNotContainsString('Set for you', $home);

        // Both: the institution first, then the study section under its own heading; staff see what waits on them.
        $this->as('olive');
        $home = $this->get('/')->body;
        self::assertStringContainsString('Your institutions', $home);
        self::assertStringContainsString('Your study', $home);
        self::assertMatchesRegularExpression('/\d setup steps? left/', $home, 'the owner is told what is left to set up');
        self::assertStringNotContainsString('Set for you', $home, 'staff with nothing assigned are not shown an empty list');
    }

    public function testAnAdministratorSwitchesTheLibraryForAnAccount(): void
    {
        $bob = (array) $this->s->users()->findByUsername('bob');
        $this->as('admin');
        $page = $this->get('/users/' . $bob['uuid'])->body;
        self::assertStringContainsString('name="library" value="1" checked', $page);
        $fields = ['display_name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'member', 'status' => 'active'];

        $this->post('/users/' . $bob['uuid'], $fields);
        self::assertTrue($this->library('bob'), 'a form without the box changes nothing');
        $this->post('/users/' . $bob['uuid'], $fields + ['library_set' => '1']);
        self::assertFalse($this->library('bob'));
        self::assertStringContainsString('library on to off', (string) $this->get('/activity')->body);
        self::assertStringNotContainsString('name="library" value="1" checked', $this->get('/users/' . $bob['uuid'])->body);

        $this->as('bob');
        self::assertSame(403, $this->get('/library')->status);
        self::assertSame(200, $this->get('/')->status);

        $this->as('admin');
        $this->post('/users/' . $bob['uuid'], $fields + ['library_set' => '1', 'library' => '1']);
        self::assertTrue($this->library('bob'));
        self::assertTrue(Entitlements::has($this->s->users()->findByUsername('admin'), Entitlements::LIBRARY), 'administrators always have it');
    }

    public function testInstitutionPagesAreGroupedAndCanBeSwitched(): void
    {
        $uuid = $this->institution('olive', 'Lilongwe ICT Academy');
        $draft = $this->institution('olive', 'Mzuzu Tech Hub', false);
        $this->join($uuid, 'olive', 'carol', 'student');

        $this->as('olive');
        $page = $this->get('/institutions/' . $uuid . '/classes')->body;
        self::assertStringContainsString('aria-label="Institution sections"', $page);
        foreach (['Overview', 'Teaching', 'People', 'Academic', 'Credentials', 'Administration'] as $section) {
            self::assertMatchesRegularExpression('#<a class="[^"]*" href="[^"]+"[^>]*><i [^>]+></i>' . $section . '</a>#', $page, $section);
        }
        self::assertStringContainsString('aria-label="Teaching pages"', $page, 'the open section lists its pages');
        self::assertStringContainsString('>Question banks</a>', $page);
        self::assertStringNotContainsString('>Application</a>', $page, 'only the open section\'s pages are listed');
        $admin = $this->get('/institutions/' . $uuid . '/profile')->body;
        self::assertStringContainsString('aria-label="Administration pages"', $admin);
        self::assertStringContainsString('>Application</a>', $admin, 'the application sits under Administration once approved');
        self::assertStringContainsString('Switch institution', $page);
        self::assertStringContainsString('href="/institutions/' . $draft . '"', $page, 'the other institution is one click away');

        // Not approved yet: its three pages, flat.
        $flat = $this->get('/institutions/' . $draft)->body;
        self::assertStringContainsString('aria-label="Institution pages"', $flat);
        self::assertStringContainsString('>Application</a>', $flat);
        self::assertStringNotContainsString('aria-label="Institution sections"', $flat);

        // A student sees only what they may open, and no switcher with one institution.
        $this->as('carol');
        $page = $this->get('/institutions/' . $uuid)->body;
        foreach (['Teaching', 'Administration', 'Credentials', 'Switch institution'] as $hidden) {
            self::assertStringNotContainsString('</i>' . $hidden . '</a>', $page, $hidden);
        }
        self::assertStringNotContainsString('Switch institution', $page);
    }
}
