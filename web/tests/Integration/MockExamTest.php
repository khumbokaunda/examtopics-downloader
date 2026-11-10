<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use App\Services\Documents\QuestionView;
use App\Services\MockExams;
use Tests\Support\HttpTestCase;

/** Timed mock exams: no answers in the page, picks saved as they go, graded on the server. */
final class MockExamTest extends HttpTestCase
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
        $this->s->users()->create('mercy', self::PW, Access::MEMBER, ['display_name' => 'Mercy Banda']);
        $this->newSession();
        $this->login('mercy', self::PW);
    }

    /** @return array<string,string> question key => its answer letters */
    private function answers(): array
    {
        $out = [];
        $data = $this->s->cache()->load($this->version);
        foreach ($this->s->corrections()->apply((int) $this->version['exam_id'], (array) $data['questions']) as $q) {
            $out[QuestionView::key($q)] = MockExams::answerOf($q);
        }
        return $out;
    }

    /** Start one and return it. */
    private function start(array $data = []): array
    {
        $r = $this->post(self::BASE . '/mock', $data + ['version' => $this->version['version'], 'source' => 'all', 'count' => '3', 'timed' => '1', 'minutes' => '10']);
        self::assertSame(303, $r->status);
        self::assertMatchesRegularExpression('#^/mock/[0-9a-f-]{36}$#', $r->headers['Location']);
        return (array) $this->s->mocks()->find(substr($r->headers['Location'], 6));
    }

    public function testAMockExamIsTakenWithoutAnswersAndGradedOnTheServer(): void
    {
        $setup = $this->get(self::BASE . '/mock')->body;
        self::assertStringContainsString('All questions <span class="text-muted">(5)</span>', $setup);
        self::assertStringContainsString('My mistakes <span class="text-muted">(0)</span>', $setup);
        self::assertStringContainsString('No mock exams of this exam yet.', $setup);
        self::assertStringContainsString('href="' . self::BASE . '/mock"', $this->get(self::BASE . '/view')->body, 'offered in the reader');

        $mock = $this->start();
        self::assertCount(3, $mock['keys']);
        self::assertSame(10, (int) $mock['minutes']);
        self::assertSame(['all', 5], [$mock['source'], (int) $mock['pool_size']], 'where the questions came from, and out of how many, is kept for readiness');
        $page = $this->get('/mock/' . $mock['uuid'])->body;
        self::assertStringContainsString('Question 1 of 3', $page);
        self::assertStringContainsString('data-ends-in="', $page);
        foreach (['Answer:', 'is-answer', 'Community votes', 'I think it is', 'answer_images', 'Discussion ('] as $hidden) {
            self::assertStringNotContainsString($hidden, $page, 'no answers in the page: ' . $hidden);
        }

        // Picks are saved as they are made, and come back on a reload.
        [$k0, $k1, $k2] = $mock['keys'];
        $answers = $this->answers();
        $saved = $this->postJson('/api/mock/' . $mock['uuid'] . '/answer', ['key' => $k0, 'picks' => $answers[$k0], 'flagged' => '1']);
        self::assertTrue($saved['json']['saved']);
        $page = $this->get('/mock/' . $mock['uuid'])->body;
        self::assertStringContainsString('1 of 3 answered', $page);
        self::assertSame(1, substr_count($page, 'is-flagged'));
        self::assertSame(strlen($answers[$k0]), substr_count($page, '" checked>'));
        self::assertSame(404, $this->postJson('/api/mock/' . $mock['uuid'] . '/answer', ['key' => '9-99', 'picks' => 'A'])['status']);

        // A second one waits until this one is done.
        $this->post(self::BASE . '/mock', ['version' => $this->version['version'], 'source' => 'all', 'count' => '3']);
        self::assertStringContainsString('current mock exam', $this->flashes()[0]['message']);

        // Handed in: one right, one wrong, one left out. The form is what counts.
        $wrong = $answers[$k1] === 'A' ? 'B' : 'A';
        $fields = [];
        foreach (str_split($answers[$k0]) as $l) {
            $fields['pick_0_' . $l] = '1';
        }
        $fields['pick_1_' . $wrong] = '1';
        $r = $this->post('/mock/' . $mock['uuid'] . '/finish', $fields);
        self::assertSame('/mock/' . $mock['uuid'], $r->headers['Location']);
        $done = (array) $this->s->mocks()->find($mock['uuid']);
        self::assertSame(1, (int) $done['right_count']);
        self::assertSame(['picked' => $wrong, 'answer' => $answers[$k1], 'right' => false], array_diff_key($done['results'][$k1], ['number' => 0]));
        self::assertSame('', $done['results'][$k2]['picked']);

        $result = $this->get('/mock/' . $mock['uuid'])->body;
        self::assertStringContainsString('33%: 1 of 3 right', $result);
        self::assertStringContainsString('your pick', $result);
        self::assertStringContainsString('Wrong or not answered <span class="filter-chip-count">2</span>', $result);
        self::assertSame(1, substr_count($this->get('/mock/' . $mock['uuid'], ['show' => 'unanswered'])->body, 'class="card question-card mock-result"'));

        // The answers count towards progress, as practice answers do; the unanswered one does not.
        $mine = $this->s->progress()->forExam((int) $this->s->users()->findByUsername('mercy')['id'], (int) $this->version['exam_id']);
        self::assertSame([2, 1, 1], [$mine['answered'], $mine['correct'], $mine['mistakes']]);
        self::assertStringContainsString('My mistakes <span class="text-muted">(1)</span>', $this->get(self::BASE . '/mock')->body);
        self::assertStringContainsString('Mock exams', $this->get('/progress')->body);

        // Handing it in again changes nothing.
        $this->post('/mock/' . $mock['uuid'] . '/finish', []);
        self::assertSame(1, (int) $this->s->mocks()->find($mock['uuid'])['right_count']);
    }

    public function testTheTimeLimitIsTheServers(): void
    {
        $mock = $this->start();
        $k0 = $mock['keys'][0];
        $answer = $this->answers()[$k0];
        $this->postJson('/api/mock/' . $mock['uuid'] . '/answer', ['key' => $k0, 'picks' => $answer]);
        // Past the time and its grace: nothing more is saved, and the form sent now is not believed.
        $this->app->db()->run('UPDATE mock_exams SET ends_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - MockExams::GRACE - 5), (int) $mock['id']]);
        self::assertSame(409, $this->postJson('/api/mock/' . $mock['uuid'] . '/answer', ['key' => $mock['keys'][1], 'picks' => 'A'])['status']);
        $fields = ['pick_1_A' => '1', 'pick_1_B' => '1', 'pick_2_A' => '1'];
        $this->post('/mock/' . $mock['uuid'] . '/finish', $fields);
        $done = (array) $this->s->mocks()->find($mock['uuid']);
        self::assertSame(1, (int) $done['right_count'], 'graded from what was saved in time');
        self::assertSame('', $done['results'][$mock['keys'][1]]['picked']);

        // Left open past its time, it is finished when next opened.
        $other = $this->start();
        $this->app->db()->run('UPDATE mock_exams SET ends_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - MockExams::GRACE - 5), (int) $other['id']]);
        self::assertStringContainsString('The time ran out, so your saved answers were graded.', $this->get('/mock/' . $other['uuid'])->body);
        self::assertNotNull($this->s->mocks()->find($other['uuid'])['finished_at']);
    }

    public function testAttemptsArePrivateAndCanBeGivenUp(): void
    {
        $mock = $this->start(['timed' => '']);
        self::assertSame(0, (int) $mock['minutes']);
        self::assertNull($mock['ends_at']);
        self::assertStringContainsString('No time limit', $this->get('/mock/' . $mock['uuid'])->body);

        $this->newSession();
        $this->login();
        self::assertSame(404, $this->get('/mock/' . $mock['uuid'])->status, 'not even an administrator opens someone else\'s');
        self::assertSame(404, $this->postJson('/api/mock/' . $mock['uuid'] . '/answer', ['key' => $mock['keys'][0], 'picks' => 'A'])['status']);
        self::assertSame(404, $this->get('/mock/not-an-id')->status);

        $this->newSession();
        $this->login('mercy', self::PW);
        $this->post(self::BASE . '/mock', ['version' => $this->version['version'], 'source' => 'mistakes', 'count' => '3']);
        self::assertStringContainsString('current mock exam', $this->flashes()[0]['message']);
        $r = $this->post('/mock/' . $mock['uuid'] . '/discard');
        self::assertSame(self::BASE . '/mock', $r->headers['Location']);
        self::assertSame('The mock exam was given up. Nothing from it was counted.', $this->flashes()[0]['message']);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM mock_exams'));
        $this->post(self::BASE . '/mock', ['version' => $this->version['version'], 'source' => 'mistakes', 'count' => '3']);
        self::assertStringContainsString('There are no questions to ask from "My mistakes"', $this->flashes()[0]['message']);
    }
}
