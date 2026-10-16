<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use App\Services\Mailer;
use Tests\Support\ArrayTransport;
use Tests\Support\HttpTestCase;

/** Exam requests, the inbox and the emails that go with them. */
final class RequestsTest extends HttpTestCase
{
    private const PW = 'member password long';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->app->set(Mailer::class, new Mailer($this->app, $this->s->branding(), new ArrayTransport()));
        $this->s->mailer()->save(['host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls', 'username' => 'sender@example.com', 'password' => 'x']);
        $this->s->users()->create('admin', 'correct horse battery', 'admin', ['email' => 'owner@example.com', 'email_verified' => true]);
        $this->s->users()->create('member', self::PW, Access::MEMBER, ['email' => 'member@example.com', 'email_verified' => true]);
        $this->s->users()->create('other', self::PW, Access::MEMBER);
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        $this->newSession();
    }

    private function asMember(string $name = 'member'): void
    {
        $this->newSession();
        $this->login($name, self::PW);
    }

    private function request(string $exam = 'h12-811'): void
    {
        $r = $this->post('/requests', ['provider' => 'huawei', 'exam' => $exam, 'note' => 'For my exam next month', 'return' => 'provider']);
        self::assertSame('/providers/huawei', $r->headers['Location']);
    }

    public function testRequestNotifiesTheOwnerInTheInboxAndByEmail(): void
    {
        $this->asMember();
        self::assertStringContainsString('>Request</button>', $this->get('/providers/huawei')->body);
        $this->request();
        self::assertSame('success', $this->flashes()[0]['type']);
        self::assertStringContainsString('Requested</a>', $this->get('/providers/huawei')->body);
        $this->request();
        self::assertStringContainsString('already requested', $this->flashes()[0]['message']);
        self::assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM exam_requests'));
        self::assertSame(0, $this->s->notifier()->unreadCount((int) $this->s->users()->findByUsername('member')['id']), 'the person asking is not notified');

        $admin = $this->s->users()->findByUsername('admin');
        $n = $this->app->db()->one('SELECT * FROM notifications WHERE user_id = ?', [(int) $admin['id']]);
        self::assertSame('exam_request', $n['kind']);
        self::assertStringContainsString('H12-811', $n['title']);
        self::assertStringContainsString('For my exam next month', $n['body']);
        $mail = $this->app->db()->one('SELECT * FROM mail_queue');
        self::assertSame('owner@example.com', $mail['to_email']);

        // A second person asking for the same exam merges into the same unread notification.
        $this->asMember('other');
        $this->request();
        self::assertSame(2, (int) $this->app->db()->value('SELECT count FROM notifications WHERE id = ?', [(int) $n['id']]));
        self::assertSame(1, $this->s->notifier()->unreadCount((int) $admin['id']));

        // The bell and the inbox.
        $this->newSession();
        $this->login();
        self::assertStringContainsString('class="bell-count">1<', $this->get('/')->body);
        self::assertStringContainsString('H12-811', $this->get('/inbox')->body);
        $r = $this->get('/inbox/' . $n['id']);
        self::assertSame('/requests?status=open&provider=huawei&exam=h12-811', $r->headers['Location']);
        self::assertSame(0, $this->s->notifier()->unreadCount((int) $admin['id']));
        self::assertStringContainsString('2 people want this', $this->get('/requests', ['status' => 'open'])->body);
    }

    /** Exams are chosen from a provider's list; a code is only typed when no list was ever loaded. */
    public function testTheFormOffersTheExamList(): void
    {
        $this->s->providers()->upsert('cisco', 'Cisco', 'manual');
        $this->asMember();
        $page = $this->get('/requests', ['provider' => 'huawei'])->body;
        self::assertStringContainsString('Request an exam', $page);
        self::assertMatchesRegularExpression('#<select class="form-select" id="exam" name="exam" required>#', $page);
        self::assertStringContainsString('<option value="h12-811"', $page);
        self::assertStringNotContainsString('placeholder="h12-811"', $page, 'no typing when the list is loaded');

        $r = $this->post('/requests', ['provider' => 'huawei', 'exam' => 'h99-999']);
        self::assertSame('/requests', $r->headers['Location']);
        self::assertStringContainsString('not on', $this->flashes()[0]['message']);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM exam_requests'));

        // A provider whose list was never loaded: type the code.
        $page = $this->get('/requests', ['provider' => 'cisco'])->body;
        self::assertStringContainsString('placeholder="h12-811"', $page);
        self::assertStringContainsString('has not been loaded yet', $page);
        $this->post('/requests', ['provider' => 'cisco', 'exam' => '200-301']);
        self::assertSame('success', $this->flashes()[0]['type']);

        // Already requested: shown, but not offered again.
        $this->request();
        $this->flashes();
        self::assertMatchesRegularExpression('#<option value="h12-811" disabled[^>]*>H12-811[^<]*· requested#', $this->get('/requests', ['provider' => 'huawei'])->body);

        // Administrators collect exams themselves, so they get the list without the form.
        $this->newSession();
        $this->login();
        $page = $this->get('/requests')->body;
        self::assertStringNotContainsString('Request an exam', $page);
        self::assertStringContainsString('class="col-12"', $page);
    }

    public function testRequestsArePrivateToTheirOwner(): void
    {
        $this->asMember();
        $this->request();
        $uuid = (string) $this->app->db()->value('SELECT uuid FROM exam_requests');
        $notification = (int) $this->app->db()->value('SELECT id FROM notifications');

        $this->asMember('other');
        self::assertStringNotContainsString('H12-811', $this->get('/requests')->body, 'members see only their own requests');
        self::assertSame(404, $this->post('/requests/' . $uuid . '/cancel')->status);
        self::assertSame(403, $this->post('/requests/' . $uuid . '/answer', ['status' => 'declined'])->status);
        self::assertSame(404, $this->get('/inbox/' . $notification)->status, 'someone else\'s notification');
        self::assertSame(403, $this->get('/users')->status);

        $this->asMember();
        $this->post('/requests/' . $uuid . '/cancel');
        self::assertSame('cancelled', $this->app->db()->value('SELECT status FROM exam_requests'));
        self::assertNotNull($this->app->db()->value('SELECT read_at FROM notifications'), 'nobody waits on it now');
    }

    public function testDecliningTellsThePersonWhoAsked(): void
    {
        $this->asMember();
        $this->request('h19-301');
        $uuid = (string) $this->app->db()->value('SELECT uuid FROM exam_requests');
        $this->newSession();
        $this->login();
        // Without a choice nothing happens, and the message says what is missing.
        $this->flashes();
        $this->post('/requests/' . $uuid . '/answer', ['response' => 'x']);
        self::assertStringContainsString('Choose', $this->flashes()[0]['message']);
        self::assertSame('open', $this->app->db()->value('SELECT status FROM exam_requests'));
        $this->post('/requests/' . $uuid . '/answer', ['status' => 'declined', 'response' => 'ExamTopics has no discussions for it.']);
        $member = $this->s->users()->findByUsername('member');
        $n = $this->app->db()->one("SELECT * FROM notifications WHERE kind = 'request_update'");
        self::assertSame((int) $member['id'], (int) $n['user_id']);
        self::assertStringContainsString('declined', $n['title']);
        self::assertStringContainsString('ExamTopics has no discussions', $n['body']);
        self::assertSame('member@example.com', $this->app->db()->value("SELECT to_email FROM mail_queue WHERE idempotency_key LIKE 'request-declined:%'"));
        self::assertNotNull($this->app->db()->value("SELECT read_at FROM notifications WHERE kind = 'exam_request'"), 'the owner\'s notification is resolved');

        // People who turned email off are told in the inbox only.
        $this->s->users()->setPreferences((int) $member['id'], ['email_notifications' => '0']);
        $this->asMember();
        $this->request('h12-811');
        $this->newSession();
        $this->login();
        $uuid = (string) $this->app->db()->value("SELECT uuid FROM exam_requests WHERE exam_code = 'h12-811'");
        $this->post('/requests/' . $uuid . '/answer', ['status' => 'declined']);
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM mail_queue WHERE to_email = 'member@example.com'"));
    }

    public function testCollectingAnExamClosesItsRequests(): void
    {
        $this->asMember();
        $this->request();
        $this->asMember('other');
        $this->request();

        $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        self::assertSame(['collected'], array_values(array_unique(array_column($this->app->db()->all('SELECT status FROM exam_requests'), 'status'))));
        $updates = $this->app->db()->all("SELECT * FROM notifications WHERE kind = 'request_update'");
        self::assertCount(2, $updates);
        self::assertSame('/library/huawei/h12-811', $updates[0]['link']);
        self::assertStringContainsString('is in the library', $updates[0]['title']);

        $this->asMember();
        self::assertStringContainsString('Open<', $this->get('/requests')->body);
        $this->request();
        self::assertStringContainsString('already in the library', $this->flashes()[0]['message']);
    }
}
