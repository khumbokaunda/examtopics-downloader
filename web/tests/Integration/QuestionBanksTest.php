<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** Institution question banks: topics, the question editor, versions, review and who may do what. */
final class QuestionBanksTest extends HttpTestCase
{
    use InstitutionFixtures;

    private string $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->people('fay');
        $this->a = $this->institution('olive', 'Lilongwe ICT Academy');
        $this->join($this->a, 'olive', 'bob', 'instructor');
        $this->join($this->a, 'olive', 'dan', 'instructor');
        $this->join($this->a, 'olive', 'erin', 'exam_officer');
        $this->join($this->a, 'olive', 'carol', 'student');
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

    /** @return string the bank's path */
    private function bank(string $name = 'CCNA 1'): string
    {
        $this->post('/institutions/' . $this->a . '/banks', ['name' => $name, 'subject' => 'Networking', 'programme' => '', 'description' => '']);
        return '/institutions/' . $this->a . '/banks/' . $this->db('SELECT uuid FROM question_banks WHERE name = ?', [$name]);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> a valid single-choice question */
    private function single(array $extra = []): array
    {
        return $extra + ['type' => 'single', 'text' => "Which layer routes packets?\nChoose one.", 'choice_text' => ['Physical', 'Network', 'Transport', ''],
            'correct' => ['1'], 'marks' => '2', 'difficulty' => 'easy', 'topic' => '', 'explanation' => 'Layer 3 routes.', 'reference' => ''];
    }

    private function questionPath(string $bank): string
    {
        return $bank . '/questions/' . $this->db('SELECT uuid FROM bank_questions ORDER BY id DESC LIMIT 1');
    }

    public function testWritingAQuestionChecksItAndKeepsItADraft(): void
    {
        $bank = $this->bank();
        $this->post($bank . '/topics', ['name' => 'Routing', 'parent' => '']);
        $routing = (string) $this->db('SELECT uuid FROM bank_topics WHERE name = ?', ['Routing']);
        $this->post($bank . '/topics', ['name' => 'OSPF', 'parent' => $routing]);
        self::assertSame(2, (int) $this->db('SELECT COUNT(*) FROM bank_topics'));

        $bad = $this->post($bank . '/questions', $this->single(['correct' => ['0', '1']]));
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Mark exactly one choice', $bad->body);
        self::assertStringContainsString('Which layer routes packets?', $bad->body, 'the form comes back filled in');
        self::assertStringContainsString('Give at least two choices', $this->post($bank . '/questions', $this->single(['choice_text' => ['Only one']]))->body);
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM bank_questions'));

        $this->post($bank . '/questions', $this->single(['topic' => $routing]));
        $q = $this->app->db()->one('SELECT * FROM bank_questions');
        self::assertSame('draft', $q['status']);
        self::assertNull($q['published_version']);
        $v = $this->app->db()->one('SELECT * FROM question_versions');
        self::assertSame([['key' => 'A', 'text' => 'Physical', 'correct' => false], ['key' => 'B', 'text' => 'Network', 'correct' => true],
            ['key' => 'C', 'text' => 'Transport', 'correct' => false]], json_decode($v['choices'], true));
        self::assertSame('2.00', $v['marks']);
        self::assertStringContainsString('Network', $this->get($this->questionPath($bank))->body);

        // True or false, and multiple select with part marks.
        $this->post($bank . '/questions', ['type' => 'true_false', 'text' => 'OSPF is a link-state protocol.', 'tf_answer' => 'T', 'marks' => '1', 'difficulty' => 'medium']);
        self::assertSame([['key' => 'T', 'text' => 'True', 'correct' => true], ['key' => 'F', 'text' => 'False', 'correct' => false]],
            json_decode((string) $this->db('SELECT choices FROM question_versions ORDER BY id DESC LIMIT 1'), true));
        $this->post($bank . '/questions', ['type' => 'multiple', 'text' => 'Which are routing protocols?', 'choice_text' => ['OSPF', 'HTTP', 'EIGRP'],
            'correct' => ['0', '2'], 'partial_credit' => '1', 'marks' => '3', 'difficulty' => 'hard']);
        self::assertSame(1, (int) $this->db('SELECT partial_credit FROM question_versions ORDER BY id DESC LIMIT 1'));

        // A topic used by a question stays.
        $this->post($bank . '/topics/' . $routing . '/delete');
        self::assertStringContainsString('stays', $this->lastFlash());
    }

    public function testReviewAndPublishingWithVersionsThatNeverChange(): void
    {
        $bank = $this->bank();
        $this->post($bank . '/questions', $this->single());
        $path = $this->questionPath($bank);
        $this->post($path . '/status', ['action' => 'approve']);
        self::assertStringContainsString('not by you', $this->lastFlash(), 'an instructor cannot approve');
        $this->post($path . '/status', ['action' => 'submit']);
        self::assertSame('review', $this->db('SELECT status FROM bank_questions'));

        $this->as('erin');
        self::assertStringContainsString('Which layer routes packets?', $this->get('/institutions/' . $this->a . '/banks/review')->body);
        $this->post($path . '/status', ['action' => 'return', 'note' => '']);
        self::assertStringContainsString('Say what needs changing', $this->lastFlash());
        $this->post($path . '/status', ['action' => 'return', 'note' => 'Add a fourth choice.']);
        $this->as('bob');
        self::assertStringContainsString('Add a fourth choice.', $this->get($path)->body);
        $this->post($path . '/status', ['action' => 'submit']);
        $this->as('erin');
        $this->post($path . '/status', ['action' => 'publish']);
        self::assertSame(['published', 1], [$this->db('SELECT status FROM bank_questions'), (int) $this->db('SELECT published_version FROM bank_questions')]);

        // An edit is a new version; the published one stays in use and unchanged.
        $this->as('bob');
        $before = $this->app->db()->one('SELECT * FROM question_versions WHERE version = 1');
        $this->post($path, $this->single());
        self::assertSame('Nothing was changed.', $this->lastFlash());
        $this->post($path, $this->single(['choice_text' => ['Physical', 'Network', 'Transport', 'Session']]));
        self::assertStringContainsString('Assessments keep using version 1', $this->lastFlash());
        self::assertSame(['draft', 2, 1], [$this->db('SELECT status FROM bank_questions'), (int) $this->db('SELECT latest_version FROM bank_questions'),
            (int) $this->db('SELECT published_version FROM bank_questions')]);
        self::assertSame($before, $this->app->db()->one('SELECT * FROM question_versions WHERE version = 1'), 'a version is never changed');
        $old = $this->get($path . '/versions/1')->body;
        self::assertStringContainsString('An earlier version', $old);
        self::assertStringNotContainsString('Session', $old);
        self::assertSame(404, $this->get($path . '/versions/9')->status);

        $this->post($path . '/status', ['action' => 'submit']);
        $this->as('erin');
        $this->post($path . '/status', ['action' => 'publish']);
        self::assertSame(2, (int) $this->db('SELECT published_version FROM bank_questions'));
        $this->post($path . '/status', ['action' => 'archive']);
        self::assertSame('archived', $this->db('SELECT status FROM bank_questions'));
        $this->post($path . '/status', ['action' => 'restore']);
        self::assertSame('published', $this->db('SELECT status FROM bank_questions'), 'restored to what it was');
        $activity = $this->get('/institutions/' . $this->a . '/activity');
        self::assertSame(403, $activity->status, 'an examination officer has no activity page');
        $this->as('olive');
        self::assertStringContainsString('published version 2 of a question in CCNA 1', $this->get('/institutions/' . $this->a . '/activity')->body);
    }

    public function testWhoMayReadAndWriteABank(): void
    {
        $bank = $this->bank();
        $this->post($bank . '/questions', $this->single());
        $path = $this->questionPath($bank);

        $this->as('dan');
        self::assertSame(404, $this->get($bank)->status, 'another instructor does not see a bank they were not added to');
        self::assertStringNotContainsString('href="' . $bank . '"', $this->get('/institutions/' . $this->a . '/banks')->body);
        $this->as('bob');
        $this->post($bank . '/editors', ['editors' => [$this->memberUuid($this->a, 'dan')]]);
        $this->post($bank . '/editors', ['editors' => [$this->memberUuid($this->a, 'carol')]]);
        self::assertStringContainsString('does not include writing questions', $this->lastFlash());
        $this->as('dan');
        self::assertSame(200, $this->get($bank . '/questions/new')->status, 'an added editor writes');
        self::assertSame(403, $this->post($bank . '/archive')->status, 'but only the creator manages the bank');

        $this->as('erin');
        self::assertSame(200, $this->get($path)->status, 'an examination officer reads every bank');
        self::assertSame(403, $this->get($bank . '/questions/new')->status);

        $this->as('carol');
        self::assertSame(403, $this->get('/institutions/' . $this->a . '/banks')->status);
        self::assertSame(404, $this->get($path)->status, 'students never reach a question or its answer');
    }

    public function testBanksStayInsideTheirInstitution(): void
    {
        $bank = $this->bank();
        $this->post($bank . '/questions', $this->single());
        $b = $this->institution('fay', 'Mzuzu Academy');
        $this->as('fay');
        $this->post('/institutions/' . $b . '/banks', ['name' => 'Theirs']);
        $theirs = (string) $this->db('SELECT uuid FROM question_banks WHERE name = ?', ['Theirs']);
        $this->post('/institutions/' . $b . '/banks/' . $theirs . '/topics', ['name' => 'Their topic']);
        $theirTopic = (string) $this->db('SELECT uuid FROM bank_topics WHERE name = ?', ['Their topic']);
        self::assertSame(404, $this->get($bank)->status, 'another institution\'s owner');

        $this->as('olive');
        self::assertSame(404, $this->get('/institutions/' . $this->a . '/banks/' . $theirs)->status);
        $r = $this->post($bank . '/questions', $this->single(['topic' => $theirTopic]));
        self::assertStringContainsString('Choose one of the bank', $r->body, 'another bank\'s topic is refused');

        $this->expectException(\PDOException::class);
        $iidB = (int) $this->db('SELECT id FROM institutions WHERE uuid = ?', [$b]);
        $qA = (int) $this->db('SELECT id FROM bank_questions LIMIT 1');
        $this->app->db()->run("INSERT INTO question_versions (institution_id, question_id, version, type, text, choices, marks, difficulty, created_at)
                               VALUES (?, ?, 9, 'single', 'x', '[]', 1, 'easy', NOW())", [$iidB, $qA]);
    }

    public function testPicturesAndArchivedBanks(): void
    {
        $bank = $this->bank();
        $png = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $r = $this->postFiles($bank . '/questions', $this->single(), ['image' => ['name' => 'p.png', 'tmp_name' => $png, 'size' => (int) filesize($png), 'error' => UPLOAD_ERR_OK]]);
        self::assertSame(303, $r->status);
        $images = json_decode((string) $this->db('SELECT images FROM question_versions LIMIT 1'), true);
        self::assertCount(1, $images);
        self::assertContains($images[0], $this->s->questionBanks()->repo()->allImageUrls(), 'image cleanup keeps bank pictures');
        self::assertStringContainsString('/media/' . substr($images[0], 7), $this->get($this->questionPath($bank))->body);

        $this->post($bank . '/archive');
        $r = $this->post($bank . '/questions', $this->single(['text' => 'Another']));
        self::assertStringContainsString('archived bank takes no new questions', $r->body);
    }
}
