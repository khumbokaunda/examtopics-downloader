<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AuditText;
use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** Plans and their limits, and the public pages (home, pricing, contact). */
final class PlansAndSiteTest extends HttpTestCase
{
    use InstitutionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->people('fay', 'gus');
    }

    private function db(string $sql, array $args = []): mixed
    {
        return $this->app->db()->value($sql, $args);
    }

    private function lastFlash(): string
    {
        $f = $this->flashes();
        return (string) (end($f)['message'] ?? '');
    }

    public function testThePublicPagesAreOffUntilTurnedOn(): void
    {
        $this->newSession();
        $r = $this->get('/');
        self::assertSame(303, $r->status);
        self::assertStringStartsWith('/login', $r->headers['Location']);
        self::assertSame(404, $this->get('/pricing')->status);
        self::assertSame(404, $this->get('/contact')->status);

        $this->as('admin');
        $this->post('/platform/plans', ['name' => 'Starter', 'price' => 'MWK 40,000 a month', 'max_students' => '50', 'max_staff' => '5', 'public' => '1']);
        $this->post('/platform/plans', ['name' => 'Hidden deal', 'price' => 'MWK 1', 'public' => '0']);
        $this->post('/settings/site', ['on' => '1', 'headline' => 'Tests that run on exam day', 'intro' => 'For training providers.', 'contact_email' => 'hello@example.org']);
        self::assertStringContainsString('noindex', $this->get('/')->body, 'signed in: the dashboard, not listed');

        $this->newSession();
        $home = $this->get('/');
        self::assertSame(200, $home->status);
        self::assertStringContainsString('Tests that run on exam day', $home->body);
        self::assertStringContainsString('content="index, follow"', $home->body);
        self::assertArrayHasKey('Content-Security-Policy', $home->headers, 'the same headers as every page');
        $pricing = $this->get('/pricing')->body;
        self::assertStringContainsString('MWK 40,000 a month', $pricing);
        self::assertStringContainsString('Up to 50 students', $pricing);
        self::assertStringNotContainsString('Hidden deal', $pricing);
        self::assertStringContainsString('noindex', $this->get('/login')->body, 'other pages stay unlisted');
        self::assertStringStartsWith('/login', $this->get('/library')->headers['Location']);

        // Amounts, a yearly price with its saving, the plan to recommend and features that add up.
        $this->as('admin');
        $this->post('/platform/plans', ['name' => 'Basic', 'amount_monthly' => '20,000', 'amount_yearly' => '192,000', 'features' => "Question banks\nTimed tests", 'public' => '1']);
        $this->post('/platform/plans', ['name' => 'Standard', 'amount_monthly' => '40000', 'features' => "Invigilation\n\nGradebook", 'public' => '1', 'featured' => '1', 'includes_previous' => '1']);
        $this->post('/platform/plans', ['name' => 'Bad', 'amount_monthly' => 'lots']);
        self::assertStringContainsString('A price is an amount', $this->lastFlash());
        $this->post('/platform/plans', ['name' => 'Premium', 'amount_monthly' => '60000', 'public' => '1', 'featured' => '1']);
        self::assertSame(['Premium'], array_column($this->app->db()->all('SELECT name FROM plans WHERE featured = 1'), 'name'), 'one plan is recommended at a time');
        self::assertSame("Invigilation\nGradebook", $this->db("SELECT features FROM plans WHERE name = 'Standard'"));
        $this->newSession();
        $pricing = $this->get('/pricing')->body;
        foreach (['MWK 20,000', 'MWK 16,000', 'MWK 192,000 billed yearly, save 20%', 'Save up to 20%', 'Most popular', 'Everything in Basic, plus:', 'Gradebook', 'Paid monthly only'] as $text) {
            self::assertStringContainsString($text, $pricing);
        }
        self::assertStringContainsString('Plans that grow with your', $this->get('/')->body, 'the home page shows the plans too');
        $basic = (string) $this->db("SELECT uuid FROM plans WHERE name = 'Basic'");
        self::assertStringContainsString('value="' . $basic . '" selected', $this->get('/contact', ['plan' => $basic])->body);

        // The contact page: kept, sent to the people who look after institutions, limited.
        $contact = $this->get('/contact')->body;
        self::assertStringContainsString('hello@example.org', $contact);
        self::assertSame(422, $this->post('/contact', ['name' => 'Ann', 'email' => 'not an address', 'message' => 'Hi'])->status);
        $this->post('/contact', ['name' => 'Ann Phiri', 'email' => 'ann@example.org', 'organisation' => 'Blantyre Skills Centre', 'plan' => $basic, 'message' => 'How much for 80 students?']);
        $this->post('/contact', ['name' => 'Bot', 'email' => 'bot@example.org', 'message' => 'Buy now', 'website' => 'http://spam.example']);
        self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM contact_messages'), 'the hidden field catches scripts');
        self::assertStringContainsString('Message sent', $this->get('/contact', ['sent' => '1'])->body);
        for ($n = 0; $n < 4; $n++) {
            $this->post('/contact', ['name' => 'Ann', 'email' => 'ann@example.org', 'message' => 'Again ' . $n]);
        }
        self::assertStringContainsString('Too many messages', $this->post('/contact', ['name' => 'Ann', 'email' => 'ann@example.org', 'message' => 'Sixth'])->body);
        self::assertSame(5, (int) $this->db('SELECT COUNT(*) FROM contact_messages'));

        $this->as('admin');
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.username = 'admin' AND n.link = '/platform/messages'"));
        $page = $this->get('/platform/messages')->body;
        self::assertStringContainsString('How much for 80 students?', $page);
        self::assertStringContainsString('Asks about Basic', $page);
        $uuid = (string) $this->db("SELECT uuid FROM contact_messages WHERE name = 'Ann Phiri'");
        $this->post('/platform/messages/' . $uuid . '/answered');
        self::assertSame('answered', $this->db('SELECT status FROM contact_messages WHERE uuid = ?', [$uuid]));
        self::assertStringContainsString('Ann Phiri', $this->get('/platform/messages', ['status' => 'answered'])->body);

        // Members cannot see plans, messages or the site settings.
        $this->as('olive');
        self::assertSame(403, $this->get('/platform/plans')->status);
        self::assertSame(403, $this->get('/platform/messages')->status);
        self::assertSame(403, $this->get('/settings/site')->status);
    }

    public function testPlanLimitsAreCheckedWherePeopleJoin(): void
    {
        $a = $this->institution('olive', 'Lilongwe ICT Academy');
        $this->join($a, 'olive', 'bob', 'student');

        $this->as('admin');
        $this->post('/platform/plans', ['name' => 'Tiny', 'price' => 'MWK 10,000 a month', 'max_students' => '2', 'max_staff' => '1', 'public' => '1']);
        $tiny = (string) $this->db("SELECT uuid FROM plans WHERE name = 'Tiny'");
        $this->post('/platform/plans', ['name' => 'Bad', 'max_students' => 'many']);
        self::assertStringContainsString('whole number', $this->lastFlash());

        // Bob is one student; the owner is one staff member. Tiny fits.
        $this->post('/platform/institutions/' . $a . '/plan', ['plan' => $tiny, 'note' => 'Paid by Airtel Money']);
        self::assertSame($tiny, $this->db('SELECT p.uuid FROM institutions i JOIN plans p ON p.id = i.plan_id WHERE i.uuid = ?', [$a]));
        self::assertStringContainsString('Paid by Airtel Money', $this->get('/platform/institutions/' . $a)->body);

        // Staff are full: inviting another one is refused.
        $this->as('olive');
        $this->post('/institutions/' . $a . '/invitations', ['email' => 'erin@example.com', 'role' => 'instructor']);
        self::assertStringContainsString('allows 1 staff', $this->lastFlash());
        // One more student fits; a second invitation would be over, counting the open one.
        $this->join($a, 'olive', 'carol', 'student');
        $this->as('olive');
        $this->post('/institutions/' . $a . '/invitations', ['email' => 'dan@example.com', 'role' => 'student']);
        self::assertStringContainsString('allows 2 students', $this->lastFlash());
        self::assertStringContainsString('Tiny plan: 2 of 2 students, 1 of 1 staff', $this->get('/institutions/' . $a)->body);

        // An invitation sent before the plan changed cannot be accepted over the limit.
        $this->as('admin');
        $this->post('/platform/institutions/' . $a . '/plan', ['plan' => '']);
        $this->as('olive');
        $this->post('/institutions/' . $a . '/invitations', ['email' => 'dan@example.com', 'role' => 'student']);
        preg_match('#/invitations/([a-f0-9]{48})#', $this->lastFlash(), $m);
        $this->as('admin');
        $this->post('/platform/institutions/' . $a . '/plan', ['plan' => $tiny]);
        $this->as('dan');
        $this->post('/invitations/' . $m[1] . '/accept', ['notice' => '1']);
        self::assertStringContainsString('allows 2 students', $this->lastFlash());
        self::assertSame(0, (int) $this->db("SELECT COUNT(*) FROM institution_memberships m JOIN users u ON u.id = m.user_id WHERE u.username = 'dan'"));

        // Enrolment codes too.
        $this->as('olive');
        $this->post('/institutions/' . $a . '/students/codes', ['requires_approval' => '0']);
        $code = (string) $this->db('SELECT code FROM enrolment_codes');
        $this->as('fay');
        $this->post('/enrol', ['code' => $code]);
        $this->post('/enrol/join', ['notice' => '1']);
        self::assertStringContainsString('allows 2 students', $this->lastFlash());

        // A smaller plan than the institution already uses is refused.
        $this->as('admin');
        $this->post('/platform/plans', ['name' => 'Smaller', 'max_students' => '1']);
        $this->post('/platform/institutions/' . $a . '/plan', ['plan' => (string) $this->db("SELECT uuid FROM plans WHERE name = 'Smaller'")]);
        self::assertStringContainsString('has 2 students, more than the 1', $this->lastFlash());

        // History is kept, and each change is in the activity history in words.
        self::assertSame(3, (int) $this->db('SELECT COUNT(*) FROM institution_plan_changes'));
        $row = $this->app->db()->one("SELECT * FROM audit_log WHERE action = 'institution.plan_changed' ORDER BY id DESC LIMIT 1");
        self::assertStringContainsString('from no plan to Tiny', AuditText::describe($row)['text']);

        // A plan no longer offered keeps its institutions but cannot be chosen.
        $this->post('/platform/plans/' . $tiny . '/archive', ['archived' => '1']);
        self::assertSame($tiny, $this->db('SELECT p.uuid FROM institutions i JOIN plans p ON p.id = i.plan_id WHERE i.uuid = ?', [$a]));
        $this->post('/platform/institutions/' . $a . '/plan', ['plan' => '']);
        $this->post('/platform/institutions/' . $a . '/plan', ['plan' => $tiny]);
        self::assertStringContainsString('not offered any more', $this->lastFlash());
    }
}
