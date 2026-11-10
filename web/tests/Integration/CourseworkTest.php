<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/**
 * Course materials, assignments with file submissions (checked by content, late work, handing in
 * again), marking with a rubric, releasing marks, and the gradebook taking assignment marks.
 */
final class CourseworkTest extends HttpTestCase
{
    use InstitutionFixtures;

    private string $inst;
    private string $course;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->inst] = $this->sittingScenario();
        $class = (string) $this->value('SELECT uuid FROM institution_classes');
        $this->post('/institutions/' . $this->inst . '/courses', ['title' => 'Routing and switching', 'class' => $class]);
        $this->course = '/institutions/' . $this->inst . '/courses/' . $this->value('SELECT uuid FROM courses');
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

    /** @return array{name:string,tmp_name:string,size:int,error:int} */
    private function upload(string $name, string $content): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'cw');
        file_put_contents($tmp, $content);
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    public function testMaterialsAreFilesAndLinks(): void
    {
        $this->postFiles($this->course . '/materials', ['heading' => 'Week 1', 'title' => 'Slides'], ['file' => $this->upload('slides.pdf', $this->pdf())]);
        $this->post($this->course . '/materials', ['heading' => 'Week 1', 'title' => 'Subnetting video', 'url' => 'https://www.youtube.com/watch?v=abc']);
        $this->post($this->course . '/materials', ['heading' => 'Week 1', 'title' => 'Bad link', 'url' => 'javascript:alert(1)']);
        self::assertStringContainsString('Give the full address', $this->message());
        $this->postFiles($this->course . '/materials', ['title' => 'Script'], ['file' => $this->upload('run.pdf', "<?php echo 'x';")]);
        self::assertStringContainsString('is not one of the kinds accepted', $this->message(), 'judged by content, not by name');
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM course_materials'));

        $this->as('carol');
        $page = $this->get($this->course, ['tab' => 'materials'])->body;
        self::assertStringContainsString('Week 1', $page);
        self::assertStringContainsString('href="https://www.youtube.com/watch?v=abc"', $page, 'video is a link, never embedded');
        self::assertStringNotContainsString('<iframe', $page);
        self::assertStringNotContainsString('Add a file or link', $page);
        $file = $this->get($this->course . '/materials/' . $this->value("SELECT uuid FROM course_materials WHERE kind = 'file'"));
        self::assertSame(200, $file->status);
        self::assertSame(403, $this->post($this->course . '/materials', ['title' => 'Mine', 'url' => 'https://example.org/x'])->status);
        $this->as('fay');
        self::assertSame(403, $this->get($this->course . '/materials/' . $this->value("SELECT uuid FROM course_materials WHERE kind = 'file'"))->status);
    }

    public function testAnAssignmentFromSettingToMarksInTheGradebook(): void
    {
        $this->post($this->course . '/assignments/new', ['title' => 'Lab report', 'instructions' => 'Write up the OSPF lab.', 'max_marks' => '20',
            'file_kinds' => ['pdf'], 'max_files' => '2', 'max_mb' => '5', 'late_policy' => 'accept', 'due_at' => gmdate('Y-m-d\TH:i', time() + 3 * 86400)]);
        $uuid = (string) $this->value('SELECT uuid FROM assignments');
        $path = $this->course . '/assignments/' . $uuid;

        // A draft is not shown to learners.
        $this->as('carol');
        self::assertSame(404, $this->get($path)->status);
        self::assertStringNotContainsString('Lab report', $this->get($this->course, ['tab' => 'assignments'])->body);

        // A rubric, then opening it tells the learners.
        $this->as('olive');
        $this->post($path . '/rubric', ['criterion' => ['Method', 'Results', ''], 'criterion_marks' => ['8', '12', ''], 'criterion_description' => ['Steps are clear', '', '']]);
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM assignment_criteria'));
        self::assertSame('20.00', $this->value('SELECT max_marks FROM assignments'));
        $this->post($path . '/status', ['to' => 'published']);
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM notifications WHERE title = 'New assignment: Lab report'"));

        // carol hands in; a wrong kind of file is refused; handing in again keeps the first.
        $this->as('carol');
        $page = $this->get($path)->body;
        self::assertStringContainsString('Write up the OSPF lab.', $page);
        self::assertStringContainsString('Hand in', $page);
        self::assertMatchesRegularExpression('#Lab report</a></div>\s*<div class="row-meta">Due #', $this->get('/')->body, 'on home until handed in');
        $this->postFiles($path . '/submit', [], ['files' => $this->upload('report.pdf', 'not a pdf at all')]);
        self::assertStringContainsString('is not one of the kinds accepted', $this->message());
        $this->postFiles($path . '/submit', ['note' => 'First go.'], ['files' => $this->upload('report.pdf', $this->pdf())]);
        self::assertSame('Handed in.', $this->message());
        $this->postFiles($path . '/submit', [], ['files' => $this->upload('report-v2.pdf', $this->pdf())]);
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM assignment_submissions'));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM assignment_submissions WHERE superseded_at IS NULL'));
        self::assertStringContainsString('report-v2.pdf', $this->get($path)->body);
        self::assertDoesNotMatchRegularExpression('#Lab report</a></div>\s*<div class="row-meta">Due #', $this->get('/')->body, 'handed in: off the list');
        $own = (string) $this->value('SELECT f.uuid FROM submission_files f JOIN assignment_submissions s ON s.id = f.submission_id WHERE s.superseded_at IS NULL');
        self::assertSame(200, $this->get($path . '/files/' . $own)->status);

        // dan hands in after the deadline: accepted, marked late. Then the deadline refuses late work.
        $this->app->db()->run('UPDATE assignments SET due_at = ?', [gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->as('dan');
        self::assertSame(404, $this->get($path . '/files/' . $own)->status, 'nobody else\'s work');
        $this->postFiles($path . '/submit', [], ['files' => $this->upload('dan.pdf', $this->pdf())]);
        self::assertStringContainsString('marked late', $this->message());
        self::assertSame(1, (int) $this->value("SELECT late FROM assignment_submissions s JOIN institution_memberships m ON m.id = s.membership_id JOIN users u ON u.id = m.user_id WHERE u.username = 'dan'"));
        $this->app->db()->run("UPDATE assignments SET late_policy = 'refuse'");
        $this->postFiles($path . '/submit', [], ['files' => $this->upload('dan2.pdf', $this->pdf())]);
        self::assertStringContainsString('late work is not accepted', $this->message());
        self::assertSame(403, $this->get($path . '/submissions/' . $this->memberUuid($this->inst, 'carol'))->status, 'learners do not mark');

        // Marking with the rubric: each criterion within its marks, the total is the mark; a draft until released.
        $this->as('olive');
        $staff = $this->get($path)->body;
        self::assertStringContainsString('Handed in: 2 of 2', $staff);
        $carol = $path . '/submissions/' . $this->memberUuid($this->inst, 'carol');
        self::assertStringContainsString('report-v2.pdf', $this->get($carol)->body);
        $criteria = array_column($this->app->db()->all('SELECT id FROM assignment_criteria ORDER BY position'), 'id');
        $this->post($carol, ['criteria' => [(string) $criteria[0] => '9', (string) $criteria[1] => '10'], 'feedback' => 'Good method.']);
        self::assertStringContainsString('Method: give a mark from 0 to 8', $this->message());
        $this->post($carol, ['criteria' => [(string) $criteria[0] => '7', (string) $criteria[1] => '10'], 'feedback' => 'Good method.']);
        self::assertSame('17.00', $this->value('SELECT marks FROM assignment_marks WHERE superseded_at IS NULL'));
        $this->post($carol, ['criteria' => [(string) $criteria[0] => '8', (string) $criteria[1] => '10'], 'feedback' => 'Good method.']);
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM assignment_marks'), 'the first mark is kept');
        $this->post($path . '/rubric', ['criterion' => ['Only one'], 'criterion_marks' => ['20']]);
        self::assertStringContainsString('Marking has started', $this->message());

        // The gradebook takes the mark; learners see it once released.
        $this->post($this->course . '/grades', ['kind' => 'assignment', 'assignment' => $uuid, 'weight' => '1']);
        self::assertStringContainsString('90%', $this->get($this->course, ['tab' => 'grades'])->body, '18 of 20');
        $this->as('carol');
        self::assertStringNotContainsString('Good method.', $this->get($path)->body, 'not out yet');
        self::assertStringContainsString('Course grade: not yet', $this->get($this->course, ['tab' => 'grades'])->body);
        $this->as('olive');
        $this->post($path . '/release');
        self::assertStringContainsString('2 learners were told', $this->message());
        $this->as('carol');
        $mine = $this->get($path)->body;
        self::assertStringContainsString('18 of 20', $mine);
        self::assertStringContainsString('Good method.', $mine);
        self::assertStringContainsString('Method: 8 of 8', $mine);
        self::assertStringContainsString('Course grade: 90%', $this->get($this->course, ['tab' => 'grades'])->body);
        $this->postFiles($path . '/submit', [], ['files' => $this->upload('late.pdf', $this->pdf())]);
        self::assertStringContainsString('closed', $this->message(), 'nothing more once the marks are out');

        foreach (['institution.assignment_created', 'institution.assignment_opened', 'institution.assignment_submitted', 'institution.assignment_marked', 'institution.assignment_released'] as $action) {
            self::assertGreaterThan(0, (int) $this->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]), $action);
        }
        self::assertDirectoryExists($this->app->paths()->storage() . '/institution_documents/' . $this->inst . '/coursework', 'stored where backups look');
    }
}
