<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;

/** Carrying an exam's corrections to its other editions, after seeing what it would do. */
final class EditionCarryTest extends HttpTestCase
{
    private const BASE = '/library/huawei/h12-811';
    private array $versions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        foreach (['h12-811', 'h12-811-v2-0'] as $code) {
            $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), $code, ['source_mode' => 'live', 'rps' => 50.0]);
            $this->drain();
            $this->versions[$code] = (array) $this->s->versions()->find((int) $this->s->jobs()->find($id)['cache_version_id']);
        }
        // The two share a card, as an administrator grouped them.
        $this->app->db()->run("UPDATE exams SET edition_group = 'huawei/hcia datacom' WHERE code IN ('h12-811', 'h12-811-v2-0')");
    }

    private function examId(string $code): int
    {
        return (int) $this->versions[$code]['exam_id'];
    }

    public function testCorrectionsAreCarriedAfterSeeingWhatWouldHappen(): void
    {
        $v = $this->versions['h12-811']['version'];
        $this->post(self::BASE . '/answers', ['key' => '1-1', 'version' => $v, 'letter_C' => '1', 'note' => 'C, see the RFC.']);
        $this->post(self::BASE . '/answers', ['key' => '1-2', 'version' => $v, 'letter_D' => '1']);
        $this->post(self::BASE . '/answers', ['key' => '1-3', 'version' => $v, 'note' => 'Hard one.']);
        $this->post(self::BASE . '/questions', ['key' => '1-1', 'version' => $v, 'text' => 'Which routing protocol is described?']);
        // The other edition already has its own answer for its question 2.
        $this->s->answerEdits()->save($this->examId('h12-811-v2-0'), '1-2', 'B', null, null);

        self::assertStringContainsString('Carry to other editions (1)', $this->get(self::BASE . '/corrections', ['version' => $v])->body);
        $page = $this->get(self::BASE . '/corrections/editions', ['version' => $v])->body;
        // Question 1 is there with its exam code in the wording, so nearly the same.
        self::assertMatchesRegularExpression('#Question 1</a> <span class="text-muted small" title="Most of its words are the same">\(nearly the same\)#', $page);
        self::assertStringContainsString('Answer C, with a note', $page);
        self::assertStringContainsString('Will be added', $page);
        self::assertStringContainsString('Its wording differs', $page, 'the question edit replaces wording the other edition does not have');
        self::assertStringContainsString('Answer B; tick to replace it', $page);
        self::assertStringContainsString('Not found there', $page, 'question 3 is not in the other edition');
        self::assertSame(1, substr_count($page, '" checked'), 'only what would be added is ticked');
        self::assertSame(2, substr_count($page, 'name="carry_'), 'only what can be carried can be ticked');

        // Carry what was ticked: the answer is added, the other edition's own answer stays.
        preg_match_all('#name="(carry_\d+)" value="([^"]+)"([^>]*)>#', $page, $m, PREG_SET_ORDER);
        $form = ['version' => $v];
        foreach ($m as [, $name, $value, $rest]) {
            if (str_contains($rest, 'checked')) {
                $form[$name] = html_entity_decode($value);
            }
        }
        $r = $this->post(self::BASE . '/corrections/editions', $form);
        self::assertStringContainsString('/corrections', $r->headers['Location']);
        self::assertSame('1 correction was carried to H12-811-V2-0 (1).', $this->flashes()[0]['message']);
        $there = $this->s->answerEdits()->forExam($this->examId('h12-811-v2-0'));
        self::assertSame(['C', 'C, see the RFC.'], [$there['1-1']['answer'], $there['1-1']['note']]);
        self::assertSame('B', $there['1-2']['answer']);
        self::assertStringContainsString('C (corrected; the collected answer was A)', $this->get('/library/huawei/h12-811-v2-0/view')->body);
        self::assertStringContainsString('carried a correction of H12-811 (Huawei) to H12-811-V2-0', $this->get('/activity')->body);

        // Carried again, it is already the same.
        self::assertStringContainsString('Already the same', $this->get(self::BASE . '/corrections/editions', ['version' => $v])->body);
    }

    public function testMembersCannotCarryCorrections(): void
    {
        $this->s->users()->create('member', 'member password long', \App\Services\Access::MEMBER);
        $this->newSession();
        $this->login('member', 'member password long');
        self::assertSame(403, $this->get(self::BASE . '/corrections/editions')->status);
        self::assertSame(403, $this->post(self::BASE . '/corrections/editions', [])->status);
    }
}
