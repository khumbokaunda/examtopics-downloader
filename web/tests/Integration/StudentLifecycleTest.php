<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** One account, many institutional relationships: invitations and their states, enrolment codes, identity matching on import, standing. */
final class StudentLifecycleTest extends HttpTestCase
{
    use InstitutionFixtures;

    private string $a;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->name() === 'testAStudentWhoIsNotActiveCannotSitAssessments') {
            return;
        }
        $this->people('fay', 'gus');
        $this->a = $this->institution('olive', 'Lilongwe ICT Academy');
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

    private function base(): string
    {
        return '/institutions/' . $this->a . '/students';
    }

    public function testInvitationsMoveThroughTheirStatesAndCanBeResentOrCancelled(): void
    {
        $this->as('olive');
        $this->post('/institutions/' . $this->a . '/invitations', ['email' => 'nobody@example.com', 'role' => 'student']);
        $this->post('/institutions/' . $this->a . '/invitations', ['email' => 'fay@example.com', 'role' => 'student']);
        $this->post('/institutions/' . $this->a . '/invitations', ['email' => 'bob@example.com', 'role' => 'instructor']);
        $page = $this->get($this->base())->body;
        self::assertStringContainsString('>Invited<', $page);
        self::assertStringContainsString('Pending acceptance', $page, 'fay has an account and only needs to accept');
        $bobId = (int) $this->db("SELECT id FROM institution_invitations WHERE email = 'bob@example.com'");
        self::assertStringNotContainsString('/invitations/' . $bobId . '/resend', $page, 'staff invitations are on the People page');

        $nobody = (int) $this->db("SELECT id FROM institution_invitations WHERE email = 'nobody@example.com'");
        $oldToken = (string) $this->db('SELECT token_sha256 FROM institution_invitations WHERE id = ?', [$nobody]);
        $this->post($this->base() . '/invitations/' . $nobody . '/resend');
        preg_match('#/invitations/([a-f0-9]{48})#', $this->lastFlash(), $m);
        self::assertNotEmpty($m, 'without email set up, the new link is shown');
        self::assertNotSame($oldToken, $this->db('SELECT token_sha256 FROM institution_invitations WHERE id = ?', [$nobody]), 'the old link stops working');
        self::assertSame(2, (int) $this->db('SELECT sent_count FROM institution_invitations WHERE id = ?', [$nobody]));
        $this->newSession();
        $this->get('/invitations/' . $m[1]);
        $this->as('olive');
        self::assertStringContainsString('Pending registration', $this->get($this->base())->body, 'opened, but there is no account yet');

        $bob = (int) $this->db("SELECT id FROM institution_invitations WHERE email = 'bob@example.com'");
        $this->post($this->base() . '/invitations/' . $bob . '/cancel');
        self::assertNull($this->db('SELECT revoked_at FROM institution_invitations WHERE id = ?', [$bob]), 'student managers do not touch staff invitations');
        $this->post($this->base() . '/invitations/' . $nobody . '/cancel');
        self::assertNotNull($this->db('SELECT revoked_at FROM institution_invitations WHERE id = ?', [$nobody]));

        $fayInv = (string) $this->db("SELECT id FROM institution_invitations WHERE email = 'fay@example.com'");
        $this->post($this->base() . '/invitations/' . $fayInv . '/resend');
        preg_match('#/invitations/([a-f0-9]{48})#', $this->lastFlash(), $f);
        $this->as('fay');
        self::assertStringContainsString('Joining an institution', $this->get('/invitations/' . $f[1])->body, 'the membership notice is shown');
        $this->post('/invitations/' . $f[1] . '/accept');
        self::assertNull($this->db("SELECT accepted_at FROM institution_invitations WHERE email = 'fay@example.com'"), 'the notice must be ticked');
        $this->post('/invitations/' . $f[1] . '/accept', ['notice' => '1']);
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM policy_acceptances WHERE policy = 'membership_notice' AND context = 'membership'"));
    }

    public function testEnrolmentCodesWithAndWithoutApproval(): void
    {
        $this->as('olive');
        $this->post('/institutions/' . $this->a . '/classes', ['name' => 'CCNA 1', 'intake' => 'Jan 2027']);
        $class = (string) $this->db('SELECT uuid FROM institution_classes');
        $this->post($this->base() . '/codes', ['label' => 'Room 4', 'class' => $class, 'requires_approval' => '1', 'max_uses' => '2']);
        $code = (string) $this->db('SELECT code FROM enrolment_codes');
        self::assertMatchesRegularExpression('/^LILO-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
        $this->post($this->base() . '/codes', ['requires_approval' => '0', 'expires_on' => '2001-01-01']);
        self::assertStringContainsString('expiry date from today', $this->lastFlash());
        $this->post($this->base() . '/codes', []);
        $open = (string) $this->db('SELECT code FROM enrolment_codes WHERE requires_approval = 0');

        $this->as('fay');
        self::assertStringContainsString('No institution uses that code', $this->post('/enrol', ['code' => 'NOPE-1234-5678'])->body);
        $page = $this->post('/enrol', ['code' => strtolower($code)])->body;
        self::assertStringContainsString('Join Lilongwe ICT Academy', $page, 'case and spacing do not matter');
        self::assertStringContainsString('approves each request', $page);
        $this->post('/enrol/join', ['notice' => '1']);
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM enrolment_requests WHERE status = 'pending'"));
        self::assertSame(0, (int) $this->db("SELECT COUNT(*) FROM institution_memberships WHERE role = 'student'"), 'not a member until approved');
        $this->post('/enrol', ['code' => $code]);
        $this->post('/enrol/join', ['notice' => '1']);
        self::assertStringContainsString('already waiting', $this->lastFlash());

        $this->as('olive');
        self::assertStringContainsString('Requests to join', $this->get($this->base())->body);
        $req = (string) $this->db('SELECT uuid FROM enrolment_requests');
        $this->post($this->base() . '/requests/' . $req, ['decision' => 'approve']);
        $member = $this->memberUuid($this->a, 'fay');
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM class_enrolments e JOIN institution_memberships m ON m.id = e.membership_id WHERE m.uuid = ? AND e.status = 'active'", [$member]),
            'the code\'s class is applied');
        self::assertSame(1, (int) $this->db('SELECT uses FROM enrolment_codes WHERE code = ?', [$code]));

        $this->as('gus');
        $this->post('/enrol', ['code' => $open]);
        $this->post('/enrol/join', []);
        self::assertStringContainsString('tick the box', $this->lastFlash());
        $this->post('/enrol', ['code' => $open]);
        $this->post('/enrol/join', ['notice' => '1']);
        self::assertSame('active', $this->db("SELECT m.status FROM institution_memberships m JOIN users u ON u.id = m.user_id WHERE u.username = 'gus'"), 'joins at once');
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM policy_acceptances WHERE policy = 'membership_notice' AND context = 'membership'"));

        $this->as('olive');
        $this->post($this->base() . '/codes/' . $this->db('SELECT uuid FROM enrolment_codes WHERE code = ?', [$open]) . '/revoke');
        $this->as('dan');
        self::assertStringContainsString('no longer in use', $this->post('/enrol', ['code' => $open])->body);
        for ($k = 0; $k < 10; $k++) {
            $this->post('/enrol', ['code' => 'XXXX-0000-' . $k]);
        }
        self::assertStringContainsString('Too many codes', $this->post('/enrol', ['code' => $code])->body, 'codes cannot be found by trying them');
    }

    public function testImportMatchesByEmailOnlyAndHoldsConflictsForADecision(): void
    {
        $this->join($this->a, 'olive', 'carol', 'student');
        $this->app->db()->run("UPDATE users SET display_name = 'Carol Phiri' WHERE username = 'carol'");
        $this->app->db()->run("UPDATE users SET display_name = 'Dan Banda' WHERE username = 'dan'");
        $this->as('olive');
        $this->post($this->base() . '/' . $this->memberUuid($this->a, 'carol'), ['student_number' => 'ICT001', 'programme' => '', 'status' => 'active', 'enrolled_on' => '']);
        $this->post('/institutions/' . $this->a . '/invitations', ['email' => 'waiting@example.com', 'role' => 'student']);
        $csv = "email,name,student_number\n"
            . "carol@example.com,Phiri Carol,ICT009\n"   // member, number differs: decide
            . "dan@example.com,Daniel Banda,ICT002\n"    // existing user, name differs: decide
            . "fay@example.com,,ICT003\n"                 // existing user
            . "new@example.com,New Person,ICT004\n"       // new user
            . "waiting@example.com,,ICT005\n"             // already invited: kept
            . "broken,,\n";
        $tmp = (string) tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, $csv);
        $send = fn() => $this->postFiles($this->base() . '/import', [], ['file' => ['name' => 's.csv', 'tmp_name' => $tmp, 'size' => strlen($csv), 'error' => UPLOAD_ERR_OK]]);
        $page = $send()->body;
        self::assertStringContainsString('3 ready, 2 to decide, 1 not importable', $page);
        foreach (['Existing ExamLib user', 'New user', 'Already invited', 'Needs a decision', 'Not importable',
                 'has the student number ICT001; the file says ICT009', 'differs from the account'] as $text) {
            self::assertStringContainsString($text, $page);
        }
        $problems = $this->get($this->base() . '/import/problems.csv')->body;
        self::assertSame(4, count(array_filter(explode("\n", $problems))), 'heading, two to decide and one invalid');

        $line = (string) $this->db('SELECT 3');
        $this->post($this->base() . '/import/confirm', ['confirm' => [$line]]);
        self::assertStringContainsString('0 students updated and put in their classes, 3 invited, 1 invitations already waiting, 1 left for a decision, 1 not imported', $this->lastFlash());
        self::assertSame('ICT001', $this->db("SELECT student_number FROM institution_students s JOIN institution_memberships m ON m.id = s.membership_id JOIN users u ON u.id = m.user_id WHERE u.username = 'carol'"),
            'a number on record is not changed unless someone decides to');
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM institution_invitations WHERE email = 'waiting@example.com' AND revoked_at IS NULL"));
        $invites = (int) $this->db("SELECT COUNT(*) FROM institution_invitations WHERE revoked_at IS NULL");

        $send();
        $this->post($this->base() . '/import/confirm');
        self::assertSame($invites, (int) $this->db("SELECT COUNT(*) FROM institution_invitations WHERE revoked_at IS NULL"), 'importing the same file again sends nothing twice');
        self::assertSame(0, (int) $this->db("SELECT COUNT(*) FROM users WHERE email = 'new@example.com'"), 'no account is ever created for someone');
    }

    public function testAStudentWhoIsNotActiveCannotSitAssessments(): void
    {
        [$a, , $assessment] = $this->sittingScenario();
        $this->as('carol');
        self::assertStringContainsString('Module test', $this->get('/my/assessments')->body);
        $this->as('olive');
        $this->post('/institutions/' . $a . '/students/' . $this->memberUuid($a, 'carol'), ['student_number' => '', 'programme' => '', 'status' => 'withdrawn', 'enrolled_on' => '']);
        self::assertSame('withdrawn', $this->db("SELECT s.status FROM institution_students s JOIN institution_memberships m ON m.id = s.membership_id JOIN users u ON u.id = m.user_id WHERE u.username = 'carol'"));
        $this->as('carol');
        self::assertStringNotContainsString('Module test', $this->get('/my/assessments')->body);
        $this->post('/my/assessments/' . $a . '/' . $assessment . '/start', ['accept' => '1']);
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM assessment_sessions'));
        self::assertSame('active', $this->db("SELECT m.status FROM institution_memberships m JOIN users u ON u.id = m.user_id WHERE u.username = 'carol'"),
            'withdrawing changes the relationship, not the account or membership history');
    }
}
