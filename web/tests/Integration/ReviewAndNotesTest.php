<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use App\Services\StudyProgress;
use Tests\Support\HttpTestCase;

/** Spaced review (questions due again), and a person's own notes and bookmarks on questions. */
final class ReviewAndNotesTest extends HttpTestCase
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
        $this->newSession();
        $this->login('mercy', self::PW);
    }

    private function attempt(int $n, string $picks): array
    {
        return json_decode($this->post('/api/library/huawei/h12-811/' . $this->version['version'] . '/questions/' . $n . '/attempt', ['picks' => $picks])->body, true);
    }

    private function row(string $key): array
    {
        return (array) $this->app->db()->one('SELECT box, due_on FROM study_answers WHERE question_key = ?', [$key]);
    }

    public function testTheScheduleSpacesReviewsOut(): void
    {
        $day = '2026-11-04';
        self::assertSame([1, '2026-11-06'], StudyProgress::schedule(null, true, $day), 'right the first time: in two days');
        self::assertSame([0, '2026-11-05'], StudyProgress::schedule(null, false, $day), 'wrong: tomorrow');
        self::assertSame([3, '2026-11-12'], StudyProgress::schedule(['box' => 2, 'due_on' => '2026-11-04'], true, $day), 'due and right: up a box');
        self::assertSame([5, '2026-12-06'], StudyProgress::schedule(['box' => 5, 'due_on' => '2026-11-01'], true, $day), 'the top box stays');
        self::assertSame([2, '2026-11-08'], StudyProgress::schedule(['box' => 2, 'due_on' => '2026-11-08'], true, $day), 'early: the schedule stays');
        self::assertSame([0, '2026-11-05'], StudyProgress::schedule(['box' => 4, 'due_on' => '2026-11-20'], false, $day), 'wrong at any time: back to the start');
    }

    public function testQuestionsComeBackWhenDue(): void
    {
        $this->get(self::BASE . '/view', ['mode' => 'practice']);
        self::assertTrue($this->attempt(1, 'A')['correct']);
        self::assertFalse($this->attempt(2, 'A')['correct']);
        self::assertSame(1, (int) $this->row('1-1')['box']);
        self::assertSame(0, (int) $this->row('1-2')['box']);
        self::assertStringNotContainsString('Due today', $this->get(self::BASE . '/view', ['mode' => 'practice'])->body, 'nothing due yet');

        // A few days on, both are due: listed in practice mode and on the dashboard.
        $this->app->db()->run('UPDATE study_answers SET due_on = DATE_SUB(due_on, INTERVAL 3 DAY)');
        $practice = $this->get(self::BASE . '/view', ['mode' => 'practice'])->body;
        self::assertStringContainsString('Due today (2)', $practice);
        $due = $this->get(self::BASE . '/view', ['mode' => 'practice', 'due' => '1'])->body;
        self::assertStringContainsString('2 due for review today, of 5 questions', $due);
        self::assertStringContainsString('id="question-1"', $due);
        self::assertStringNotContainsString('id="question-3"', $due);
        $dash = $this->get('/')->body;
        self::assertStringContainsString('Due for review today', $dash);
        self::assertStringContainsString('2 questions to go over again', $dash);
        self::assertStringContainsString('mode=practice&amp;due=1', $dash);

        // Answered right when due, a question moves on; the other is still due.
        $this->attempt(1, 'A');
        self::assertSame(2, (int) $this->row('1-1')['box']);
        self::assertStringContainsString('Due today (1)', $this->get(self::BASE . '/view', ['mode' => 'practice'])->body);
        // And a mock exam can be made of them.
        self::assertStringContainsString('Due for review today <span class="text-muted">(1)</span>', $this->get(self::BASE . '/mock')->body);
    }

    public function testNotesAndBookmarksArePrivate(): void
    {
        $r = $this->post(self::BASE . '/notes', ['key' => '1-3', 'number' => '3', 'version' => $this->version['version'], 'note' => "BE: remember the two\nprotocols.", 'mode' => 'practice']);
        self::assertStringContainsString('mode=practice', $r->headers['Location']);
        self::assertSame('Question 3: your note was saved. Only you can see it.', $this->flashes()[0]['message']);
        $page = $this->get(self::BASE . '/view', ['mode' => 'practice'])->body;
        self::assertStringContainsString('Your note</span><p>BE: remember the two<br>', $page);
        self::assertStringContainsString('Change your note', $page);

        $on = $this->postJson(self::BASE . '/notes/bookmark', ['key' => '1-3', 'number' => '3', 'version' => $this->version['version']]);
        self::assertTrue($on['json']['saved']);
        $page = $this->get(self::BASE . '/view')->body;
        self::assertStringContainsString('Bookmarked (1)', $page);
        self::assertStringContainsString('aria-pressed="true"', $page);
        $marked = $this->get(self::BASE . '/view', ['bookmarked' => '1'])->body;
        self::assertStringContainsString('1 bookmarked, of 5 questions', $marked);
        self::assertStringContainsString('id="question-3"', $marked);
        self::assertStringContainsString('My bookmarked questions <span class="text-muted">(1)</span>', $this->get(self::BASE . '/mock')->body);

        // Someone else sees none of it.
        $this->newSession();
        $this->login('john', self::PW);
        $page = $this->get(self::BASE . '/view')->body;
        self::assertStringNotContainsString('remember the two', $page);
        self::assertStringNotContainsString('Bookmarked (', $page);

        // Taken off and emptied, nothing is kept.
        $this->newSession();
        $this->login('mercy', self::PW);
        self::assertFalse($this->postJson(self::BASE . '/notes/bookmark', ['key' => '1-3', 'version' => $this->version['version']])['json']['saved']);
        $this->post(self::BASE . '/notes', ['key' => '1-3', 'number' => '3', 'version' => $this->version['version'], 'note' => '  ']);
        self::assertSame('Question 3: your note was removed.', $this->flashes()[0]['message']);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM question_notes'));
        self::assertSame(404, $this->post(self::BASE . '/notes', ['key' => '../x', 'note' => 'x'])->status);
    }
}
