<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/**
 * Institutions: registering one, the platform's verification, memberships and invitations, and
 * above all that one institution's pages and people are closed to everyone who does not belong
 * to it, platform administrators included.
 */
final class InstitutionsTest extends HttpTestCase
{
    use InstitutionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->people();
    }

    public function testRegisteringWaitsForVerificationAndTheReviewerIsTold(): void
    {
        $this->as('olive');
        self::assertSame(200, $this->get('/institutions/register')->status);
        $bad = $this->post('/institutions/register', ['name' => 'X'] + $this->profile('Ignored'));
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Nothing was saved', $bad->body);
        $uuid = $this->institution('olive', 'Lilongwe ICT Academy', false);

        $i = $this->app->db()->one('SELECT * FROM institutions WHERE uuid = ?', [$uuid]);
        self::assertSame('draft', $i['status']);
        $m = $this->app->db()->one('SELECT * FROM institution_memberships WHERE institution_id = ?', [(int) $i['id']]);
        self::assertSame('owner', $m['role'], 'the person registering becomes its owner');
        $admin = $this->s->users()->findByUsername('admin');
        $told = fn(): int => (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND kind = 'institution'", [(int) $admin['id']]);
        self::assertSame(0, $told(), 'a draft is the applicant\'s own');
        $this->post('/institutions/' . $uuid . '/application/submit', ['accept' => '1']);
        self::assertSame('pending', $this->app->db()->value('SELECT status FROM institutions WHERE uuid = ?', [$uuid]));
        self::assertSame(1, $told());

        $page = $this->get('/institutions/' . $uuid)->body;
        self::assertStringContainsString('With the reviewers', $page);
        self::assertSame(403, $this->get('/institutions/' . $uuid . '/members')->status, 'nobody is invited before verification');
        self::assertSame(403, $this->post('/institutions/' . $uuid . '/invitations', ['email' => 'bob@example.com', 'role' => 'student'])->status);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM institution_invitations'));
        self::assertSame(200, $this->get('/institutions/' . $uuid . '/profile')->status, 'the owner can correct the profile while it waits');
        self::assertStringContainsString('href="/institutions"', $page, 'members of an institution see it in the menu');
    }

    public function testRejectingNeedsAReasonAndTheOwnerCanSendItAgain(): void
    {
        $uuid = $this->institution('olive', 'Zomba Training Centre', false);
        $this->as('admin');
        $this->post('/platform/institutions/' . $uuid . '/decision', ['decision' => 'reject', 'note' => 'Not sent yet.']);
        self::assertStringContainsString('cannot be given that decision', $this->flashes()[0]['message'], 'a draft is not the reviewer\'s');
        $this->as('olive');
        $this->post('/institutions/' . $uuid . '/application/submit', ['accept' => '1']);
        $this->as('admin');
        $this->post('/platform/institutions/' . $uuid . '/decision', ['decision' => 'reject', 'note' => '']);
        self::assertStringContainsString('Give the reason', $this->flashes()[0]['message']);
        $this->post('/platform/institutions/' . $uuid . '/decision', ['decision' => 'reject', 'note' => 'The telephone number does not answer.']);
        self::assertSame('rejected', $this->app->db()->value('SELECT status FROM institutions WHERE uuid = ?', [$uuid]));
        self::assertStringContainsString('not approved', $this->flashes()[0]['message']);
        $this->post('/platform/institutions/' . $uuid . '/decision', ['decision' => 'suspend', 'note' => 'x']);
        self::assertStringContainsString('cannot be given that decision', $this->flashes()[0]['message']);

        $this->as('olive');
        self::assertStringContainsString('The telephone number does not answer.', $this->get('/institutions/' . $uuid)->body);
        $this->post('/institutions/' . $uuid . '/resubmit');
        self::assertSame('pending', $this->app->db()->value('SELECT status FROM institutions WHERE uuid = ?', [$uuid]));
        self::assertSame(3, (int) $this->app->db()->value('SELECT COUNT(*) FROM institution_reviews'), 'sending, the decision and sending again are all kept');
    }

    public function testNoOneOutsideAnInstitutionCanOpenItNotEvenAPlatformAdministrator(): void
    {
        $a = $this->institution('olive', 'Blantyre Institute');
        $b = $this->institution('dan', 'Mzuzu Academy');
        $this->join($a, 'olive', 'bob', 'admin');
        $this->join($b, 'dan', 'carol', 'student');

        $this->as('admin');
        foreach (['', '/members', '/profile', '/activity'] as $page) {
            self::assertSame(404, $this->get('/institutions/' . $a . $page)->status, 'admin ' . $page);
        }
        self::assertSame(200, $this->get('/platform/institutions/' . $a)->status);
        $review = $this->get('/platform/institutions/' . $a)->body;
        self::assertStringNotContainsString('bob@example.com', $review, 'reviewers see the owners, not the people');
        self::assertStringContainsString('olive@example.com', $review);

        // An administrator of A, with A's rights, against B.
        $this->as('bob');
        self::assertSame(200, $this->get('/institutions/' . $a . '/members')->status);
        self::assertSame(404, $this->get('/institutions/' . $b)->status);
        self::assertSame(404, $this->get('/institutions/' . $b . '/members')->status);
        self::assertSame(404, $this->post('/institutions/' . $b . '/invitations', ['email' => 'erin@example.com', 'role' => 'student'])->status);
        // B's member, addressed through A's pages.
        $carol = $this->memberUuid($b, 'carol');
        self::assertSame(404, $this->post('/institutions/' . $a . '/members/' . $carol . '/remove')->status);
        self::assertSame(404, $this->post('/institutions/' . $a . '/members/' . $carol . '/role', ['role' => 'instructor'])->status);
        self::assertSame('active', $this->app->db()->value('SELECT status FROM institution_memberships WHERE uuid = ?', [$carol]));
        self::assertSame('student', $this->app->db()->value('SELECT role FROM institution_memberships WHERE uuid = ?', [$carol]));
        // B's invitation, withdrawn through A.
        $this->as('dan');
        $this->post('/institutions/' . $b . '/invitations', ['email' => 'erin@example.com', 'role' => 'student']);
        $inv = (int) $this->app->db()->value('SELECT id FROM institution_invitations WHERE email = ? AND revoked_at IS NULL', ['erin@example.com']);
        $this->as('bob');
        $this->post('/institutions/' . $a . '/invitations/' . $inv . '/revoke');
        self::assertNull($this->app->db()->value('SELECT revoked_at FROM institution_invitations WHERE id = ?', [$inv]));
        self::assertStringNotContainsString('Mzuzu Academy', $this->get('/institutions')->body);

        // Platform review needs its permission.
        self::assertSame(403, $this->get('/platform/institutions')->status);
        self::assertSame(403, $this->post('/platform/institutions/' . $b . '/decision', ['decision' => 'suspend', 'note' => 'x'])->status);
    }

    public function testRolesGiveOnlyTheirInstitutionPermissions(): void
    {
        $a = $this->institution('olive', 'Kasungu College');
        $b = $this->institution('dan', 'Salima Centre');
        $this->join($a, 'olive', 'bob', 'instructor');
        $this->join($b, 'dan', 'bob', 'admin');

        $this->as('bob');
        $list = $this->get('/institutions')->body;
        self::assertStringContainsString('Kasungu College', $list, 'one person, several institutions');
        self::assertStringContainsString('Salima Centre', $list);
        self::assertSame(200, $this->get('/institutions/' . $a . '/members')->status, 'an instructor sees who belongs');
        self::assertStringNotContainsString('olive@example.com', $this->get('/institutions/' . $a . '/members')->body, 'but not their email addresses');
        self::assertSame(403, $this->post('/institutions/' . $a . '/invitations', ['email' => 'erin@example.com', 'role' => 'student'])->status,
            'being an administrator of another institution gives nothing here');
        self::assertSame(403, $this->get('/institutions/' . $a . '/activity')->status);
        self::assertSame(403, $this->get('/institutions/' . $a . '/profile')->status);
        self::assertSame(200, $this->get('/institutions/' . $b . '/activity')->status);

        // A student sees the overview and nothing else.
        $this->join($a, 'olive', 'carol', 'student');
        self::assertSame(200, $this->get('/institutions/' . $a)->status);
        self::assertSame(403, $this->get('/institutions/' . $a . '/members')->status);

        // An extra permission, given by an owner, works in that institution only.
        $this->as('olive');
        $this->post('/institutions/' . $a . '/members/' . $this->memberUuid($a, 'carol') . '/role', ['role' => 'student', 'permissions' => ['activity.view']]);
        $this->as('carol');
        self::assertSame(200, $this->get('/institutions/' . $a . '/activity')->status);
    }

    public function testOwnersAreProtected(): void
    {
        $a = $this->institution('olive', 'Mangochi Institute');
        $this->join($a, 'olive', 'bob', 'admin');
        $this->join($a, 'olive', 'carol', 'student');
        $olive = $this->memberUuid($a, 'olive');

        $this->as('bob');
        $this->post('/institutions/' . $a . '/invitations', ['email' => 'erin@example.com', 'role' => 'owner']);
        self::assertStringContainsString('Only an owner can make someone an owner', $this->flashes()[0]['message']);
        $this->post('/institutions/' . $a . '/members/' . $olive . '/remove');
        self::assertStringContainsString('Only an owner can change another owner', $this->flashes()[0]['message']);
        $this->post('/institutions/' . $a . '/members/' . $this->memberUuid($a, 'bob') . '/role', ['role' => 'admin', 'permissions' => ['institution.manage']]);
        self::assertStringContainsString('cannot change your own membership', $this->flashes()[0]['message']);
        $this->post('/institutions/' . $a . '/members/' . $this->memberUuid($a, 'carol') . '/role', ['role' => 'student', 'permissions' => ['institution.manage']]);
        self::assertNull($this->app->db()->value('SELECT permissions FROM institution_memberships WHERE uuid = ?', [$this->memberUuid($a, 'carol')]),
            'nobody hands out a permission they do not hold');

        $this->as('olive');
        $this->post('/institutions/' . $a . '/leave');
        self::assertStringContainsString('only owner', $this->flashes()[0]['message']);
        self::assertSame('active', $this->app->db()->value('SELECT status FROM institution_memberships WHERE uuid = ?', [$olive]));

        $this->as('admin');
        $oliveUser = $this->s->users()->findByUsername('olive');
        $this->session->set('_confirmed', ['user' => (int) $this->s->users()->findByUsername('admin')['id'], 'until' => time() + 600]);
        $this->post('/users/' . $oliveUser['uuid'] . '/delete');
        self::assertStringContainsString('only owner of Mangochi Institute', $this->flashes()[0]['message']);
        self::assertNotNull($this->s->users()->findByUsername('olive'));

        // With a second owner, the first can step down.
        $this->as('olive');
        $this->post('/institutions/' . $a . '/members/' . $this->memberUuid($a, 'bob') . '/role', ['role' => 'owner']);
        self::assertSame('owner', $this->app->db()->value('SELECT role FROM institution_memberships WHERE uuid = ?', [$this->memberUuid($a, 'bob')]));
        $this->post('/institutions/' . $a . '/leave');
        self::assertSame('removed', $this->app->db()->value('SELECT status FROM institution_memberships WHERE uuid = ?', [$olive]));
    }

    public function testRemovingSomeoneKeepsTheRecordAndCanBeUndoneByInvitingAgain(): void
    {
        $a = $this->institution('olive', 'Dedza Skills Centre');
        $this->join($a, 'olive', 'bob', 'instructor');
        $bob = $this->memberUuid($a, 'bob');
        $this->as('olive');
        $this->post('/institutions/' . $a . '/members/' . $bob . '/remove');
        $row = $this->app->db()->one('SELECT * FROM institution_memberships WHERE uuid = ?', [$bob]);
        self::assertSame('removed', $row['status'], 'the membership is kept, marked removed');
        self::assertNotNull($row['removed_at']);
        self::assertStringContainsString('Include people who left', $this->get('/institutions/' . $a . '/members')->body);
        self::assertStringContainsString('bob', $this->get('/institutions/' . $a . '/members', ['removed' => '1'])->body);

        $this->as('bob');
        self::assertSame(404, $this->get('/institutions/' . $a)->status, 'someone removed is outside again');

        $this->join($a, 'olive', 'bob', 'student');
        self::assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM institution_memberships m JOIN users u ON u.id = m.user_id WHERE u.username = ?', ['bob']));
        self::assertSame('student', $this->app->db()->value('SELECT role FROM institution_memberships WHERE uuid = ?', [$bob]));
    }

    public function testInvitationsGoToTheirAddressAndEnd(): void
    {
        $a = $this->institution('olive', 'Karonga Tech');
        $this->as('olive');
        $this->post('/institutions/' . $a . '/invitations', ['email' => 'Bob@Example.com', 'role' => 'student']);
        preg_match('#/invitations/([a-f0-9]{48})#', $this->flashes()[0]['message'], $m);
        $token = $m[1];
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM institution_invitations WHERE token_sha256 = ?', [$token]), 'only the hash is stored');
        $bob = $this->s->users()->findByUsername('bob');
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND kind = 'institution'", [(int) $bob['id']]),
            'an existing account is told in its inbox');

        $this->newSession();
        $page = $this->get('/invitations/' . $token);
        self::assertSame(200, $page->status, 'the invitation shows to someone not signed in');
        self::assertStringContainsString('Join Karonga Tech', $page->body);
        self::assertSame(404, $this->get('/invitations/' . str_repeat('a', 48))->status);

        $this->as('carol');
        self::assertStringContainsString('This invitation is for bob@example.com', $this->get('/invitations/' . $token)->body);
        $this->post('/invitations/' . $token . '/accept', ['notice' => '1']);
        self::assertSame(404, $this->get('/institutions/' . $a)->status, 'a forwarded link does not let someone else in');

        $this->as('olive');
        $this->post('/institutions/' . $a . '/invitations', ['email' => 'bob@example.com', 'role' => 'instructor']);
        self::assertNotNull($this->app->db()->value('SELECT revoked_at FROM institution_invitations WHERE token_sha256 = ?', [hash('sha256', $token)]),
            'a newer invitation replaces the open one');
        $this->as('bob');
        self::assertStringContainsString('withdrawn', $this->get('/invitations/' . $token)->body);
    }

    public function testASuspendedInstitutionIsClosedToItsMembers(): void
    {
        $a = $this->institution('olive', 'Nkhata Bay Centre');
        $this->join($a, 'olive', 'bob', 'admin');
        $this->as('admin');
        $this->post('/platform/institutions/' . $a . '/decision', ['decision' => 'suspend', 'note' => 'Complaints received.']);
        $this->as('bob');
        self::assertSame(403, $this->get('/institutions/' . $a)->status);
        self::assertSame(403, $this->get('/institutions/' . $a . '/members')->status);
        $this->as('olive');
        self::assertStringContainsString('Complaints received.', $this->get('/institutions/' . $a)->body, 'the owners see why');
        self::assertSame(403, $this->get('/institutions/' . $a . '/members')->status);

        $this->as('admin');
        $this->post('/platform/institutions/' . $a . '/decision', ['decision' => 'verify']);
        $this->as('bob');
        self::assertSame(200, $this->get('/institutions/' . $a . '/members')->status, 'reinstated');
        $this->as('olive');
        $activity = $this->get('/institutions/' . $a . '/activity')->body;
        self::assertStringContainsString('suspended Nkhata Bay Centre', $activity);
        self::assertStringContainsString('reinstated Nkhata Bay Centre', $activity);
        self::assertStringContainsString('joined Nkhata Bay Centre as Institution administrator', $activity);
    }
}
