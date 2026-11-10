<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\InstitutionAccess;
use App\Services\InstitutionStructure;
use RuntimeException;

/**
 * Courses: the list, one course (overview with its notices, its assessments, its participants)
 * and the changes to it. The rules of who sees and changes a course are in App\Services\Courses.
 */
final class InstitutionCourseController extends TenantController
{
    private const TABS = ['overview' => 'Overview', 'materials' => 'Materials', 'assignments' => 'Assignments', 'assessments' => 'Assessments', 'grades' => 'Grades',
        'participants' => 'Participants'];

    public function index(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, null);
        $courses = $this->s->courses();
        $iid = (int) $i['id'];
        $archived = $this->request->query('status') === 'archived';
        $create = $courses->canCreate($m);
        return $this->render('institutions/courses', $this->pageVars($i, $m, 'courses') + [
            'courses' => $courses->visibleTo($iid, $m, $archived ? 'archived' : 'active'), 'archived' => $archived, 'create' => $create,
            'classes' => $create ? $courses->classesFor($iid, $m) : [],
            'modules' => $create ? $this->s->records()->allModules($iid) : [],
            'teachers' => $create ? $this->teachers($iid) : [],
        ]);
    }

    public function create(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, null);
        if (!$this->s->courses()->canCreate($m)) {
            throw new HttpException(403, 'Your role in ' . $i['name'] . ' does not include starting courses.');
        }
        try {
            $uuid = $this->s->courses()->save($i, $m, null, $this->fields());
            $this->flash('success', 'Course created. Add its assessments and post a notice here.');
            return $this->redirect('/institutions/' . $i['uuid'] . '/courses/' . $uuid);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/institutions/' . $i['uuid'] . '/courses');
        }
    }

    public function show(string $ref, string $course): Response
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, false);
        $courses = $this->s->courses();
        $iid = (int) $i['id'];
        $manage = $courses->canManage($m, $c);
        $learner = !$manage && $courses->learns($m, $c);
        $view = (string) $this->request->query('tab', 'overview');
        $view = isset(self::TABS[$view]) ? $view : 'overview';
        $vars = $this->pageVars($i, $m, 'courses') + ['course' => $c, 'view' => $view, 'views' => self::TABS, 'manage' => $manage, 'learner' => $learner,
            'crumb' => ['Courses' => '/institutions/' . $i['uuid'] . '/courses', $c['title'] => null]];
        if ($view === 'materials') {
            $groups = [];
            foreach ($this->s->coursework()->materials($iid, (int) $c['id']) as $mat) {
                $groups[(string) $mat['heading']][] = $mat;
            }
            return $this->render('institutions/course', $vars + ['groups' => $groups]);
        }
        if ($view === 'assignments') {
            $cw = $this->s->coursework();
            $list = $cw->assignments($iid, (int) $c['id'], !$manage);
            $mine = [];
            foreach ($learner ? $list : [] as $w) {
                $mine[(int) $w['id']] = [$cw->submissionOf($iid, (int) $w['id'], (int) $m['id']), $w['released_at'] !== null ? $cw->markOf($iid, (int) $w['id'], (int) $m['id']) : null];
            }
            return $this->render('institutions/course', $vars + ['assignments' => $list, 'mine' => $mine, 'timezone' => $this->timezone()]);
        }
        if ($view === 'assessments') {
            $mine = [];
            foreach ($learner ? $this->s->sittings()->repo()->assignedTo((int) $this->auth->user()['id'], (string) $i['uuid']) : [] as $a) {
                if ((int) ($a['course_id'] ?? 0) === (int) $c['id']) {
                    $mine[] = $a;
                }
            }
            $attachable = [];
            foreach ($manage ? $this->s->assessments()->repo()->all($iid) : [] as $a) {
                if ($a['course_id'] === null && in_array($a['status'], ['draft', 'published'], true) && $this->s->assessments()->canSee($m, $a)) {
                    $attachable[] = $a;
                }
            }
            return $this->render('institutions/course', $vars + ['assessments' => $learner ? [] : $courses->assessments($iid, (int) $c['id']), 'mine' => $mine,
                'attachable' => $attachable, 'canBuild' => InstitutionAccess::can($m, 'assessments.create')]);
        }
        if ($view === 'grades') {
            $gb = $this->s->gradebook();
            $book = $gb->book($iid, $c, $learner, $learner ? (int) $m['id'] : null);
            $online = [];
            foreach ($manage ? $courses->assessments($iid, (int) $c['id']) : [] as $a) {
                $online[] = $a;
            }
            $inBook = array_flip(array_filter(array_map(static fn(array $g) => $g['assessment_id'] !== null ? (int) $g['assessment_id'] : null, $book['items'])));
            $taken = array_flip(array_filter(array_map(static fn(array $g) => $g['assignment_id'] !== null ? (int) $g['assignment_id'] : null, $book['items'])));
            return $this->render('institutions/course', $vars + ['book' => $book,
                'online' => array_values(array_filter($online, static fn(array $a): bool => !isset($inBook[(int) $a['id']]))),
                'freeAssignments' => $manage ? array_values(array_filter($this->s->coursework()->assignments($iid, (int) $c['id']), static fn(array $w): bool => !isset($taken[(int) $w['id']]))) : []]);
        }
        if ($view === 'participants') {
            $repo = $this->s->structure()->repo();
            return $this->render('institutions/course', $vars + ['instructors' => $repo->instructors($iid, (int) $c['class_id']),
                'students' => $learner ? [] : $repo->classStudents($iid, (int) $c['class_id'])]);
        }
        return $this->render('institutions/course', $vars + ['announcements' => $courses->announcements($iid, (int) $c['id']),
            'modules' => $manage ? $this->s->records()->allModules($iid) : [], 'teachers' => $manage ? $this->teachers($iid) : []]);
    }

    public function update(string $ref, string $course): Response
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, true);
        return $this->attempt($i, $c, fn() => $this->s->courses()->save($i, $m, $c, $this->fields()), 'Course saved.');
    }

    public function archive(string $ref, string $course): Response
    {
        [$i, , $c] = $this->courseFor($ref, $course, true);
        $archive = $c['status'] === 'active';
        return $this->attempt($i, $c, fn() => $this->s->courses()->setArchived($i, $c, $archive),
            $archive ? 'Course archived. Its notices and assessments stay.' : 'Course restored.');
    }

    public function announce(string $ref, string $course): Response
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, true);
        return $this->attempt($i, $c, function () use ($i, $m, $c): void {
            $n = $this->s->courses()->announce($i, $m, $c, (string) $this->request->input('title', ''), (string) $this->request->input('body', ''));
            $this->flash('success', 'Posted. ' . ($n === 1 ? 'One learner was' : $n . ' learners were') . ' told.');
        });
    }

    public function removeNotice(string $ref, string $course, string $notice): Response
    {
        [$i, , $c] = $this->courseFor($ref, $course, true);
        return $this->attempt($i, $c, fn() => $this->s->courses()->removeAnnouncement($i, $c, $notice), 'Notice removed.');
    }

    /** Put an assessment in the course and, where the member may, set it for the course's class. */
    public function addAssessment(string $ref, string $course): Response
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, true);
        return $this->attempt($i, $c, function () use ($i, $m, $c): void {
            $service = $this->s->assessments();
            $a = $service->repo()->find((int) $i['id'], (string) $this->request->input('assessment', ''));
            if ($a === null || !$service->canSee($m, $a) || !in_array($a['status'], ['draft', 'published'], true)) {
                throw new RuntimeException('Choose one of the assessments in the list.');
            }
            if ($a['course_id'] !== null) {
                throw new RuntimeException('That assessment already belongs to a course.');
            }
            $this->s->courses()->link($i, $c, $a);
            $assigned = false;
            if ($service->canAssign($m, $a)) {
                try {
                    $service->assign($i, $m, $a, (string) $c['class_uuid'], '');
                    $assigned = true;
                } catch (RuntimeException) {
                    // Linked all the same: someone who may assign it can set it for the class.
                }
            }
            $this->flash('success', $a['title'] . ' is in the course' . ($assigned ? ' and set for ' . $c['class_name'] . '.' : '. Assign it to the class from its own page.'));
        }, null, 'assessments');
    }

    public function removeAssessment(string $ref, string $course, string $assessment): Response
    {
        [$i, , $c] = $this->courseFor($ref, $course, true);
        return $this->attempt($i, $c, function () use ($i, $c, $assessment): void {
            $a = $this->s->assessments()->repo()->find((int) $i['id'], $assessment);
            if ($a === null || (int) ($a['course_id'] ?? 0) !== (int) $c['id']) {
                throw new RuntimeException('That assessment is not in this course.');
            }
            $this->s->courses()->link($i, null, $a);
            $this->flash('success', $a['title'] . ' was taken out of the course. Who it is set for has not changed.');
        }, null, 'assessments');
    }

    // Gradebook --------------------------------------------------------------------------------

    public function addGradeItem(string $ref, string $course): Response
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, true);
        return $this->attempt($i, $c, fn() => $this->s->gradebook()->addItem($i, $m, $c, ['kind' => (string) $this->request->input('kind', ''),
            'assessment' => (string) $this->request->input('assessment', ''), 'assignment' => (string) $this->request->input('assignment', ''), 'title' => (string) $this->request->input('title', ''),
            'max_marks' => (string) $this->request->input('max_marks', ''), 'weight' => (string) $this->request->input('weight', '')]), 'Grade item added.', 'grades');
    }

    public function saveGradeItem(string $ref, string $course, string $gradeitem): Response
    {
        [$i, , $c, $g] = $this->itemFor($ref, $course, $gradeitem);
        return $this->attempt($i, $c, fn() => $this->s->gradebook()->saveItem($i, $c, $g, ['title' => (string) $this->request->input('title', ''),
            'max_marks' => (string) $this->request->input('max_marks', ''), 'weight' => (string) $this->request->input('weight', '')]), 'Grade item saved.', 'grades');
    }

    public function removeGradeItem(string $ref, string $course, string $gradeitem): Response
    {
        [$i, , $c, $g] = $this->itemFor($ref, $course, $gradeitem);
        return $this->attempt($i, $c, fn() => $this->s->gradebook()->removeItem($i, $c, $g), 'Grade item taken out. Its marks are kept.', 'grades');
    }

    /** The marks of one offline item, to enter or upload. */
    public function gradeItem(string $ref, string $course, string $gradeitem): Response
    {
        [$i, $m, $c, $g] = $this->itemFor($ref, $course, $gradeitem);
        if ($g['assessment_id'] !== null) {
            return $this->redirect('/institutions/' . $i['uuid'] . '/assessments/' . $g['assessment_uuid'] . '/results');
        }
        $gb = $this->s->gradebook();
        return $this->render('institutions/grade_item', $this->pageVars($i, $m, 'courses') + ['course' => $c, 'item' => $g,
            'learners' => $gb->learners((int) $i['id'], (int) $c['class_id']), 'marks' => $gb->marks((int) $i['id'], (int) $g['id']),
            'crumb' => ['Courses' => '/institutions/' . $i['uuid'] . '/courses', $c['title'] => '/institutions/' . $i['uuid'] . '/courses/' . $c['uuid'] . '?tab=grades', $g['title'] => null]]);
    }

    public function enterMarks(string $ref, string $course, string $gradeitem): Response
    {
        [$i, $m, $c, $g] = $this->itemFor($ref, $course, $gradeitem);
        $entries = [];
        $marks = (array) ($this->request->post['marks'] ?? []);
        $feedback = (array) ($this->request->post['feedback'] ?? []);
        foreach ($this->s->gradebook()->learners((int) $i['id'], (int) $c['class_id']) as $l) {
            $key = (string) $l['membership_uuid'];
            if (array_key_exists($key, $marks) || array_key_exists($key, $feedback)) {
                $entries[(int) $l['membership_id']] = ['marks' => is_string($marks[$key] ?? null) ? $marks[$key] : '',
                    'feedback' => is_string($feedback[$key] ?? null) ? $feedback[$key] : '', 'name' => \App\Services\Access::displayName($l)];
            }
        }
        return $this->marksSaved($i, $m, $c, $g, $entries, 'typed');
    }

    public function importMarks(string $ref, string $course, string $gradeitem): Response
    {
        [$i, $m, $c, $g] = $this->itemFor($ref, $course, $gradeitem);
        $file = $this->request->file('file');
        try {
            if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a CSV file to upload.');
            }
            $entries = $this->s->gradebook()->parseMarks((int) $i['id'], $c, (string) file_get_contents($file['tmp_name']));
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/institutions/' . $i['uuid'] . '/courses/' . $c['uuid'] . '/grades/' . $g['uuid']);
        }
        return $this->marksSaved($i, $m, $c, $g, $entries, 'uploaded');
    }

    public function releaseMarks(string $ref, string $course, string $gradeitem): Response
    {
        [$i, , $c, $g] = $this->itemFor($ref, $course, $gradeitem);
        try {
            $n = $this->s->gradebook()->release($i, $c, $g);
            $this->flash('success', 'The marks are shown to the learners. ' . ($n === 1 ? 'One was' : $n . ' were') . ' told.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/courses/' . $c['uuid'] . '/grades/' . $g['uuid']);
    }

    public function overrideGrade(string $ref, string $course): Response
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, true);
        return $this->attempt($i, $c, function () use ($i, $m, $c): void {
            $learner = $this->s->institutions()->repo()->member((int) $i['id'], (string) $this->request->input('learner', ''));
            $inCourse = $learner !== null && $this->s->structure()->repo()->activeEnrolment((int) $i['id'], (int) $c['class_id'], (int) $learner['id']) !== null;
            if (!$inCourse) {
                throw new RuntimeException('Choose one of the course\'s learners.');
            }
            $this->s->gradebook()->override($i, $m, $c, $learner, (string) $this->request->input('percent', ''), (string) $this->request->input('reason', ''));
            $this->flash('success', trim((string) $this->request->input('percent', '')) === '' ? 'The worked-out grade applies again.' : 'Grade set. The reason is kept with it.');
        }, null, 'grades');
    }

    public function finalGrades(string $ref, string $course): Response
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, true);
        return $this->attempt($i, $c, function () use ($i, $m, $c): void {
            $final = $this->request->input('final') === '1';
            $n = $this->s->gradebook()->setFinal($i, $m, $c, $final);
            $this->flash('success', $final ? 'The grades are final. ' . ($n === 1 ? 'One learner was' : $n . ' learners were') . ' told.' : 'The grades are provisional again.');
        }, null, 'grades');
    }

    public function gradesCsv(string $ref, string $course): Response
    {
        [$i, , $c] = $this->courseFor($ref, $course, true);
        $name = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', strtolower((string) $c['title'])), '-') ?: 'course';
        return new Response(\App\Services\Gradebook::csv($this->s->gradebook()->book((int) $i['id'], $c)), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '-grades.csv"']);
    }

    public function gradesPdf(string $ref, string $course): Response
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, false);
        $manage = $this->s->courses()->canManage($m, $c);
        if (!$manage && !$this->s->courses()->learns($m, $c)) {
            throw new HttpException(403, 'Only the course\'s instructors and its learners can download its grades.');
        }
        $book = $this->s->gradebook()->book((int) $i['id'], $c, !$manage, $manage ? null : (int) $m['id']);
        $html = (new \App\Core\View($this->app, $this->session, $this->auth->user()))->render('institutions/grades_pdf', ['institution' => $i, 'course' => $c,
            'book' => $book, 'learner' => !$manage], null);
        $name = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', strtolower((string) $c['title'])), '-') ?: 'course';
        return new Response(\App\Services\Results::pdf($html, $this->app->paths()->storage() . '/tmp/dompdf', count($book['items']) > 5 ? 'landscape' : 'portrait'), 200,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $name . '-grades.pdf"']);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $c @param array<string,mixed> $g @param array<int,array<string,string>> $entries */
    private function marksSaved(array $i, array $m, array $c, array $g, array $entries, string $how): Response
    {
        try {
            $n = $this->s->gradebook()->enter($i, $m, $c, $g, $entries, $how);
            $this->flash('success', $n === 0 ? 'Nothing had changed.' : ($n === 1 ? 'One mark saved.' : $n . ' marks saved.')
                . ($g['released_at'] === null && $n > 0 ? ' Learners see them once you release them.' : ''));
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/courses/' . $c['uuid'] . '/grades/' . $g['uuid']);
    }

    /**
     * The course and one of its grade items, for someone who runs the course.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>,3:array<string,mixed>}
     */
    private function itemFor(string $ref, string $course, string $gradeitem): array
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, true);
        $g = $this->s->gradebook()->item((int) $i['id'], (int) $c['id'], $gradeitem) ?? throw HttpException::notFound('Grade item');
        return [$i, $m, $c, $g];
    }

    /**
     * The institution, membership and course, for someone who may see it (or change it).
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function courseFor(string $ref, string $course, bool $manage): array
    {
        [$i, $m] = $this->tenant($ref, null);
        $c = $this->s->courses()->find((int) $i['id'], $course) ?? throw HttpException::notFound('Course');
        if ($manage ? !$this->s->courses()->canManage($m, $c) : !$this->s->courses()->canSee($m, $c)) {
            throw new HttpException(403, $manage ? 'Only the course\'s instructors, and people who manage classes, can change it.'
                : 'Only the course\'s learners and instructors, and people who can see every class, can open it.');
        }
        return [$i, $m, $c];
    }

    /** @return array<string,mixed> */
    private function fields(): array
    {
        $in = [];
        foreach (['title', 'summary', 'class', 'module', 'lead', 'starts_on', 'ends_on', 'pass_percent'] as $f) {
            $in[$f] = (string) $this->request->input($f, '');
        }
        return $in;
    }

    /** @return list<array<string,mixed>> the institution's teaching staff, to choose a lead instructor from */
    private function teachers(int $iid): array
    {
        return array_values(array_filter($this->s->institutions()->repo()->members($iid),
            static fn(array $p): bool => in_array($p['role'], InstitutionStructure::TEACHING_ROLES, true)));
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $c */
    private function attempt(array $i, array $c, \Closure $fn, ?string $ok = null, string $tab = ''): Response
    {
        try {
            $fn();
            if ($ok !== null) {
                $this->flash('success', $ok);
            }
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/courses/' . $c['uuid'], $tab !== '' ? ['tab' => $tab] : []);
    }
}
