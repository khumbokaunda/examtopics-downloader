<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\ObjectiveController;
use App\Services\Access;
use App\Services\Objectives;
use Tests\Support\HttpTestCase;

/** An exam's objectives: the editor, the reader's filter and trust badges, the mock breakdown, the next step. */
final class ObjectivesTest extends HttpTestCase
{
    private const PW = 'member password long';
    private array $version;
    private int $exam;

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
        $this->exam = (int) $this->version['exam_id'];
        $this->s->users()->create('member', self::PW, Access::MEMBER);
    }

    private function objective(string $name): array
    {
        return (array) $this->app->db()->one('SELECT * FROM exam_objectives WHERE exam_id = ? AND name = ?', [$this->exam, $name]);
    }

    private function editorSetsUpTwoObjectives(): void
    {
        $page = $this->get('/library/huawei/h12-811/objectives')->body;
        self::assertStringContainsString('No objectives yet', $page);
        $this->post('/library/huawei/h12-811/objectives', ['action' => 'topics', 'version' => $this->version['version']]);
        self::assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM exam_objectives WHERE exam_id = ?', [$this->exam]));
        $topic = $this->objective('Topic 1');
        self::assertSame(5, (int) $this->app->db()->value('SELECT COUNT(*) FROM question_objectives WHERE objective_id = ?', [(int) $topic['id']]));

        $this->post('/library/huawei/h12-811/objectives', ['action' => 'save', 'version' => $this->version['version'], 'objective' => $topic['uuid'], 'code' => '1.0', 'name' => 'Switching']);
        $this->post('/library/huawei/h12-811/objectives', ['action' => 'save', 'version' => $this->version['version'], 'code' => '2.0', 'name' => '  Routing  ']);
        $this->post('/library/huawei/h12-811/objectives', ['action' => 'assign', 'version' => $this->version['version'], 'objective' => $this->objective('Routing')['uuid'], 'numbers' => '4-5, 99']);
    }

    public function testEditorsSetObjectivesAndPutQuestionsUnderThem(): void
    {
        $this->editorSetsUpTwoObjectives();
        $map = $this->s->objectives()->map($this->exam);
        $switching = (int) $this->objective('Switching')['id'];
        $routing = (int) $this->objective('Routing')['id'];
        self::assertSame([$switching, $switching, $switching, $routing, $routing], [$map['1-1'], $map['1-2'], $map['1-3'], $map['1-4'], $map['1-5']]);

        $page = $this->get('/library/huawei/h12-811/objectives')->body;
        self::assertStringContainsString('2 objectives, 0 of 5 questions without one', $page);

        // Taking questions out of every objective, then removing one.
        $this->post('/library/huawei/h12-811/objectives', ['action' => 'assign', 'version' => $this->version['version'], 'objective' => '', 'numbers' => '5']);
        self::assertArrayNotHasKey('1-5', $this->s->objectives()->map($this->exam));
        $this->post('/library/huawei/h12-811/objectives', ['action' => 'remove', 'version' => $this->version['version'], 'objective' => $this->objective('Routing')['uuid']]);
        self::assertSame(['1-1', '1-2', '1-3'], array_keys($this->s->objectives()->map($this->exam)), 'its questions are left without one');

        // Wrong input is refused with a message, and nothing changes.
        $this->post('/library/huawei/h12-811/objectives', ['action' => 'save', 'version' => $this->version['version'], 'name' => '']);
        $this->post('/library/huawei/h12-811/objectives', ['action' => 'topics', 'version' => $this->version['version']]);
        self::assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM exam_objectives WHERE exam_id = ?', [$this->exam]));

        $actions = array_column($this->app->db()->all("SELECT action FROM audit_log WHERE action LIKE 'exam.objective%'"), 'action');
        self::assertContains('exam.objective_saved', $actions);
        self::assertContains('exam.objectives_assigned', $actions);
        self::assertContains('exam.objective_removed', $actions);

        // Members do not edit objectives.
        $this->newSession();
        $this->login('member', self::PW, 'member');
        self::assertSame(403, $this->get('/library/huawei/h12-811/objectives')->status);
        self::assertSame(403, $this->post('/library/huawei/h12-811/objectives', ['action' => 'topics', 'version' => $this->version['version']])->status);
    }

    public function testQuestionNumbers(): void
    {
        self::assertSame([1, 2, 3, 5, 7, 8, 9], array_keys(ObjectiveController::numbers('1-3, 5 9-7; x 0')));
        self::assertCount(5000, ObjectiveController::numbers('1-99999'));
        self::assertSame([], ObjectiveController::numbers('none'));
    }

    public function testReadersFilterByObjectiveAndSeeWhatIsKnownAboutEachAnswer(): void
    {
        $this->editorSetsUpTwoObjectives();
        $this->s->answerEdits()->save($this->exam, '1-3', 'C', null, (int) $this->s->users()->findByUsername('admin')['id']);
        $this->app->db()->run("INSERT INTO question_reports (exam_id, question_key, kind, body, created_at) VALUES (?, '1-4', 'answer', 'Wrong letter', ?)", [$this->exam, gmdate('Y-m-d H:i:s')]);

        $this->newSession();
        $this->login('member', self::PW, 'member');
        $page = $this->get('/library/huawei/h12-811/view')->body;
        self::assertStringContainsString('All objectives', $page);
        self::assertStringContainsString('Corrected by an editor', $page);
        self::assertStringContainsString('Reported, being looked at', $page);
        self::assertStringContainsString('Switching', $page);

        $routing = $this->get('/library/huawei/h12-811/view', ['objective' => $this->objective('Routing')['uuid']])->body;
        self::assertStringContainsString('id="question-4"', $routing);
        self::assertStringNotContainsString('id="question-1"', $routing);
        self::assertStringContainsString('2 in Routing, of 5 questions', $routing);
        self::assertStringContainsString('name="objective" value="' . $this->objective('Routing')['uuid'] . '"', $routing, 'forms keep the filter');
    }

    public function testTheMockBreakdownAndTheNextStep(): void
    {
        $svc = $this->s->objectives();
        $mock = ['exam_id' => $this->exam, 'keys' => ['1-1', '1-2', '1-3', '1-4', '1-5'],
            'results' => ['1-1' => ['right' => true], '1-2' => ['right' => false], '1-3' => ['right' => true], '1-4' => ['right' => false], '1-5' => ['right' => false]]];
        self::assertNull($svc->breakdown($mock), 'no objectives yet');
        $this->editorSetsUpTwoObjectives();
        $b = $svc->breakdown($mock);
        self::assertSame(100.0, $b['coverage']);
        self::assertSame([['Switching', 2, 3], ['Routing', 0, 2]], array_map(static fn(array $r) => [$r['name'], $r['right'], $r['total']], $b['rows']));
        $this->post('/library/huawei/h12-811/objectives', ['action' => 'assign', 'version' => $this->version['version'], 'objective' => '', 'numbers' => '5']);
        self::assertSame(80.0, $svc->breakdown($mock)['coverage'], '4 of 5 is enough');
        $this->post('/library/huawei/h12-811/objectives', ['action' => 'assign', 'version' => $this->version['version'], 'objective' => '', 'numbers' => '4']);
        self::assertNull($svc->breakdown($mock), '80% of the questions need an objective');
        $this->post('/library/huawei/h12-811/objectives', ['action' => 'assign', 'version' => $this->version['version'], 'objective' => $this->objective('Routing')['uuid'], 'numbers' => '4-5']);

        $member = (int) $this->s->users()->findByUsername('member')['id'];
        $today = gmdate('Y-m-d');
        self::assertSame('Start practising', $svc->nextStep($member, $this->exam, 5, $today)['text']);
        $answer = fn(string $key, int $correct, ?string $due = null) => $this->app->db()->run(
            'INSERT INTO study_answers (user_id, exam_id, question_key, number, picked, correct, first_at, last_at, due_on) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)',
            [$member, $this->exam, $key, 'A', $correct, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $due]);
        $answer('1-1', 1, '2099-01-01');
        self::assertSame('Carry on practising', $svc->nextStep($member, $this->exam, 5, $today)['text']);
        $answer('1-2', 0, '2099-01-01');
        self::assertSame(['mode' => 'practice', 'mistakes' => '1'], $svc->nextStep($member, $this->exam, 5, $today)['query']);
        $answer('1-3', 1, '2000-01-01');
        self::assertSame('Review the 1 question due today', $svc->nextStep($member, $this->exam, 5, $today)['text']);
        $this->app->db()->run('UPDATE study_answers SET correct = 1, due_on = ? WHERE user_id = ?', ['2099-01-01', $member]);
        self::assertTrue($svc->nextStep($member, $this->exam, 5, $today)['mock'], 'most answered and none wrong: a mock');

        // On the exam page and the mock result.
        $this->newSession();
        $this->login('member', self::PW, 'member');
        self::assertStringContainsString('Take a timed mock exam', $this->get('/library/huawei/h12-811')->body);
        $uuid = \App\Core\Ids::uuid();
        $this->app->db()->run('INSERT INTO mock_exams (uuid, user_id, exam_id, version_id, source, pool_size, question_keys, picks, flags, minutes, started_at, ends_at, finished_at, total, right_count, results)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uuid, $member, $this->exam, (int) $this->version['id'], 'all', 5, json_encode(['1-1', '1-2', '1-3', '1-4']), '{}', '[]', 0,
                gmdate('Y-m-d H:i:s', time() - 600), null, gmdate('Y-m-d H:i:s'), 4, 2,
                json_encode(['1-1' => ['picked' => 'A', 'answer' => 'A', 'right' => true, 'number' => 1], '1-2' => ['picked' => 'A', 'answer' => 'B', 'right' => false, 'number' => 2],
                    '1-3' => ['picked' => 'A', 'answer' => 'A', 'right' => true, 'number' => 3], '1-4' => ['picked' => 'A', 'answer' => 'B', 'right' => false, 'number' => 4]])]);
        $result = $this->get('/mock/' . $uuid)->body;
        self::assertStringContainsString('By objective', $result);
        self::assertStringContainsString('2 of 3 (67%)', $result);
        self::assertStringContainsString('objective=' . $this->objective('Routing')['uuid'], $result);
        self::assertSame(['corrected', 'checked', 'reported', 'doubtful', 'unreviewed'], array_keys(Objectives::TRUST));
    }
}
