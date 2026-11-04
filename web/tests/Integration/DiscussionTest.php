<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use Tests\Support\HttpTestCase;

/**
 * Members' own discussion of a question, with answer votes, and reports of a problem with a
 * question for the people who correct questions.
 */
final class DiscussionTest extends HttpTestCase
{
    private const BASE = '/library/huawei/h12-811';
    private const PW = 'member password long';
    private array $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        $this->version = (array) $this->s->versions()->find((int) $this->s->jobs()->find($id)['cache_version_id']);
        foreach (['mercy' => 'Mercy Banda', 'john' => 'John Phiri'] as $name => $display) {
            $this->s->users()->create($name, self::PW, Access::MEMBER, ['display_name' => $display]);
        }
    }

    private function as(string $who): void
    {
        $this->newSession();
        $who === 'admin' ? $this->login() : $this->login($who, self::PW);
    }

    /** @param array<string,string> $data */
    private function comment(string $key, array $data): \App\Core\Http\Response
    {
        return $this->post(self::BASE . '/discussion', ['key' => $key, 'version' => $this->version['version'], 'page' => '1'] + $data);
    }

    private function reader(array $query = []): string
    {
        return $this->get(self::BASE . '/view', ['version' => $this->version['version']] + $query)->body;
    }

    public function testMembersDiscussAndVoteOnAnswers(): void
    {
        $this->as('mercy');
        self::assertStringContainsString("Members' discussion (0)", $this->reader());
        $r = $this->comment('1-1', ['body' => "I think it is C.\nOSPF is not it.", 'letter_C' => '1']);
        self::assertStringEndsWith('#discussion-1', $r->headers['Location'], 'back at the discussion, which opens');
        self::assertSame('Question 1: posted, with your vote for C.', $this->flashes()[0]['message']);
        $page = $this->reader();
        self::assertStringContainsString("Members' discussion (1)", $page);
        self::assertStringContainsString("<p>I think it is C.<br>\nOSPF is not it.</p>", $page);
        self::assertStringContainsString('>suggests C<', $page);
        self::assertStringContainsString("Members' votes here: C (1)", $page);
        self::assertStringContainsString('Mercy Banda', $page);

        // Nothing at all is refused; a vote alone is fine; the latest vote is the one counted.
        $this->comment('1-1', ['body' => '  ']);
        self::assertSame('Question 1: write something, or pick the answer you would choose.', $this->flashes()[0]['message']);
        $this->comment('1-1', ['letter_B' => '1', 'letter_E' => '1']);
        self::assertStringContainsString("Members' votes here: BE (1)", $this->reader(), 'one vote per person');

        // Two members disagreeing with the answer make it one to check, here and on the integrity page.
        $this->comment('1-1', ['letter_C' => '1']);
        $this->as('john');
        $this->comment('1-1', ['body' => 'Agreed, C.', 'letter_C' => '1']);
        $this->as('admin');
        $page = $this->reader(['review' => '1']);
        self::assertStringContainsString('Members here voted C (2 of 2).', $page);
        self::assertStringContainsString('1 answers to check', $page);
        self::assertStringContainsString('Check 1', $this->get('/integrity')->body);
        self::assertStringContainsString('posted in the discussion of question 1 of H12-811 (Huawei), voting C', $this->get('/activity')->body);

        // Practice mode keeps it out of the page and shows it with the answer.
        $this->as('mercy');
        self::assertStringNotContainsString('Agreed, C.', $this->reader(['mode' => 'practice']));
        $reveal = json_decode($this->get('/api/library/huawei/h12-811/' . $this->version['version'] . '/questions/1')->body, true);
        self::assertSame('C (2)', $reveal['member_votes']);
        self::assertSame(['Mercy Banda', 'Mercy Banda', 'Mercy Banda', 'John Phiri'], array_column($reveal['member_comments'], 'author'));
        self::assertSame('Agreed, C.', $reveal['member_comments'][3]['body']);
        self::assertStringEndsWith('#discussion-1', $reveal['discuss_url']);
    }

    public function testCommentsAreDeletedByTheirAuthorOrSomeoneWhoCorrectsQuestions(): void
    {
        $this->as('mercy');
        $this->comment('1-2', ['body' => 'Mine.', 'letter_D' => '1']);
        $mine = (int) $this->app->db()->value('SELECT id FROM question_comments');
        $this->as('john');
        $this->comment('1-2', ['body' => 'Johns.']);
        $johns = (int) $this->app->db()->value("SELECT id FROM question_comments WHERE body = 'Johns.'");
        $delete = fn(int $id) => $this->post(self::BASE . '/discussion/' . $id . '/delete', ['version' => $this->version['version'], 'number' => '2']);

        self::assertSame(403, $delete($mine)->status, 'not someone else\'s');
        self::assertSame(1, substr_count($this->reader(), 'class="comment-delete"'), 'a button for his own comment only');
        $this->as('mercy');
        self::assertSame(303, $delete($mine)->status);
        self::assertSame('The comment was deleted, with its vote.', $this->flashes()[0]['message']);
        self::assertStringNotContainsString("Members' votes here", $this->reader());
        $this->as('admin');
        self::assertSame(303, $delete($johns)->status);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM question_comments'));
        self::assertStringContainsString('deleted John Phiri&apos;s comment on question 2', $this->get('/activity')->body);
        self::assertSame(404, $delete(99999)->status);
        self::assertSame(404, $this->comment('9-99', ['body' => 'x'])->status);
    }

    public function testReportsReachThePeopleWhoCorrectQuestions(): void
    {
        $this->as('mercy');
        self::assertStringContainsString('Report a problem', $this->reader(['mode' => 'practice']), 'in both modes');
        $report = fn(array $data) => $this->post(self::BASE . '/reports', ['key' => '1-3', 'version' => $this->version['version'], 'mode' => 'practice'] + $data);
        $report(['body' => 'Wrong']);
        self::assertSame('Question 3: choose what is wrong.', $this->flashes()[0]['message']);
        $r = $report(['kind' => 'answer', 'body' => 'It should be BD, see RFC 2328.']);
        self::assertStringContainsString('mode=practice', $r->headers['Location'], 'back where it was sent from');
        self::assertSame('Thank you. Your report on question 3 was sent to the people who check questions.', $this->flashes()[0]['message']);
        self::assertSame(403, $this->post(self::BASE . '/reports/resolve', ['key' => '1-3', 'version' => $this->version['version']])->status);
        self::assertStringNotContainsString('Reported by a member', $this->reader(), 'only they see reports');

        // The people who correct questions are told, and see it in the reader and on the integrity page.
        $this->as('admin');
        $note = $this->app->db()->one("SELECT * FROM notifications WHERE kind = 'question_report'");
        self::assertSame('Problem reported: H12-811 question 3', $note['title']);
        self::assertStringContainsString('Mercy Banda: The answer is wrong. It should be BD', $note['body']);
        self::assertStringContainsString('Reported (1)', $this->reader());
        $page = $this->reader(['reported' => '1']);
        self::assertStringContainsString('1 reported by members, of 5 questions', $page);
        self::assertStringContainsString('Reported by a member', $page);
        self::assertStringContainsString('It should be BD, see RFC 2328.', $page);
        $integrity = $this->get('/integrity')->body;
        self::assertMatchesRegularExpression('#kpi-value d-block">1</span><span class="kpi-label d-block">Reports from members#', $integrity);
        self::assertStringContainsString('reported=1">', $integrity);

        // Dealt with: gone from the reader and the integrity page, and the member is told.
        $r = $this->post(self::BASE . '/reports/resolve', ['key' => '1-3', 'version' => $this->version['version'], 'number' => '3', 'reported' => '1']);
        self::assertStringContainsString('reported=1', $r->headers['Location']);
        self::assertSame('Question 3: the report was marked dealt with.', $this->flashes()[0]['message']);
        self::assertStringContainsString('No reports waiting', $this->reader(['reported' => '1']));
        self::assertNotNull($this->app->db()->value("SELECT read_at FROM notifications WHERE kind = 'question_report' AND title LIKE 'Problem reported%'"));
        $told = $this->app->db()->one("SELECT n.* FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.username = 'mercy'");
        self::assertSame('Your report on H12-811 question 3 was dealt with', $told['title']);
        self::assertStringContainsString('dealt with a report on question 3 of H12-811', $this->get('/activity')->body);
        self::assertStringContainsString('reported a problem with question 3 of H12-811 (Huawei): the answer is wrong', $this->get('/activity')->body);
    }
}
