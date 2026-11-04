<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use Tests\Support\HttpTestCase;

/** Every change to a question's corrections is kept, and any earlier state can be put back. */
final class CorrectionHistoryTest extends HttpTestCase
{
    private const BASE = '/library/huawei/h12-811';
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
    }

    private function examId(): int
    {
        return (int) $this->version['exam_id'];
    }

    private function history(string $key = '1-1'): string
    {
        return $this->get(self::BASE . '/corrections/history', ['version' => $this->version['version'], 'key' => $key])->body;
    }

    /** @return list<int> the question's history entries, newest first */
    private function entries(string $key = '1-1'): array
    {
        return array_map(static fn(array $h) => (int) $h['id'], $this->s->correctionHistory()->forQuestion($this->examId(), $key));
    }

    public function testEveryChangeIsKeptAndCanBeUndone(): void
    {
        $v = $this->version['version'];
        $this->post(self::BASE . '/answers', ['key' => '1-1', 'version' => $v, 'letter_C' => '1']);
        $this->post(self::BASE . '/answers', ['key' => '1-1', 'version' => $v, 'letter_A' => '1', 'letter_C' => '1', 'note' => 'C too.']);
        $this->post(self::BASE . '/answers', ['key' => '1-1', 'version' => $v, 'letter_A' => '1', 'letter_C' => '1', 'note' => 'C too.']);
        $this->post(self::BASE . '/answers', ['key' => '1-1', 'version' => $v, 'action' => 'remove']);
        self::assertCount(3, $this->entries(), 'saving the same again is not a change');

        $page = $this->history();
        self::assertStringContainsString('Question 1: history', $page);
        self::assertStringContainsString('Answer AC, note: &quot;C too.&quot;</span> <i class="bi bi-arrow-right" aria-hidden="true"></i> <span class="history-to">No correction', $page);
        self::assertStringContainsString('No correction</span> <i class="bi bi-arrow-right" aria-hidden="true"></i> <span class="history-to">Answer C', $page);
        self::assertSame(1, substr_count($page, 'Step back one edit</button>'));
        self::assertStringContainsString('admin', $page);

        // Step back: the removal is undone, and that is recorded too.
        [$latest, , $first] = $this->entries();
        $r = $this->post(self::BASE . '/corrections/history', ['version' => $v, 'entry' => (string) $latest]);
        self::assertStringContainsString('key=1-1', $r->headers['Location']);
        self::assertSame('Question 1: the answer is back to how it was before that change.', $this->flashes()[0]['message']);
        self::assertSame(['answer' => 'AC', 'note' => 'C too.'], $this->s->answerEdits()->state($this->examId(), '1-1'));
        self::assertCount(4, $this->entries());

        // Back to before the first correction: none at all.
        $this->post(self::BASE . '/corrections/history', ['version' => $v, 'entry' => (string) $first]);
        self::assertSame('Question 1: the answer was taken off, as it was before.', $this->flashes()[0]['message']);
        self::assertNull($this->s->answerEdits()->state($this->examId(), '1-1'));
        self::assertStringContainsString('put back an earlier answer to question 1 of H12-811', $this->get('/activity')->body);
        self::assertSame(404, $this->post(self::BASE . '/corrections/history', ['version' => $v, 'entry' => '999999'])->status);
    }

    public function testQuestionEditsHaveHistoryToo(): void
    {
        $v = $this->version['version'];
        $this->post(self::BASE . '/questions', ['key' => '1-2', 'version' => $v, 'text' => 'Which routing protocol is described?', 'choice_B' => 'BGP-4']);
        $this->post(self::BASE . '/answers', ['key' => '1-2', 'version' => $v, 'letter_B' => '1']);
        self::assertStringContainsString('History (2)', $this->get(self::BASE . '/corrections', ['version' => $v])->body);
        self::assertStringContainsString("History of this question's corrections (2)", $this->get(self::BASE . '/view', ['version' => $v])->body);

        $this->post(self::BASE . '/questions', ['key' => '1-2', 'version' => $v, 'action' => 'reset']);
        $page = $this->history('1-2');
        self::assertStringContainsString('Edited: wording: &quot;Which routing protocol is described?&quot;; choices B</span>', $page);
        self::assertStringContainsString('As collected', $page);
        $this->post(self::BASE . '/corrections/history', ['version' => $v, 'entry' => (string) $this->entries('1-2')[0]]);
        self::assertSame('Which routing protocol is described?', $this->s->questionEdits()->state($this->examId(), '1-2')['text']);
        self::assertSame(['B' => 'BGP-4'], $this->s->questionEdits()->state($this->examId(), '1-2')['choices']);
    }

    public function testOnlyPeopleWhoCorrectQuestionsSeeHistory(): void
    {
        $this->post(self::BASE . '/answers', ['key' => '1-1', 'version' => $this->version['version'], 'letter_C' => '1']);
        $this->s->users()->create('member', 'member password long', Access::MEMBER);
        $this->newSession();
        $this->login('member', 'member password long');
        self::assertSame(403, $this->get(self::BASE . '/corrections/history', ['key' => '1-1'])->status);
        self::assertSame(403, $this->post(self::BASE . '/corrections/history', ['entry' => (string) $this->entries()[0]])->status);
    }
}
