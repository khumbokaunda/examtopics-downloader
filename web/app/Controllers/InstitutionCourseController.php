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
    private const TABS = ['overview' => 'Overview', 'assessments' => 'Assessments', 'participants' => 'Participants'];

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
        foreach (['title', 'summary', 'class', 'module', 'lead', 'starts_on', 'ends_on'] as $f) {
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
