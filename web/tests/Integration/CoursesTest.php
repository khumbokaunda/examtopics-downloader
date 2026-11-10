<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\QuestionImport;
use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/**
 * Courses (a class being taught something), their notices and assessments, what a person sees
 * coming up, importing questions into a bank, and copying an assessment.
 */
final class CoursesTest extends HttpTestCase
{
    use InstitutionFixtures;

    private function value(string $sql, array $args = []): mixed
    {
        return $this->app->db()->value($sql, $args);
    }

    private function message(): string
    {
        $f = $this->flashes();
        return (string) (end($f)['message'] ?? '');
    }

    /** @return array{name:string,tmp_name:string,size:int,error:int} */
    private function upload(string $name, string $content): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, $content);
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }

    public function testAnInstructorRunsACourseAndItsLearnersSeeIt(): void
    {
        [$inst, $test, $testUuid] = $this->sittingScenario();
        $base = '/institutions/' . $inst;
        $class = (string) $this->value('SELECT uuid FROM institution_classes');
        $this->post($base . '/classes/' . $class . '/instructors', ['instructors' => [$this->memberUuid($inst, 'bob')]]);
        $this->post($base . '/classes', ['name' => 'Linux basics']);
        $other = (string) $this->value("SELECT uuid FROM institution_classes WHERE name = 'Linux basics'");
        $this->post($test . '/schedule', ['opens_at' => '', 'closes_at' => gmdate('Y-m-d\TH:i', time() + 5 * 86400)]);
        self::assertNotNull($this->value('SELECT closes_at FROM assessments'), 'the assessment has a deadline');

        // The instructor starts a course for the class he teaches, and only for that one.
        $this->as('bob');
        $list = $this->get($base . '/courses');
        self::assertSame(200, $list->status);
        self::assertStringContainsString('New course', $list->body);
        self::assertStringNotContainsString('Linux basics', $list->body, 'only the classes he teaches are offered');
        $this->post($base . '/courses', ['title' => 'Routing', 'class' => $other]);
        self::assertStringContainsString('only for a class you teach', $this->message());
        $this->post($base . '/courses', ['title' => 'Routing', 'class' => $class, 'starts_on' => '2030-02-01', 'ends_on' => '2030-01-01']);
        self::assertStringContainsString('ends before it starts', $this->message());
        $r = $this->post($base . '/courses', ['title' => 'Routing and switching', 'class' => $class, 'lead' => $this->memberUuid($inst, 'bob'),
            'starts_on' => gmdate('Y-m-d', time() + 86400), 'ends_on' => gmdate('Y-m-d', time() + 60 * 86400), 'summary' => 'Twelve weeks of routing.']);
        $course = (string) $this->value('SELECT uuid FROM courses');
        self::assertSame($base . '/courses/' . $course, $r->headers['Location']);
        $path = $base . '/courses/' . $course;
        self::assertStringContainsString('Twelve weeks of routing.', $this->get($path)->body);

        // His own draft assessment goes in, and is set for the class with it.
        $this->post($base . '/assessments', ['title' => 'Quiz 2', 'type' => 'formative']);
        $quiz = (string) $this->value("SELECT uuid FROM assessments WHERE title = 'Quiz 2'");
        self::assertStringContainsString('Quiz 2', $this->get($path, ['tab' => 'assessments'])->body, 'offered in the list');
        $this->post($path . '/assessments', ['assessment' => $quiz]);
        self::assertStringContainsString('set for CCNA 1', $this->message());
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM assessment_assignments x JOIN assessments a ON a.id = x.assessment_id WHERE a.title = 'Quiz 2' AND x.class_id IS NOT NULL"));
        $this->post($path . '/assessments', ['assessment' => $testUuid]);
        self::assertStringContainsString('Choose one of the assessments', $this->message(), 'he cannot add an assessment he cannot see');

        // He posts a notice: the class's students are told, nobody else.
        $this->post($path . '/notices', ['title' => '', 'body' => 'x']);
        self::assertStringContainsString('Give the notice a title', $this->message());
        $this->post($path . '/notices', ['title' => 'Lab moved to Friday', 'body' => "Bring your laptops.\nRoom 4."]);
        self::assertStringContainsString('2 learners were told', $this->message());
        self::assertSame(['carol', 'dan'], array_column($this->app->db()->all("SELECT u.username FROM notifications n JOIN users u ON u.id = n.user_id
            WHERE n.title LIKE '%Lab moved to Friday%' ORDER BY u.username"), 'username'));

        // The owner adds the published test, which has a deadline.
        $this->as('olive');
        $this->post($path . '/assessments', ['assessment' => $testUuid]);
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM assessments WHERE course_id IS NOT NULL'));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM assessment_assignments x JOIN assessments a ON a.id = x.assessment_id WHERE a.uuid = ?', [$testUuid]),
            'already set for the class: not assigned twice');

        // A learner: the course, the notice and the deadline on home; the course page without the staff's tools.
        $this->as('carol');
        $home = $this->get('/')->body;
        self::assertStringContainsString('Your courses', $home);
        self::assertStringContainsString('Routing and switching', $home);
        self::assertStringContainsString('notice: Lab moved to Friday', $home);
        self::assertStringContainsString('Coming up', $home);
        self::assertMatchesRegularExpression('#Module test</a></div>\s*<div class="row-meta">Closes #', $home, 'the deadline');
        self::assertMatchesRegularExpression('#Routing and switching</a></div>\s*<div class="row-meta">Starts #', $home, 'the course start');
        $page = $this->get($path);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Bring your laptops.', $page->body);
        self::assertStringNotContainsString('Post a notice', $page->body);
        self::assertStringNotContainsString('Course details', $page->body);
        $set = $this->get($path, ['tab' => 'assessments'])->body;
        self::assertStringContainsString('href="/my/assessments/' . $inst . '/' . $testUuid . '"', $set);
        self::assertStringNotContainsString('Quiz 2', $set, 'a draft is not shown to learners');
        $people = $this->get($path, ['tab' => 'participants'])->body;
        self::assertStringContainsString('2 learners in CCNA 1', $people);
        self::assertStringNotContainsString('>dan<', $people, 'learners are not shown each other');
        self::assertStringContainsString('aria-label="Institution sections"', $page->body);
        self::assertMatchesRegularExpression('#href="' . $base . '/courses"[^>]*><i [^>]+></i>Courses</a>#', $page->body, 'a student reaches courses from the institution');
        self::assertSame(403, $this->post($path . '/notices', ['title' => 'Hi', 'body' => 'x'])->status);
        self::assertSame(403, $this->post($path, ['title' => 'Mine'])->status);

        // A student of another class, and someone outside the institution.
        $this->as('fay');
        self::assertSame(403, $this->get($path)->status);
        self::assertStringNotContainsString('Routing and switching', $this->get($base . '/courses')->body);
        self::assertStringNotContainsString('Routing and switching', $this->get('/')->body);
        $this->as('admin');
        self::assertSame(404, $this->get($path)->status, 'another institution cannot reach it');

        // Someone who sees every class sees the course but does not run it.
        $this->as('erin');
        self::assertSame(200, $this->get($path)->status);
        self::assertStringContainsString('carol', $this->get($path, ['tab' => 'participants'])->body);
        self::assertSame(403, $this->post($path . '/notices', ['title' => 'Hi', 'body' => 'x'])->status);

        // Taking an assessment out leaves who it is set for; a removed notice is gone; an archived course is closed.
        $this->as('bob');
        $this->post($path . '/assessments/' . $quiz . '/remove');
        self::assertNull($this->value("SELECT course_id FROM assessments WHERE title = 'Quiz 2'"));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM assessment_assignments x JOIN assessments a ON a.id = x.assessment_id WHERE a.title = 'Quiz 2'"));
        $this->post($path . '/notices/' . $this->value('SELECT uuid FROM course_announcements') . '/remove');
        self::assertStringNotContainsString('Bring your laptops.', $this->get($path)->body);
        self::assertNotNull($this->value('SELECT removed_at FROM course_announcements'), 'kept, marked removed');
        $this->post($path . '/archive');
        self::assertSame('archived', $this->value('SELECT status FROM courses'));
        $this->post($path . '/notices', ['title' => 'Late', 'body' => 'x']);
        self::assertStringContainsString('archived course', $this->message());
        self::assertStringContainsString('Routing and switching', $this->get($base . '/courses', ['status' => 'archived'])->body);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'institution.course_created'"));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'institution.course_notice_posted'"));
    }

    public function testQuestionsAreImportedAsDraftsAfterAPreview(): void
    {
        [$inst] = $this->sittingScenario();
        $bank = '/institutions/' . $inst . '/banks/' . $this->value('SELECT uuid FROM question_banks');
        $this->post($bank . '/topics', ['name' => 'Routing']);
        $before = (int) $this->value('SELECT COUNT(*) FROM bank_questions');
        $audits = (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'institution.question_created'");
        self::assertStringContainsString('href="' . $bank . '/import"', $this->get($bank)->body);
        self::assertStringStartsWith('type,question', $this->get($bank . '/import/template.csv')->body);

        $csv = "question,a,b,c,d,answer,marks,difficulty,topic,explanation\r\n"
            . "Which protocol uses port 22?,Telnet,SSH,FTP,,B,2,easy,Routing,SSH listens on 22.\r\n"
            . "Which are private address ranges?,10.0.0.0/8,8.8.8.0/24,192.168.0.0/16,,\"A,C\",,,,\r\n"
            . "A router works at layer 3.,,,,,True,,,,\r\n"
            . "Which cable is used?,UTP,Fibre,,,D,,,,\r\n"
            . "Which layer routes?,Physical,Network,Session,,B,,,,\r\n"
            . "What is OSPF?,A protocol,A cable,,,A,,,Switching,\r\n"
            . ",,,,,,,,,\r\n";
        $page = $this->postFiles($bank . '/import', [], ['file' => $this->upload('questions.csv', $csv)]);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Preview: 3 ready, 3 not importable', $page->body);
        self::assertStringContainsString('names choice D, which is empty', $page->body);
        self::assertStringContainsString('Already in the bank.', $page->body);
        self::assertStringContainsString('no topic called &quot;Switching&quot;', $page->body);
        self::assertSame($before, (int) $this->value('SELECT COUNT(*) FROM bank_questions'), 'a preview saves nothing');
        $problems = $this->get($bank . '/import/problems.csv')->body;
        self::assertStringContainsString('Which cable is used?', $problems);
        self::assertStringNotContainsString('port 22', $problems);

        $this->post($bank . '/import/confirm');
        self::assertStringContainsString('3 questions imported as drafts', $this->message());
        self::assertSame($before + 3, (int) $this->value('SELECT COUNT(*) FROM bank_questions'));
        self::assertSame(3, (int) $this->value("SELECT COUNT(*) FROM bank_questions WHERE status = 'draft'"), 'they go through review like any other');
        $ssh = $this->app->db()->one("SELECT v.* FROM question_versions v WHERE v.text = 'Which protocol uses port 22?'");
        self::assertSame(['single', '2.00', 'easy', 'SSH listens on 22.'], [$ssh['type'], $ssh['marks'], $ssh['difficulty'], $ssh['explanation']]);
        self::assertNotNull($ssh['topic_id']);
        self::assertSame('multiple', $this->value("SELECT type FROM question_versions WHERE text = 'Which are private address ranges?'"));
        self::assertSame('true_false', $this->value("SELECT type FROM question_versions WHERE text = 'A router works at layer 3.'"));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'institution.questions_imported'"), 'recorded once');
        self::assertSame($audits, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'institution.question_created'"), 'not once per question');
        $this->post($bank . '/import/confirm');
        self::assertStringContainsString('preview has expired', $this->message(), 'a preview is used once');

        // The same file again imports nothing.
        self::assertStringContainsString('Preview: 0 ready, 6 not importable', $this->postFiles($bank . '/import', [], ['file' => $this->upload('questions.csv', $csv)])->body);

        // Aiken text.
        $aiken = "What does DNS do?\nIt is asked often.\nA. Resolves names\nB) Routes packets\nANSWER: A\n\nWhich device forwards frames?\nA. Switch\nB. Modem\n\nWhat is a VLAN?\nA. A virtual LAN\nB. A cable\nANSWER: a\n";
        $rows = QuestionImport::parse($aiken, 'aiken');
        self::assertSame(["What does DNS do?\nIt is asked often.", 'A', ['a' => 'Resolves names', 'b' => 'Routes packets']], [$rows[0]['text'], $rows[0]['answer'], $rows[0]['choices']]);
        self::assertCount(3, $rows);
        $page = $this->postFiles($bank . '/import', ['format' => 'aiken'], ['file' => $this->upload('q.txt', $aiken)])->body;
        self::assertStringContainsString('Preview: 2 ready, 1 not importable', $page);
        $this->post($bank . '/import/confirm');
        self::assertSame($before + 5, (int) $this->value('SELECT COUNT(*) FROM bank_questions'));

        self::assertSame(422, $this->postFiles($bank . '/import', [], ['file' => $this->upload('empty.csv', "name,age\r\nx,1\r\n")])->status);

        // Someone who cannot write in the bank cannot import into it.
        $this->as('carol');
        self::assertNotSame(200, $this->get($bank . '/import')->status);
        self::assertNotSame(200, $this->postFiles($bank . '/import', [], ['file' => $this->upload('questions.csv', $csv)])->status);
    }

    public function testAnAssessmentIsCopiedAsANewDraft(): void
    {
        [$inst, $path, $uuid] = $this->sittingScenario();
        $base = '/institutions/' . $inst;
        self::assertStringContainsString('Copy as a new draft', $this->get($path)->body);
        $r = $this->post($path . '/duplicate');
        $copy = $this->app->db()->one("SELECT * FROM assessments WHERE title = 'Copy of Module test'");
        self::assertSame($base . '/assessments/' . $copy['uuid'], $r->headers['Location']);
        self::assertSame(['draft', '30', '50.00', 'answers', null, null, null], [$copy['status'], (string) $copy['duration_minutes'], $copy['pass_percent'], $copy['review_policy'],
            $copy['opens_at'], $copy['closes_at'], $copy['course_id']]);
        self::assertSame(3, (int) $this->value('SELECT COUNT(*) FROM assessment_items WHERE assessment_id = ?', [(int) $copy['id']]));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM assessment_items WHERE assessment_id = ? AND question_version_id IS NOT NULL', [(int) $copy['id']]),
            'the copy is fixed to versions again when it is published');
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM assessment_assignments WHERE assessment_id = ?', [(int) $copy['id']]), 'set for nobody');
        self::assertSame('published', $this->value('SELECT status FROM assessments WHERE uuid = ?', [$uuid]), 'the original is untouched');
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'institution.assessment_duplicated'"));

        $this->as('bob');
        self::assertNotSame(303, $this->post($path . '/duplicate')->status, 'an instructor cannot copy an assessment he cannot see');
        $this->as('carol');
        self::assertNotSame(303, $this->post($path . '/duplicate')->status);
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM assessments'));
    }
}
