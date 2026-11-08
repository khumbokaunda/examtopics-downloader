<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** Results and analytics: statistics, publishing to candidates, what they see, exports and reports. */
final class ResultsTest extends HttpTestCase
{
    use InstitutionFixtures;

    private string $a;
    private string $path;
    private string $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->path, $this->assessment] = $this->sittingScenario();
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

    /** Sit the assessment as $who with answers by question text prefix. @param array<string,string> $answers @return string the sitting's uuid */
    private function sit(string $who, array $answers): string
    {
        $this->as($who);
        $r = $this->post('/my/assessments/' . $this->a . '/' . $this->assessment . '/start', ['accept' => '1']);
        $uuid = substr((string) $r->headers['Location'], 5);
        $seq = 1;
        foreach ($answers as $text => $answer) {
            $pos = (int) $this->db('SELECT q.position FROM assessment_session_questions q JOIN assessment_sessions s ON s.id = q.session_id
                JOIN question_versions v ON v.id = q.question_version_id WHERE s.uuid = ? AND v.text LIKE ?', [$uuid, $text . '%']);
            $this->postJson('/api/sit/' . $uuid . '/answer', ['position' => (string) $pos, 'answer' => $answer, 'seq' => (string) $seq++]);
        }
        $this->post('/sit/' . $uuid . '/submit');
        return $uuid;
    }

    public function testStatisticsPublishingAndWhatCandidatesSee(): void
    {
        // carol: all right (6 of 6). dan: single wrong (A, "Physical"), multiple half, true or false right (4 of 6 minus 2 = 2.5 of 6).
        $carol = $this->sit('carol', ['Which layer' => 'B', 'Which are routing' => 'AC', 'OSPF is' => 'T']);
        $dan = $this->sit('dan', ['Which layer' => 'A', 'Which are routing' => 'A', 'OSPF is' => 'T']);
        self::assertSame(['6.00', '2.50'], [$this->db('SELECT score FROM assessment_sessions WHERE uuid = ?', [$carol]), $this->db('SELECT score FROM assessment_sessions WHERE uuid = ?', [$dan])]);

        // Before publishing, candidates do not see results.
        self::assertSame(404, $this->get('/sit/' . $dan . '/result')->status);
        self::assertStringContainsString('Shown when the institution publishes results', $this->get('/my/assessments')->body);

        $this->as('erin');
        $page = $this->get($this->path . '/results')->body;
        self::assertStringContainsString('Score distribution', $page);
        self::assertStringContainsString('Physical', $page, 'the wrong choice picked most is named');
        self::assertStringContainsString('Results not published', $page);
        $csv = $this->get($this->path . '/results.csv');
        self::assertStringContainsString('text/csv', $csv->headers['Content-Type']);
        self::assertSame(3, count(array_filter(explode("\n", $csv->body))), 'a header and one line per sitting');
        $pdf = $this->get($this->path . '/results/' . $dan . '/report.pdf');
        self::assertSame('application/pdf', $pdf->headers['Content-Type']);
        self::assertStringStartsWith('%PDF', $pdf->body);

        $this->post($this->path . '/results/publication', ['action' => 'publish']);
        self::assertStringContainsString('2 candidates were told', $this->lastFlash());
        $danUser = $this->s->users()->findByUsername('dan');
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND link = ?", [(int) $danUser['id'], '/sit/' . $dan . '/result']));
        $this->post($this->path . '/results/publication', ['action' => 'publish']);
        self::assertStringContainsString('already published', $this->lastFlash());

        // The review policy here is "answers": score, topics and every answer with explanations.
        $this->as('dan');
        $result = $this->get('/sit/' . $dan . '/result')->body;
        self::assertStringContainsString('2.5 of 6', $result);
        self::assertStringContainsString('SECRET EXPLANATION', $result, 'explanations show once results are published with answers');
        self::assertStringContainsString('See your result', $this->get('/my/assessments')->body);
        self::assertStringStartsWith('%PDF', $this->get('/sit/' . $dan . '/result.pdf')->body);
        self::assertSame(404, $this->get('/sit/' . $carol . '/result')->status, 'only their own');

        // "Score only" keeps the answers back; "nothing" keeps everything back.
        $this->app->db()->run("UPDATE assessments SET review_policy = 'score'");
        $score = $this->get('/sit/' . $dan . '/result')->body;
        self::assertStringContainsString('2.5 of 6', $score);
        self::assertStringNotContainsString('SECRET EXPLANATION', $score);
        $this->app->db()->run("UPDATE assessments SET review_policy = 'none'");
        $none = $this->get('/sit/' . $dan . '/result')->body;
        self::assertStringContainsString('Your result is recorded', $none);
        self::assertStringNotContainsString('2.5 of 6', $none);
        self::assertSame(404, $this->get('/sit/' . $dan . '/result.pdf')->status);

        $this->as('erin');
        $this->post($this->path . '/results/publication', ['action' => 'unpublish']);
        $this->as('dan');
        self::assertSame(404, $this->get('/sit/' . $dan . '/result')->status, 'taken back');
    }

    public function testWhoMayPublishAndExport(): void
    {
        $this->sit('carol', ['Which layer' => 'B']);
        $this->as('bob');
        // An instructor who did not create the assessment does not see it at all.
        self::assertSame(404, $this->get($this->path . '/results')->status);
        self::assertSame(404, $this->get($this->path . '/results.csv')->status);
        $this->app->db()->run('UPDATE assessments SET created_by = (SELECT m.id FROM institution_memberships m JOIN users u ON u.id = m.user_id WHERE u.username = ?)', ['bob']);
        self::assertSame(200, $this->get($this->path . '/results')->status, 'its creator sees its results');
        self::assertSame(403, $this->get($this->path . '/results.csv')->status, 'but exporting needs results.export');
        self::assertSame(403, $this->post($this->path . '/results/publication', ['action' => 'publish'])->status);
    }

    public function testClassStudentAndInstitutionViews(): void
    {
        $carol = $this->sit('carol', ['Which layer' => 'B', 'Which are routing' => 'AC', 'OSPF is' => 'T']);
        $this->as('olive');
        $class = (string) $this->db('SELECT uuid FROM institution_classes');
        $page = $this->get('/institutions/' . $this->a . '/classes/' . $class)->body;
        self::assertStringContainsString('1 of 2 handed in', $page);
        self::assertStringContainsString('average 100%', $page);
        self::assertStringContainsString('/results/' . $carol, $this->get('/institutions/' . $this->a . '/students/' . $this->memberUuid($this->a, 'carol'))->body);
        self::assertStringContainsString('Pass rate', $this->get('/institutions/' . $this->a)->body);
    }
}
