<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/**
 * Written-answer (essay) questions: a sitting with a written answer is handed in awaiting
 * marking, with no percent or pass; results cannot be published until every written answer is
 * marked; marking completes the score; candidates see their answer and the feedback.
 */
final class WrittenAnswersTest extends HttpTestCase
{
    use InstitutionFixtures;

    private string $inst;
    private string $path;
    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->inst] = $this->sittingScenario();
        $base = '/institutions/' . $this->inst;
        $bank = $base . '/banks/' . $this->value('SELECT uuid FROM question_banks');
        $this->post($bank . '/questions', ['type' => 'essay', 'text' => 'Explain how OSPF elects a designated router.', 'marks' => '5', 'difficulty' => 'medium',
            'explanation' => 'Priority first, then router ID. 5 marks for both with an example.']);
        $essay = (string) $this->value("SELECT q.uuid FROM bank_questions q JOIN question_versions v ON v.question_id = q.id WHERE v.type = 'essay'");
        $this->post($bank . '/questions/' . $essay . '/status', ['action' => 'submit']);
        $this->post($bank . '/questions/' . $essay . '/status', ['action' => 'publish']);
        $single = (string) $this->value("SELECT q.uuid FROM bank_questions q JOIN question_versions v ON v.question_id = q.id WHERE v.text = 'Which layer routes?'");

        $this->post($base . '/assessments', ['title' => 'Essay test', 'type' => 'formative']);
        $this->uuid = (string) $this->value("SELECT uuid FROM assessments WHERE title = 'Essay test'");
        $this->path = $base . '/assessments/' . $this->uuid;
        $this->post($this->path . '/settings', ['title' => 'Essay test', 'type' => 'formative', 'description' => '', 'instructions' => 'Answer both questions.', 'duration_minutes' => '30',
            'pass_percent' => '50', 'max_attempts' => '1', 'opens_at' => '', 'closes_at' => '', 'shuffle_questions' => '0', 'shuffle_choices' => '0',
            'negative_marking' => '0', 'review_policy' => 'answers']);
        $this->post($this->path . '/questions', ['bank' => (string) $this->value('SELECT uuid FROM question_banks'), 'questions' => [$single, $essay]]);
        $this->post($this->path . '/assignments', ['class' => (string) $this->value('SELECT uuid FROM institution_classes')]);
        $this->post($this->path . '/status', ['action' => 'publish']);
        self::assertSame('published', $this->value("SELECT status FROM assessments WHERE title = 'Essay test'"));
    }

    private function value(string $sql, array $args = []): mixed
    {
        return $this->app->db()->value($sql, $args);
    }

    private function message(): string
    {
        $f = $this->flashes();
        return (string) (end($f)['message'] ?? '');
    }

    /** @return string the sitting's uuid */
    private function sit(string $who, string $single, string $written): string
    {
        $this->as($who);
        $r = $this->post('/my/assessments/' . $this->inst . '/' . $this->uuid . '/start', ['accept' => '1']);
        $sitting = substr((string) $r->headers['Location'], 5);
        $page = $this->get('/sit/' . $sitting)->body;
        self::assertStringContainsString('class="form-control mock-text"', $page, 'a text box for the written answer');
        $pos = fn(string $type): int => (int) $this->value('SELECT q.position FROM assessment_session_questions q JOIN assessment_sessions s ON s.id = q.session_id
            JOIN question_versions v ON v.id = q.question_version_id WHERE s.uuid = ? AND v.type = ?', [$sitting, $type]);
        $this->postJson('/api/sit/' . $sitting . '/answer', ['position' => (string) $pos('single'), 'answer' => $single, 'seq' => '1']);
        $this->postJson('/api/sit/' . $sitting . '/answer', ['position' => (string) $pos('essay'), 'answer' => $written, 'seq' => '2']);
        $this->post('/sit/' . $sitting . '/submit');
        return $sitting;
    }

    public function testWrittenAnswersAreMarkedBeforeResultsArePublished(): void
    {
        $carol = $this->sit('carol', 'B', "The router with the highest priority wins.\nOn a tie, the highest router ID.");
        $dan = $this->sit('dan', 'B', '   ');
        $session = $this->app->db()->one('SELECT * FROM assessment_sessions WHERE uuid = ?', [$carol]);
        self::assertSame(['awaiting', null, null, '2.00'], [$session['marking'], $session['percent'], $session['passed'], $session['score']], 'the written answer waits for a marker');
        self::assertStringContainsString("highest router ID.", (string) $this->value('SELECT r.answer FROM assessment_responses r JOIN assessment_sessions s ON s.id = r.session_id WHERE s.uuid = ? ORDER BY r.position DESC LIMIT 1', [$carol]), 'kept in full, line breaks and all');
        $blank = $this->app->db()->one('SELECT * FROM assessment_sessions WHERE uuid = ?', [$dan]);
        self::assertSame([null, '28.57'], [$blank['marking'], $blank['percent']], 'nothing written: nothing to mark, it earns nothing');

        // Results wait for the marking.
        $this->as('olive');
        $results = $this->get($this->path . '/results')->body;
        self::assertStringContainsString('1 sitting to mark', $results);
        $this->post($this->path . '/results/publication', ['action' => 'publish']);
        self::assertStringContainsString('still to mark', $this->message());
        self::assertNull($this->value('SELECT results_published_at FROM assessments WHERE uuid = ?', [$this->uuid]));

        // Marking: within the question's marks; the score, percent and pass are then complete.
        $marking = $this->path . '/results/' . $carol . '/marking';
        $page = $this->get($marking)->body;
        self::assertStringContainsString('highest router ID.', $page);
        self::assertStringContainsString('Marking guide.', $page);
        $pos = (int) $this->value("SELECT q.position FROM assessment_session_questions q JOIN assessment_sessions s ON s.id = q.session_id
            JOIN question_versions v ON v.id = q.question_version_id WHERE s.uuid = ? AND v.type = 'essay'", [$carol]);
        $this->post($marking, ['marks' => [$pos => '6'], 'feedback' => [$pos => 'x']]);
        self::assertStringContainsString('give a mark from 0 to 5', $this->message());
        $this->post($marking, ['marks' => [$pos => '4'], 'feedback' => [$pos => 'Right order; no example.']]);
        $session = $this->app->db()->one('SELECT * FROM assessment_sessions WHERE uuid = ?', [$carol]);
        self::assertSame(['marked', '6.00', '85.71', 1], [$session['marking'], $session['score'], $session['percent'], (int) $session['passed']]);
        self::assertStringContainsString('All marked.', $this->get($this->path . '/results')->body);

        // Published: the candidate sees the answer and the feedback; the marks are then fixed.
        $this->post($this->path . '/results/publication', ['action' => 'publish']);
        self::assertNotNull($this->value('SELECT results_published_at FROM assessments WHERE uuid = ?', [$this->uuid]));
        $this->post($marking, ['marks' => [$pos => '5'], 'feedback' => [$pos => 'Changed my mind.']]);
        self::assertStringContainsString('The results are published', $this->message());
        self::assertSame('85.71', $this->value('SELECT percent FROM assessment_sessions WHERE uuid = ?', [$carol]));
        $this->as('carol');
        $result = $this->get('/sit/' . $carol . '/result')->body;
        self::assertStringContainsString('highest router ID.', $result);
        self::assertStringContainsString('Right order; no example.', $result);
        self::assertStringNotContainsString('Priority first, then router ID', $result, 'the marking guide is for markers');
        self::assertStringStartsWith('%PDF', $this->get('/sit/' . $carol . '/result.pdf')->body);

        // Candidates do not mark.
        self::assertNotSame(200, $this->get($marking)->status);
        self::assertNotSame(303, $this->post($marking, ['marks' => [$pos => '5']])->status);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'sitting.marked'"));
    }

    public function testTheQuestionEditorOffersWrittenAnswers(): void
    {
        $bank = '/institutions/' . $this->inst . '/banks/' . $this->value('SELECT uuid FROM question_banks');
        $form = $this->get($bank . '/questions/new')->body;
        self::assertStringContainsString('value="essay"', $form);
        self::assertStringContainsString('a marker gives the marks', $form);
    }
}
