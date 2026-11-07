<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** Building assessments: settings, chosen and drawn questions, assignment, readiness, publishing and the drawn form. */
final class AssessmentsTest extends HttpTestCase
{
    use InstitutionFixtures;

    private string $a;
    private string $bank;
    private string $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->people('fay');
        $this->a = $this->institution('olive', 'Lilongwe ICT Academy');
        $this->join($this->a, 'olive', 'bob', 'instructor');
        $this->join($this->a, 'olive', 'dan', 'instructor');
        $this->join($this->a, 'olive', 'erin', 'exam_officer');
        $this->join($this->a, 'olive', 'carol', 'student');

        // A class taught by bob, with carol in it.
        $this->as('olive');
        $this->post('/institutions/' . $this->a . '/classes', ['name' => 'CCNA 1']);
        $this->class = (string) $this->db('SELECT uuid FROM institution_classes');
        $this->post('/institutions/' . $this->a . '/classes/' . $this->class . '/instructors', ['instructors' => [$this->memberUuid($this->a, 'bob')]]);
        $this->post('/institutions/' . $this->a . '/classes/' . $this->class . '/students', ['students' => [$this->memberUuid($this->a, 'carol')]]);

        // Bob's bank: six published questions (four easy on Routing), one draft.
        $this->as('bob');
        $this->post('/institutions/' . $this->a . '/banks', ['name' => 'CCNA 1 bank']);
        $this->bank = (string) $this->db('SELECT uuid FROM question_banks');
        $bank = '/institutions/' . $this->a . '/banks/' . $this->bank;
        $this->post($bank . '/topics', ['name' => 'Routing']);
        $topic = (string) $this->db('SELECT uuid FROM bank_topics');
        for ($n = 1; $n <= 7; $n++) {
            $this->post($bank . '/questions', ['type' => 'single', 'text' => 'Question ' . $n, 'choice_text' => ['Right ' . $n, 'Wrong ' . $n, 'Also wrong ' . $n],
                'correct' => ['0'], 'marks' => (string) $n, 'difficulty' => $n <= 4 ? 'easy' : 'hard', 'topic' => $n <= 4 ? $topic : '']);
        }
        $six = $this->app->db()->all('SELECT uuid FROM bank_questions ORDER BY id LIMIT 6');
        foreach ($six as $q) {
            $this->post($bank . '/questions/' . $q['uuid'] . '/status', ['action' => 'submit']);
        }
        $this->as('erin');
        foreach ($six as $q) {
            $this->post($bank . '/questions/' . $q['uuid'] . '/status', ['action' => 'publish']);
        }
        $this->as('bob');
    }

    private function db(string $sql, array $args = []): mixed
    {
        return $this->app->db()->value($sql, $args);
    }

    private function lastFlash(): string
    {
        $f = $this->flashes();
        return (string) (end($f)['message'] ?? '');
    }

    /** Create an assessment as the signed-in person. @return string its path */
    private function assessment(string $title = 'Module test'): string
    {
        $this->post('/institutions/' . $this->a . '/assessments', ['title' => $title, 'type' => 'summative']);
        return '/institutions/' . $this->a . '/assessments/' . $this->db('SELECT uuid FROM assessments WHERE title = ?', [$title]);
    }

    /** @return array<string,string> */
    private function settings(array $extra = []): array
    {
        return $extra + ['title' => 'Module test', 'type' => 'summative', 'description' => '', 'instructions' => 'Answer every question.',
            'duration_minutes' => '45', 'pass_percent' => '50', 'max_attempts' => '1', 'opens_at' => '', 'closes_at' => '',
            'shuffle_questions' => '1', 'shuffle_choices' => '1', 'negative_marking' => '0', 'review_policy' => 'answers'];
    }

    private function questionUuid(int $n): string
    {
        return (string) $this->db('SELECT q.uuid FROM bank_questions q JOIN question_versions v ON v.question_id = q.id AND v.version = 1 WHERE v.text = ?', ['Question ' . $n]);
    }

    public function testBuildingCheckingAndPublishing(): void
    {
        $path = $this->assessment();
        $this->post($path . '/settings', $this->settings(['duration_minutes' => '0']));
        self::assertStringContainsString('1 to 600 minutes', $this->lastFlash());
        $this->post($path . '/settings', $this->settings(['opens_at' => '2030-01-02T10:00', 'closes_at' => '2030-01-02T09:00']));
        self::assertStringContainsString('close after it opens', $this->lastFlash());
        $this->post($path . '/settings', $this->settings(['opens_at' => '2030-01-02T10:00', 'closes_at' => '2030-01-02T12:00']));
        self::assertSame('2030-01-02 10:00:00', $this->db('SELECT opens_at FROM assessments'), 'times are kept in UTC (the test timezone is UTC)');

        self::assertSame(200, $this->get($path . '/add')->status);
        $this->post($path . '/questions', ['bank' => $this->bank, 'questions' => [$this->questionUuid(7)]]);
        self::assertStringContainsString('Only published questions', $this->lastFlash());
        $this->post($path . '/questions', ['bank' => $this->bank, 'questions' => [$this->questionUuid(5), $this->questionUuid(6)]]);
        self::assertSame(2, (int) $this->db('SELECT COUNT(*) FROM assessment_items'));
        $this->post($path . '/blueprint', ['bank' => $this->bank, 'topic' => (string) $this->db('SELECT uuid FROM bank_topics'), 'difficulty' => 'easy', 'count' => '5']);

        $page = $this->get($path)->body;
        self::assertStringContainsString('Not enough published questions: CCNA 1 bank, Routing, easy needs 5, has 4', $page);
        self::assertStringContainsString('assigned to at least one class', $page);
        $this->post($path . '/status', ['action' => 'publish']);
        self::assertSame(403, $this->post($path . '/status', ['action' => 'publish'])->status, 'an instructor does not publish');

        $this->post($path . '/blueprint/' . (int) $this->db('SELECT id FROM assessment_blueprints') . '/remove');
        $this->post($path . '/blueprint', ['bank' => $this->bank, 'topic' => (string) $this->db('SELECT uuid FROM bank_topics'), 'difficulty' => 'easy', 'count' => '3']);
        $this->post($path . '/assignments', ['class' => $this->class]);
        self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM assessment_assignments'));
        self::assertStringContainsString('<span class="kpi-value d-block">1</span><span class="kpi-label d-block">Candidates now', $this->get($path)->body);

        $this->as('erin');
        $this->post($path . '/status', ['action' => 'publish']);
        self::assertSame('published', $this->db('SELECT status FROM assessments'));
        $pinned = $this->app->db()->all('SELECT question_version_id FROM assessment_items ORDER BY position');
        self::assertNotNull($pinned[0]['question_version_id']);
        self::assertSame(403, $this->post($path . '/settings', $this->settings(['title' => 'Changed']))->status, 'a published assessment is fixed');

        // A new published version of a chosen question does not change the published assessment.
        $this->as('bob');
        $q5 = '/institutions/' . $this->a . '/banks/' . $this->bank . '/questions/' . $this->questionUuid(5);
        $this->post($q5, ['type' => 'single', 'text' => 'Question 5, reworded', 'choice_text' => ['Right 5', 'Wrong 5', 'Also wrong 5'], 'correct' => ['0'], 'marks' => '5', 'difficulty' => 'hard']);
        $this->post($q5 . '/status', ['action' => 'submit']);
        $this->as('erin');
        $this->post($q5 . '/status', ['action' => 'publish']);
        self::assertSame($pinned, $this->app->db()->all('SELECT question_version_id FROM assessment_items ORDER BY position'));
        self::assertStringNotContainsString('reworded', $this->get($path)->body);

        $this->post($path . '/schedule', ['opens_at' => '2030-01-02T10:00', 'closes_at' => '2030-01-03T12:00']);
        self::assertSame('2030-01-03 12:00:00', $this->db('SELECT closes_at FROM assessments'));
        $this->post($path . '/status', ['action' => 'close']);
        $this->post($path . '/status', ['action' => 'reopen']);
        self::assertSame('published', $this->db('SELECT status FROM assessments'));
        $this->post($path . '/status', ['action' => 'unpublish']);
        self::assertSame(['draft', null], [$this->db('SELECT status FROM assessments'), $this->db('SELECT MAX(question_version_id) FROM assessment_items')]);
        $this->as('olive');
        self::assertStringContainsString('published the assessment Module test', $this->get('/institutions/' . $this->a . '/activity')->body);
    }

    public function testTheDrawnForm(): void
    {
        $path = $this->assessment();
        $this->post($path . '/questions', ['bank' => $this->bank, 'questions' => [$this->questionUuid(1)]]);
        $this->post($path . '/blueprint', ['bank' => $this->bank, 'topic' => '', 'difficulty' => 'easy', 'count' => '3']);
        $i = $this->app->db()->one('SELECT * FROM institutions WHERE uuid = ?', [$this->a]);
        $a = $this->app->db()->one('SELECT * FROM assessments');
        $svc = $this->s->assessments();

        $one = $svc->draw($i, $a, 42);
        self::assertSame($one, $svc->draw($i, $a, 42), 'the same seed draws the same form');
        self::assertCount(4, $one);
        $texts = array_map(static fn(array $f): string => (string) $f['version']['text'], $one);
        self::assertContains('Question 1', $texts);
        self::assertSame(1, count(array_keys($texts, 'Question 1')), 'a chosen question is never drawn again');
        foreach ($one as $f) {
            self::assertSame('easy', $f['version']['difficulty']);
            self::assertEqualsCanonicalizing(['A', 'B', 'C'], $f['choice_order']);
        }
        $orders = [];
        for ($seed = 1; $seed <= 20; $seed++) {
            $orders[implode(',', array_map(static fn(array $f): string => (string) $f['version']['id'] . implode('', $f['choice_order']), $svc->draw($i, $a, $seed)))] = true;
        }
        self::assertGreaterThan(1, count($orders), 'forms differ between candidates');

        $preview = $this->get($path . '/preview', ['seed' => '42']);
        self::assertSame(200, $preview->status);
        self::assertStringContainsString('One candidate\'s form: 4 questions', $preview->body);
    }

    public function testWhoMayBuildAssignAndSee(): void
    {
        $path = $this->assessment();
        $this->as('dan');
        self::assertSame(404, $this->get($path)->status, 'another instructor does not see it');
        $this->post('/institutions/' . $this->a . '/assessments', ['title' => 'Dan test', 'type' => 'practice']);
        $dan = '/institutions/' . $this->a . '/assessments/' . $this->db('SELECT uuid FROM assessments WHERE title = ?', ['Dan test']);
        $this->post($dan . '/assignments', ['class' => $this->class]);
        self::assertStringContainsString('only the classes you teach', $this->lastFlash());
        $this->post($dan . '/questions', ['bank' => $this->bank, 'questions' => [$this->questionUuid(1)]]);
        self::assertSame(404, $this->post($dan . '/questions', ['bank' => $this->bank, 'questions' => [$this->questionUuid(1)]])->status, 'a bank he cannot see');

        $this->as('erin');
        self::assertSame(200, $this->get($path)->status, 'an examination officer sees every assessment');
        $this->post($path . '/assignments', ['student' => $this->memberUuid($this->a, 'carol')]);
        self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM assessment_assignments WHERE membership_id IS NOT NULL'));

        $this->as('carol');
        self::assertSame(403, $this->get('/institutions/' . $this->a . '/assessments')->status);
        self::assertSame(404, $this->get($path)->status);
        self::assertSame(404, $this->get($path . '/preview')->status, 'students never see the questions or answers here');
    }

    public function testAssessmentsStayInsideTheirInstitution(): void
    {
        $path = $this->assessment();
        $b = $this->institution('fay', 'Mzuzu Academy');
        $this->as('fay');
        self::assertSame(404, $this->get(str_replace($this->a, $b, $path))->status);
        self::assertSame(404, $this->get($path)->status);
        $this->post('/institutions/' . $b . '/assessments', ['title' => 'Theirs', 'type' => 'practice']);
        $theirs = '/institutions/' . $b . '/assessments/' . $this->db('SELECT uuid FROM assessments WHERE title = ?', ['Theirs']);
        self::assertSame(404, $this->post($theirs . '/questions', ['bank' => $this->bank, 'questions' => [$this->questionUuid(1)]])->status, 'another institution\'s bank');
        $this->post($theirs . '/assignments', ['class' => $this->class]);
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM assessment_assignments'));

        $this->expectException(\PDOException::class);
        $this->app->db()->run('INSERT INTO assessment_items (institution_id, assessment_id, question_id, position) VALUES (?, ?, ?, 1)',
            [(int) $this->db('SELECT id FROM institutions WHERE uuid = ?', [$b]), (int) $this->db('SELECT id FROM assessments WHERE title = ?', ['Theirs']),
                (int) $this->db('SELECT id FROM bank_questions LIMIT 1')]);
    }
}
