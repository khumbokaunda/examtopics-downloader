<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Sittings;
use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** Candidates sitting assessments: starting, saving, the server's clock, handing in, grading and who sees what. */
final class SittingsTest extends HttpTestCase
{
    use InstitutionFixtures;

    private string $a;
    private string $path;
    private string $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->people('fay');
        $this->a = $this->institution('olive', 'Lilongwe ICT Academy');
        $this->join($this->a, 'olive', 'bob', 'instructor');
        $this->join($this->a, 'olive', 'erin', 'exam_officer');
        $this->join($this->a, 'olive', 'carol', 'student');
        $this->join($this->a, 'olive', 'dan', 'student');
        $this->join($this->a, 'olive', 'fay', 'student');
        $base = '/institutions/' . $this->a;

        $this->as('olive');
        $this->post($base . '/classes', ['name' => 'CCNA 1']);
        $class = (string) $this->db('SELECT uuid FROM institution_classes');
        $this->post($base . '/classes/' . $class . '/students', ['students' => [$this->memberUuid($this->a, 'carol'), $this->memberUuid($this->a, 'dan')]]);

        // Three published questions: single choice (2 marks, B right), multiple select with part
        // marks (3 marks, A and C right), true or false (1 mark, true).
        $this->post($base . '/banks', ['name' => 'Bank']);
        $bank = $base . '/banks/' . $this->db('SELECT uuid FROM question_banks');
        $this->post($bank . '/questions', ['type' => 'single', 'text' => 'Which layer routes?', 'choice_text' => ['Physical', 'Network', 'Session'], 'correct' => ['1'],
            'marks' => '2', 'difficulty' => 'easy', 'explanation' => 'SECRET EXPLANATION']);
        $this->post($bank . '/questions', ['type' => 'multiple', 'text' => 'Which are routing protocols?', 'choice_text' => ['OSPF', 'HTTP', 'EIGRP', 'FTP'],
            'correct' => ['0', '2'], 'partial_credit' => '1', 'marks' => '3', 'difficulty' => 'medium']);
        $this->post($bank . '/questions', ['type' => 'true_false', 'text' => 'OSPF is link-state.', 'tf_answer' => 'T', 'marks' => '1', 'difficulty' => 'easy']);
        $questions = array_column($this->app->db()->all('SELECT uuid FROM bank_questions ORDER BY id'), 'uuid');
        foreach ($questions as $q) {
            $this->post($bank . '/questions/' . $q . '/status', ['action' => 'submit']);
            $this->post($bank . '/questions/' . $q . '/status', ['action' => 'publish']);
        }

        $this->post($base . '/assessments', ['title' => 'Module test', 'type' => 'summative']);
        $this->assessment = (string) $this->db('SELECT uuid FROM assessments');
        $this->path = $base . '/assessments/' . $this->assessment;
        $this->post($this->path . '/settings', ['title' => 'Module test', 'type' => 'summative', 'description' => '', 'instructions' => 'Answer every question.',
            'duration_minutes' => '30', 'pass_percent' => '50', 'max_attempts' => '1', 'opens_at' => '', 'closes_at' => '', 'shuffle_questions' => '1',
            'shuffle_choices' => '1', 'negative_marking' => '0', 'review_policy' => 'answers']);
        $this->post($this->path . '/questions', ['bank' => (string) $this->db('SELECT uuid FROM question_banks'), 'questions' => $questions]);
        $this->post($this->path . '/assignments', ['class' => $class]);
        $this->post($this->path . '/status', ['action' => 'publish']);
        self::assertSame('published', $this->db('SELECT status FROM assessments'));
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

    private function mine(): string
    {
        return '/my/assessments/' . $this->a . '/' . $this->assessment;
    }

    /** Start as the signed-in person. @return string the sitting's uuid */
    private function start(): string
    {
        $r = $this->post($this->mine() . '/start', ['accept' => '1']);
        self::assertStringStartsWith('/sit/', $r->headers['Location'] ?? '', $this->lastFlash());
        return substr($r->headers['Location'], 5);
    }

    /** The position of the question whose text starts with $text in a sitting. */
    private function position(string $uuid, string $text): int
    {
        return (int) $this->db('SELECT q.position FROM assessment_session_questions q JOIN assessment_sessions s ON s.id = q.session_id
            JOIN question_versions v ON v.id = q.question_version_id WHERE s.uuid = ? AND v.text LIKE ?', [$uuid, $text . '%']);
    }

    public function testASittingFromStartToResult(): void
    {
        $this->as('carol');
        self::assertStringContainsString('Module test', $this->get('/my/assessments')->body);
        self::assertStringContainsString('the clock starts now', $this->get($this->mine())->body);
        $this->post($this->mine() . '/start');
        self::assertStringContainsString('tick the box', $this->lastFlash());
        $uuid = $this->start();
        self::assertSame($uuid, $this->start(), 'starting again carries on the same sitting');

        $page = $this->get('/sit/' . $uuid);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Which layer routes?', $page->body);
        self::assertStringNotContainsString('SECRET EXPLANATION', $page->body, 'nothing about the answers is on the page');
        self::assertStringNotContainsString('correct', $page->body);
        self::assertStringNotContainsString('href="/inbox"', $page->body, 'no menus during a sitting');

        $single = $this->position($uuid, 'Which layer');
        $multi = $this->position($uuid, 'Which are routing');
        $tf = $this->position($uuid, 'OSPF is');
        $save = fn(int $pos, string $answer, int $seq): array => $this->postJson('/api/sit/' . $uuid . '/answer', ['position' => (string) $pos, 'answer' => $answer, 'seq' => (string) $seq]);
        self::assertTrue($save($single, 'A', 10)['json']['kept']);
        self::assertTrue($save($single, 'B', 12)['json']['kept']);
        self::assertFalse($save($single, 'A', 11)['json']['kept'], 'an older save arriving late is ignored');
        $save($multi, 'A', 13);
        $save($tf, 'F', 14);
        self::assertSame('B', $this->db('SELECT answer FROM assessment_responses WHERE position = ?', [$single]));
        self::assertSame(3, (int) $this->db("SELECT COUNT(*) FROM assessment_response_history WHERE position = ?", [$single]) + 1, 'every kept change is in the history');

        $this->post('/sit/' . $uuid . '/submit');
        $s = $this->app->db()->one('SELECT * FROM assessment_sessions WHERE uuid = ?', [$uuid]);
        // 2 for the single choice, 1.5 of 3 for one of two right picks, 0 for a wrong true or false.
        self::assertSame(['submitted', '3.50', '6.00', '58.33', 1], [$s['status'], $s['score'], $s['max_score'], $s['percent'], (int) $s['passed']]);
        self::assertStringContainsString('Handed in', $this->get('/sit/' . $uuid . '/done')->body);
        self::assertSame(303, $this->get('/sit/' . $uuid)->status, 'a handed-in sitting does not open again');
        self::assertSame(409, $save($single, 'A', 99)['status']);
        $this->post('/sit/' . $uuid . '/submit');
        self::assertSame('3.50', $this->db('SELECT score FROM assessment_sessions'), 'handing in twice changes nothing');
        $this->post($this->mine() . '/start', ['accept' => '1']);
        self::assertStringContainsString('every attempt', $this->lastFlash());

        $this->as('erin');
        $results = $this->get($this->path . '/results')->body;
        self::assertStringContainsString('3.5 / 6', $results);
        self::assertStringContainsString('58.33%', $results);
        self::assertSame(200, $this->get($this->path . '/results/' . $uuid)->status);
        $this->post($this->path . '/status', ['action' => 'unpublish']);
        self::assertStringContainsString('stays published', $this->lastFlash());
        $this->as('olive');
        self::assertStringContainsString('handed in the assessment Module test', $this->get('/institutions/' . $this->a . '/activity')->body);
    }

    public function testTheServerKeepsTheTime(): void
    {
        $this->as('dan');
        $uuid = $this->start();
        $ends = (string) $this->db('SELECT ends_at FROM assessment_sessions WHERE uuid = ?', [$uuid]);
        self::assertEqualsWithDelta(time() + 1800, strtotime($ends . ' UTC'), 5, 'the end is set by the server');
        $pos = $this->position($uuid, 'Which layer');
        $this->postJson('/api/sit/' . $uuid . '/answer', ['position' => (string) $pos, 'answer' => 'B', 'seq' => '1']);

        $this->app->db()->run('UPDATE assessment_sessions SET ends_at = ? WHERE uuid = ?', [gmdate('Y-m-d H:i:s', time() - 5), $uuid]);
        $late = $this->postJson('/api/sit/' . $uuid . '/answer', ['position' => (string) $this->position($uuid, 'OSPF is'), 'answer' => 'T', 'seq' => '2']);
        self::assertSame(409, $late['status']);
        self::assertTrue($late['json']['ended']);
        $s = $this->app->db()->one('SELECT * FROM assessment_sessions WHERE uuid = ?', [$uuid]);
        self::assertSame(['auto_submitted', '2.00'], [$s['status'], $s['score']], 'handed in with what had arrived in time');
        self::assertStringContainsString('Time ran out', $this->get('/sit/' . $uuid . '/done')->body);

        // A sitting nobody comes back to is handed in by the background check.
        $this->as('carol');
        $other = $this->start();
        $this->app->db()->run('UPDATE assessment_sessions SET ends_at = ? WHERE uuid = ?', [gmdate('Y-m-d H:i:s', time() - 1), $other]);
        self::assertSame(1, $this->s->sittings()->expireOverdue());
        self::assertSame('auto_submitted', $this->db('SELECT status FROM assessment_sessions WHERE uuid = ?', [$other]));
        self::assertSame(0, $this->s->sittings()->expireOverdue(), 'running it again does nothing');
    }

    public function testOnlyTheCandidateReachesTheirSitting(): void
    {
        $this->as('carol');
        $uuid = $this->start();
        foreach (['dan', 'erin', 'olive', 'admin'] as $who) {
            $this->as($who);
            self::assertSame(404, $this->get('/sit/' . $uuid)->status, $who);
            self::assertSame(404, $this->postJson('/api/sit/' . $uuid . '/answer', ['position' => '1', 'answer' => 'A', 'seq' => '5'])['status'], $who);
        }
        $this->as('fay');
        self::assertStringNotContainsString('Module test', $this->get('/my/assessments')->body, 'a student it is not assigned to does not see it');
        self::assertSame(404, $this->get($this->mine())->status);
        self::assertSame(404, $this->post($this->mine() . '/start', ['accept' => '1'])->status);
    }

    public function testItOpensAndClosesOnTime(): void
    {
        $this->as('erin');
        $this->post($this->path . '/schedule', ['opens_at' => gmdate('Y-m-d\TH:i', time() + 7200), 'closes_at' => '']);
        $this->as('carol');
        self::assertStringContainsString('It opens', $this->get($this->mine())->body);
        $this->post($this->mine() . '/start', ['accept' => '1']);
        self::assertStringContainsString('not opened yet', $this->lastFlash());

        $this->as('erin');
        $this->post($this->path . '/schedule', ['opens_at' => '', 'closes_at' => gmdate('Y-m-d\TH:i', time() + 600)]);
        $this->as('carol');
        $uuid = $this->start();
        $ends = strtotime($this->db('SELECT ends_at FROM assessment_sessions WHERE uuid = ?', [$uuid]) . ' UTC');
        self::assertLessThanOrEqual(time() + 600, $ends, 'a sitting never runs past the closing time');
    }

    public function testMarking(): void
    {
        $single = ['type' => 'single', 'marks' => '2', 'partial_credit' => 0, 'choices' => json_encode([['key' => 'A', 'correct' => false], ['key' => 'B', 'correct' => true]])];
        $multi = ['type' => 'multiple', 'marks' => '4', 'partial_credit' => 1, 'choices' => json_encode([['key' => 'A', 'correct' => true], ['key' => 'B', 'correct' => false],
            ['key' => 'C', 'correct' => true], ['key' => 'D', 'correct' => false]])];
        self::assertSame(['awarded' => 2.0, 'correct' => true], Sittings::grade($single, 'B', 0.25));
        self::assertSame(['awarded' => -0.5, 'correct' => false], Sittings::grade($single, 'A', 0.25), 'a wrong answer loses a quarter');
        self::assertSame(['awarded' => 0.0, 'correct' => false], Sittings::grade($single, '', 0.25), 'no answer loses nothing');
        self::assertSame(2.0, Sittings::grade($multi, 'A', 0)['awarded']);
        self::assertSame(2.0, Sittings::grade($multi, 'ABC', 0)['awarded'], 'two right picks and a wrong one: one share');
        self::assertSame(0.0, Sittings::grade($multi, 'AB', 0)['awarded'], 'never below zero');
        self::assertSame(-1.0, Sittings::grade($multi, 'BD', 0.25)['awarded']);
        self::assertSame(0.0, Sittings::grade(['partial_credit' => 0] + $multi, 'A', 0)['awarded'], 'without part marks it is all or nothing');
        self::assertSame('AC', Sittings::cleanAnswer($multi, 'c,a,a,z'));
        self::assertSame('A', Sittings::cleanAnswer($single, 'AB'), 'one pick at most for single choice');
    }
}
