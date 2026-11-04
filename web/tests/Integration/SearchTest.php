<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use Tests\Support\HttpTestCase;

/** Search across the whole library, kept up to date by the background check. */
final class SearchTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        foreach (['h12-811', 'h19-301'] as $code) {
            $this->s->jobService()->enqueueCollect($this->provider('huawei'), $code, ['source_mode' => 'live', 'rps' => 50.0]);
            $this->drain();
        }
        $this->s->users()->create('mercy', 'member password long', Access::MEMBER);
        $this->newSession();
        $this->login('mercy', 'member password long');
    }

    public function testEveryExamIsSearchedByWordsInQuestionsAndChoices(): void
    {
        self::assertStringContainsString('href="/search"', $this->get('/')->body, 'in the menu');
        self::assertStringContainsString('Find a question anywhere', $this->get('/search')->body);

        $page = $this->get('/search', ['q' => 'Which PROTOCOL described'])->body;
        self::assertStringContainsString('8 questions, in 2 exams.', $page);
        self::assertStringContainsString('HCIA-Datacom V1.0', $page);
        self::assertStringContainsString('<mark>protocol</mark>', $page);
        self::assertStringContainsString('href="/library/huawei/h12-811/view?jump=3#question-3">Question 3</a>', $page);
        self::assertStringContainsString('mode=practice&amp;jump=3#question-3', $page);

        // Every word must appear; choices count too.
        self::assertStringContainsString('3 questions, in 1 exam.', $this->get('/search', ['q' => 'h19-301 eigrp'])->body);
        self::assertStringContainsString('No questions match', $this->get('/search', ['q' => 'protocol zebra'])->body);
        self::assertStringContainsString('Each word needs at least two letters.', $this->get('/search', ['q' => 'a b'])->body);
        // What is typed is never markup or a pattern.
        self::assertStringContainsString('No questions match', $this->get('/search', ['q' => '%_ <script>'])->body);
        self::assertStringNotContainsString('<script>', $this->get('/search', ['q' => '<script>'])->body);
    }

    public function testCorrectedWordingIsWhatIsFound(): void
    {
        $exam = $this->s->exams()->find((int) $this->provider('huawei')['id'], 'h12-811');
        $this->s->questionEdits()->save((int) $exam['id'], '1-2', 'Which zebra routing protocol is described?', null, null, null, null);
        $page = $this->get('/search', ['q' => 'zebra'])->body;
        self::assertStringContainsString('1 question, in 1 exam.', $page);
        self::assertStringContainsString('Which <mark>zebra</mark> routing', $page);
        self::assertStringContainsString('No questions match', $this->get('/search', ['q' => 'h12-811 question 2: which protocol'])->body,
            'the wording it replaced is no longer found');
    }
}
