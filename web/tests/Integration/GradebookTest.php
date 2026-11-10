<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/**
 * A course's gradebook: online and offline items with weights, marks typed and uploaded (kept
 * when changed), released marks and published results as learners see them, a grade set by hand
 * with its reason, final grades, and the exports.
 */
final class GradebookTest extends HttpTestCase
{
    use InstitutionFixtures;

    private string $inst;
    private string $test;
    private string $testUuid;
    private string $course;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->inst, $this->test, $this->testUuid] = $this->sittingScenario();
        $class = (string) $this->value('SELECT uuid FROM institution_classes');
        $this->post('/institutions/' . $this->inst . '/courses', ['title' => 'Routing and switching', 'class' => $class, 'pass_percent' => '50']);
        $this->course = '/institutions/' . $this->inst . '/courses/' . $this->value('SELECT uuid FROM courses');
        $this->post($this->course . '/assessments', ['assessment' => $this->testUuid]);
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

    private function sit(string $who, array $answers): void
    {
        $this->as($who);
        $r = $this->post('/my/assessments/' . $this->inst . '/' . $this->testUuid . '/start', ['accept' => '1']);
        $uuid = substr((string) $r->headers['Location'], 5);
        $seq = 1;
        foreach ($answers as $text => $answer) {
            $pos = (int) $this->value('SELECT q.position FROM assessment_session_questions q JOIN assessment_sessions s ON s.id = q.session_id
                JOIN question_versions v ON v.id = q.question_version_id WHERE s.uuid = ? AND v.text LIKE ?', [$uuid, $text . '%']);
            $this->postJson('/api/sit/' . $uuid . '/answer', ['position' => (string) $pos, 'answer' => $answer, 'seq' => (string) $seq++]);
        }
        $this->post('/sit/' . $uuid . '/submit');
    }

    /** @return array{name:string,tmp_name:string,size:int,error:int} */
    private function upload(string $content): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'mk');
        file_put_contents($tmp, $content);
        return ['name' => 'marks.csv', 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }

    private function member(string $username): string
    {
        return $this->memberUuid($this->inst, $username);
    }

    public function testTheGradebookFromMarksToFinalGrades(): void
    {
        // carol 100%, dan 2.5 of 6.
        $this->sit('carol', ['Which layer' => 'B', 'Which are routing' => 'AC', 'OSPF is' => 'T']);
        $this->sit('dan', ['Which layer' => 'A', 'Which are routing' => 'A', 'OSPF is' => 'T']);
        $dan = round((float) $this->value("SELECT percent FROM assessment_sessions s JOIN institution_memberships m ON m.id = s.membership_id
            JOIN users u ON u.id = m.user_id WHERE u.username = 'dan'"), 2);

        $this->as('olive');
        $this->post($this->course . '/grades', ['kind' => 'online', 'assessment' => $this->testUuid, 'weight' => '2']);
        $this->post($this->course . '/grades', ['kind' => 'online', 'assessment' => $this->testUuid, 'weight' => '2']);
        self::assertStringContainsString('already in the gradebook', $this->message());
        $this->post($this->course . '/grades', ['kind' => 'offline', 'title' => 'Practical', 'max_marks' => '20', 'weight' => '0']);
        self::assertStringContainsString('The weight is a number', $this->message());
        $this->post($this->course . '/grades', ['kind' => 'offline', 'title' => 'Practical', 'max_marks' => '20', 'weight' => '1']);
        $item = $this->course . '/grades/' . $this->value("SELECT uuid FROM grade_items WHERE title = 'Practical'");

        // Marks: all checked before any is saved.
        $this->post($item . '/marks', ['marks' => [$this->member('carol') => '18', $this->member('dan') => '25'], 'feedback' => [$this->member('carol') => 'Neat cabling.']]);
        self::assertStringContainsString('Nothing was saved.', $this->message());
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM grade_marks'));
        $this->post($item . '/marks', ['marks' => [$this->member('carol') => '18', $this->member('dan') => ''], 'feedback' => [$this->member('carol') => 'Neat cabling.']]);
        self::assertStringContainsString('One mark saved.', $this->message());

        // Staff see everything: carol (2 x 100 + 1 x 90) / 3; dan only the online item so far.
        $book = $this->get($this->course, ['tab' => 'grades'])->body;
        self::assertStringContainsString('96.67%', $book);
        self::assertStringContainsString(\App\Services\Gradebook::fmt($dan) . '%', $book);
        self::assertStringContainsString('Provisional', $book);

        // A learner sees nothing that has not been released or published.
        $this->as('carol');
        $mine = $this->get($this->course, ['tab' => 'grades'])->body;
        self::assertStringContainsString('Not out yet', $mine);
        self::assertStringContainsString('Course grade: not yet', $mine);
        self::assertStringNotContainsString('Neat cabling.', $mine);
        self::assertStringNotContainsString('>dan<', $mine);
        self::assertSame(403, $this->get($item)->status, 'learners do not open the marks page');
        self::assertSame(403, $this->get($this->course . '/grades.csv')->status);

        $this->as('olive');
        $this->post($this->test . '/results/publication', ['action' => 'publish']);
        $this->post($item . '/release');
        self::assertStringContainsString('2 were told', $this->message());
        $this->as('carol');
        $mine = $this->get($this->course, ['tab' => 'grades'])->body;
        self::assertStringContainsString('Course grade: 96.67%', $mine);
        self::assertStringContainsString('Neat cabling.', $mine);
        self::assertStringContainsString('Passed', $mine);
        self::assertStringStartsWith('%PDF', $this->get($this->course . '/grades.pdf')->body);

        // Uploading: every row checked, then saved; a change keeps the mark it replaces.
        $this->as('olive');
        $this->postFiles($item . '/import', [], ['file' => $this->upload("student,marks,feedback\r\nnobody,5,\r\ndan,10,Late\r\n")]);
        self::assertStringContainsString('"nobody" is not a learner', $this->message());
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM grade_marks'));
        $this->postFiles($item . '/import', [], ['file' => $this->upload("student,marks,feedback\r\ndan,10,Late\r\ncarol,19,Neat cabling.\r\n")]);
        self::assertStringContainsString('2 marks saved.', $this->message());
        self::assertSame(3, (int) $this->value('SELECT COUNT(*) FROM grade_marks'));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM grade_marks WHERE superseded_at IS NOT NULL'), 'carol\'s 18 is kept, superseded');
        $this->post($item . '/marks', ['marks' => [$this->member('carol') => '19', $this->member('dan') => '10'], 'feedback' => [$this->member('carol') => 'Neat cabling.', $this->member('dan') => 'Late']]);
        self::assertStringContainsString('Nothing had changed.', $this->message());

        // A grade by hand needs a reason, and shows beside the worked-out one.
        $this->post($this->course . '/grades/overrides', ['learner' => $this->member('dan'), 'percent' => '55', 'reason' => '']);
        self::assertStringContainsString('Give the reason', $this->message());
        $this->post($this->course . '/grades/overrides', ['learner' => $this->member('fay'), 'percent' => '55', 'reason' => 'x']);
        self::assertStringContainsString('course\'s learners', $this->message(), 'only the course\'s learners');
        $this->post($this->course . '/grades/overrides', ['learner' => $this->member('dan'), 'percent' => '55', 'reason' => 'Practical retaken on paper.']);
        self::assertStringContainsString('set by hand', $this->get($this->course, ['tab' => 'grades'])->body);
        $this->as('dan');
        self::assertStringContainsString('Course grade: 55%', $this->get($this->course, ['tab' => 'grades'])->body);

        // CSV for staff.
        $this->as('olive');
        $csv = $this->get($this->course . '/grades.csv')->body;
        self::assertStringContainsString('Practical (out of 20, weight 1)', $csv);
        self::assertStringContainsString('55,"Practical retaken on paper.",55,yes,provisional', $csv);
        self::assertStringStartsWith('%PDF', $this->get($this->course . '/grades.pdf')->body);

        // Final: marks stop changing, learners are told; provisional again undoes it.
        $this->post($this->course . '/grades/final', ['final' => '1']);
        self::assertStringContainsString('The grades are final.', $this->message());
        $this->post($item . '/marks', ['marks' => [$this->member('carol') => '20']]);
        self::assertStringContainsString('The grades are final', $this->message());
        self::assertStringContainsString(',final', $this->get($this->course . '/grades.csv')->body);
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM notifications WHERE title = 'Final grades: Routing and switching'"));
        $this->post($this->course . '/grades/final', ['final' => '0']);
        $this->post($item . '/marks', ['marks' => [$this->member('carol') => '20']]);
        self::assertStringContainsString('One mark saved.', $this->message());

        // Someone outside the course.
        $this->as('fay');
        self::assertSame(403, $this->get($this->course, ['tab' => 'grades'])->status);
        self::assertSame(403, $this->get($this->course . '/grades.pdf')->status);

        foreach (['institution.grade_item_added', 'institution.grade_marks_entered', 'institution.grade_marks_released', 'institution.grade_override_set',
            'institution.grades_finalised', 'institution.grades_reopened'] as $action) {
            self::assertGreaterThan(0, (int) $this->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]), $action);
        }
    }

    public function testFinalGradesCountAnUnmarkedItemAsZero(): void
    {
        $this->sit('carol', ['Which layer' => 'B', 'Which are routing' => 'AC', 'OSPF is' => 'T']);
        $this->as('olive');
        $this->post($this->course . '/grades', ['kind' => 'online', 'assessment' => $this->testUuid, 'weight' => '1']);
        $this->post($this->course . '/grades', ['kind' => 'offline', 'title' => 'Practical', 'max_marks' => '10', 'weight' => '1']);
        self::assertStringContainsString('100%', $this->get($this->course, ['tab' => 'grades'])->body, 'provisional: only what is marked counts');
        $this->post($this->course . '/grades/final', ['final' => '1']);
        $book = $this->get($this->course, ['tab' => 'grades'])->body;
        self::assertStringContainsString('50%', $book, 'final: the unmarked practical counts as zero');
        self::assertStringContainsString('an item with no mark counts as zero', $book);

        // Changing the weights or taking an item out is for provisional grades only, and keeps marks.
        $this->post($this->course . '/grades/final', ['final' => '0']);
        $item = (string) $this->value("SELECT uuid FROM grade_items WHERE title = 'Practical'");
        $this->post($this->course . '/grades/' . $item . '/remove');
        self::assertNotNull($this->value("SELECT removed_at FROM grade_items WHERE title = 'Practical'"));
        self::assertStringNotContainsString('>Practical<', $this->get($this->course, ['tab' => 'grades'])->body);
    }
}
