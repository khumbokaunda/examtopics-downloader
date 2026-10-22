<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use App\Services\AuditText;
use App\Services\Documents\QuestionView;
use Tests\Support\HttpTestCase;

/** Study progress: graded answers, the place to continue from, saved exams, and who sees what. */
final class StudyProgressTest extends HttpTestCase
{
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
        $this->version = $this->s->versions()->find((int) $this->s->jobs()->find($id)['cache_version_id']);
        $this->s->users()->create('member', self::PW, Access::MEMBER, ['display_name' => 'Mercy Member']);
        $this->newSession();
        $this->login('member', self::PW);
    }

    /** @return array{right:list<string>,wrong:string} the answer letters of a question, and one letter that is not */
    private function answer(int $number): array
    {
        foreach ($this->s->cache()->load($this->version)['questions'] as $q) {
            if ((int) $q['number'] === $number) {
                $q = QuestionView::normalize($q);
                $right = str_split(trim((string) $q['answer']));
                $letters = array_column((array) $q['choices'], 'letter');
                return ['right' => $right, 'wrong' => (string) array_values(array_diff($letters, $right))[0]];
            }
        }
        self::fail('no question ' . $number);
    }

    private function attempt(int $number, string $picks): \App\Core\Http\Response
    {
        return $this->post('/api/library/huawei/h12-811/' . $this->version['version'] . '/questions/' . $number . '/attempt', ['picks' => $picks]);
    }

    public function testCheckedAnswersAreGradedOnTheServer(): void
    {
        $q3 = $this->answer(3);
        self::assertSame(['B', 'E'], $q3['right']);
        $r = json_decode($this->attempt(3, 'e, b')->body, true);
        self::assertTrue($r['correct'], 'order, case and separators do not matter');
        self::assertSame(1, $r['totals']['answered']);
        self::assertSame('This exam: 1 of 5 answered, 100% right', $r['line']);

        $q1 = $this->answer(1);
        $r = json_decode($this->attempt(1, $q1['wrong'])->body, true);
        self::assertFalse($r['correct']);
        self::assertSame(['answered' => 2, 'correct' => 1, 'mistakes' => 1], array_intersect_key($r['totals'], ['answered' => 1, 'correct' => 1, 'mistakes' => 1]));
        self::assertFalse(json_decode($this->attempt(3, 'B')->body, true)['correct'], 'half an answer is wrong');

        // Answering again keeps the latest result and counts the tries.
        self::assertTrue(json_decode($this->attempt(1, implode('', $q1['right']))->body, true)['correct']);
        $row = $this->app->db()->one("SELECT * FROM study_answers WHERE question_key = '1-1'");
        self::assertSame([2, 1, 1], [(int) $row['attempts'], (int) $row['wrong_count'], (int) $row['correct']]);

        self::assertSame(400, $this->attempt(2, '')->status, 'nothing picked');
        self::assertSame(404, $this->attempt(99, 'A')->status);

        // A correction by an administrator is what answers are graded against.
        $this->s->answerEdits()->save((int) $this->version['exam_id'], '1-3', 'C', null, (int) $this->s->users()->findByUsername('admin')['id']);
        self::assertTrue(json_decode($this->attempt(3, 'C')->body, true)['correct']);
    }

    public function testMistakesAndThePlaceToContinueFrom(): void
    {
        $this->attempt(1, $this->answer(1)['wrong']);
        $this->attempt(3, 'BE');
        $page = $this->get('/library/huawei/h12-811/view', ['mode' => 'practice'])->body;
        self::assertStringContainsString('This exam: 2 of 5 answered, 50% right', $page);
        self::assertStringContainsString('My mistakes (1)', $page);
        self::assertStringContainsString('/questions/1/attempt', $page);

        $mistakes = $this->get('/library/huawei/h12-811/view', ['mode' => 'practice', 'mistakes' => '1'])->body;
        self::assertStringContainsString('id="question-1"', $mistakes);
        self::assertStringNotContainsString('id="question-3"', $mistakes, 'only what was answered wrong');
        self::assertStringContainsString('1 of your mistakes, of 5 questions', $mistakes);

        // The last page opened (not a search or the mistakes list) is where Continue goes.
        $member = (int) $this->s->users()->findByUsername('member')['id'];
        $place = $this->app->db()->one('SELECT mode, page FROM study_places WHERE user_id = ?', [$member]);
        self::assertSame(['mode' => 'practice', 'page' => 1], ['mode' => $place['mode'], 'page' => (int) $place['page']]);
        $this->get('/library/huawei/h12-811/view', ['q' => 'question']);
        self::assertSame('practice', $this->app->db()->value('SELECT mode FROM study_places WHERE user_id = ?', [$member]), 'a search is not a place');

        $library = $this->get('/library')->body;
        self::assertStringContainsString('Continue studying', $library);
        self::assertStringContainsString('href="/library/huawei/h12-811/view?mode=practice&amp;page=1"', $library);
        self::assertStringContainsString('Mistakes (1)', $library);
        self::assertStringContainsString('2 of 5 answered, 50% right', $library, 'progress on the card');
        self::assertStringNotContainsString('Continue studying', $this->get('/library', ['q' => 'h12'])->body, 'not while searching');
        self::assertStringContainsString('Continue studying', $this->get('/')->body, 'and on the dashboard');

        $exam = $this->get('/library/huawei/h12-811')->body;
        self::assertStringContainsString('Continue, page 1', $exam);
        self::assertStringContainsString('Practise my mistakes', $exam);
        self::assertStringContainsString('Administrators can see your progress.', $exam);

        // Clearing it forgets the answers and the place, and is in the history.
        $this->post('/library/huawei/h12-811/progress/reset');
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM study_answers WHERE user_id = ?', [$member]));
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM study_places WHERE user_id = ?', [$member]));
        $done = $this->app->db()->one("SELECT * FROM audit_log WHERE action = 'exam.progress_reset'");
        self::assertSame('member cleared their own progress in H12-811 (Huawei) (2 answers)', AuditText::describe($done)['text']);
    }

    public function testSavedExamsHaveTheirOwnShelf(): void
    {
        self::assertStringNotContainsString('>Saved <span', $this->get('/library')->body);
        $r = $this->post('/library/huawei/h12-811/save', ['back' => '/library?provider=huawei']);
        self::assertSame('/library?provider=huawei', $r->headers['Location']);
        self::assertSame('H12-811 is saved to your shelf.', $this->flashes()[0]['message']);
        $library = $this->get('/library')->body;
        self::assertMatchesRegularExpression('#Saved <span class="shelf-count" data-shelf-count>1</span>#', $library);
        self::assertStringContainsString('aria-pressed="true"', $library);
        self::assertStringContainsString('aria-pressed="true"', $this->get('/library/huawei/h12-811')->body);

        // Only back to the library, never to another site.
        self::assertSame('/library/huawei/h12-811', $this->post('/library/huawei/h12-811/save', ['back' => '//evil.example/library'])->headers['Location']);
        self::assertDoesNotMatchRegularExpression('#Saved <span class="shelf-count"#', $this->get('/library')->body, 'taken off again');
        self::assertStringNotContainsString('aria-pressed="true"', $this->get('/library')->body);
    }

    public function testAdministratorsSeeEveryonesProgressAndMembersOnlyTheirOwn(): void
    {
        $this->get('/library/huawei/h12-811/view', ['mode' => 'practice']);
        $this->attempt(3, 'BE');
        $this->attempt(1, $this->answer(1)['wrong']);
        $mine = $this->get('/progress')->body;
        self::assertStringContainsString('My progress', $mine);
        self::assertStringContainsString('Administrators can see this page.', $mine);
        self::assertMatchesRegularExpression('#kpi-value d-block">50%<#', $mine);
        self::assertStringContainsString('2 of 5', $mine);
        self::assertStringContainsString('>Continue</a>', $mine);
        self::assertSame(403, $this->get('/progress/everyone')->status);
        self::assertSame(403, $this->get('/progress/people/admin')->status);
        self::assertStringNotContainsString('href="/progress/everyone"', $mine);

        $this->newSession();
        $this->login();
        $everyone = $this->get('/progress/everyone')->body;
        self::assertStringContainsString('Mercy Member', $everyone);
        $member = '/progress/people/' . $this->s->users()->findByUsername('member')['uuid'];
        self::assertStringContainsString('href="' . $member . '"', $everyone, 'by id, not username');
        self::assertStringContainsString('1 of 2 accounts have read or practised an exam', $everyone);
        self::assertSame($member, $this->get('/progress/people/member')->headers['Location'], 'an old link by username moves to the id');
        $theirs = $this->get($member)->body;
        self::assertStringContainsString('Mercy Member\'s progress', $theirs);
        self::assertStringContainsString('2 of 5', $theirs);
        self::assertStringNotContainsString('>Continue</a>', $theirs, 'read only');
        self::assertStringContainsString('Location', implode(',', array_keys($this->get('/progress/people/admin')->headers)), 'their own goes to My progress');
        self::assertSame(404, $this->get('/progress/people/nobody')->status);

        // Deleting the account deletes its progress.
        $id = (int) $this->s->users()->findByUsername('member')['id'];
        $this->app->db()->run('DELETE FROM users WHERE id = ?', [$id]);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM study_answers WHERE user_id = ?', [$id]));
    }
}
