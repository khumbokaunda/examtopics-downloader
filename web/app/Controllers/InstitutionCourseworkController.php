<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Access;
use RuntimeException;

/**
 * A course's materials and assignments: adding materials, setting assignments, learners handing
 * in, and marking. The rules are in App\Services\Coursework; who may see and run a course is in
 * App\Services\Courses.
 */
final class InstitutionCourseworkController extends TenantController
{
    // Materials ------------------------------------------------------------------------------

    public function addMaterial(string $ref, string $course): Response
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, true);
        try {
            $this->s->coursework()->addMaterial($i, $m, $c, ['heading' => (string) $this->request->input('heading', ''), 'title' => (string) $this->request->input('title', ''),
                'url' => (string) $this->request->input('url', '')], $this->request->file('file'));
            $this->flash('success', 'Added.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect($this->base($i, $c), ['tab' => 'materials']);
    }

    public function removeMaterial(string $ref, string $course, string $material): Response
    {
        [$i, , $c] = $this->courseFor($ref, $course, true);
        $mat = $this->s->coursework()->material((int) $i['id'], (int) $c['id'], $material) ?? throw HttpException::notFound('Material');
        $this->s->coursework()->removeMaterial($i, $c, $mat);
        $this->flash('success', $mat['title'] . ' was removed.');
        return $this->redirect($this->base($i, $c), ['tab' => 'materials']);
    }

    public function material(string $ref, string $course, string $material): Response
    {
        [$i, , $c] = $this->courseFor($ref, $course, false);
        $mat = $this->s->coursework()->material((int) $i['id'], (int) $c['id'], $material);
        $path = $mat !== null && $mat['kind'] === 'file' ? $this->s->coursework()->path($i, (string) $mat['uuid']) : null;
        if ($path === null || !is_file($path)) {
            throw HttpException::notFound('Material');
        }
        return Response::download($path, (string) $mat['filename'], (string) $mat['mime']);
    }

    // Assignments ----------------------------------------------------------------------------

    public function create(string $ref, string $course): Response
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, true);
        try {
            $uuid = $this->s->coursework()->save($i, $m, $c, null, $this->fields(), $this->timezone());
            $this->flash('success', 'Assignment saved as a draft. Open it to the learners when it is ready.');
            return $this->redirect($this->base($i, $c) . '/assignments/' . $uuid);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect($this->base($i, $c), ['tab' => 'assignments']);
        }
    }

    public function show(string $ref, string $course, string $assignment): Response
    {
        [$i, $m, $c, $a, $manage] = $this->assignmentFor($ref, $course, $assignment, false);
        $cw = $this->s->coursework();
        $iid = (int) $i['id'];
        $vars = $this->pageVars($i, $m, 'courses') + ['course' => $c, 'assignment' => $a, 'manage' => $manage, 'timezone' => $this->timezone(),
            'criteria' => $cw->criteria($iid, (int) $a['id']),
            'crumb' => ['Courses' => '/institutions/' . $i['uuid'] . '/courses', $c['title'] => $this->base($i, $c) . '?tab=assignments', $a['title'] => null]];
        if ($manage) {
            return $this->render('institutions/assignment', $vars + ['roster' => $cw->roster($iid, $c, $a),
                'marked' => (int) $this->app->db()->value('SELECT COUNT(*) FROM assignment_marks WHERE assignment_id = ?', [(int) $a['id']]) > 0]);
        }
        return $this->render('institutions/assignment', $vars + ['canSubmit' => $this->s->courses()->learns($m, $c),
            'submission' => $cw->submissionOf($iid, (int) $a['id'], (int) $m['id']),
            'attempts' => $cw->attempts($iid, (int) $a['id'], (int) $m['id']),
            'mark' => $a['released_at'] !== null ? $cw->markOf($iid, (int) $a['id'], (int) $m['id']) : null]);
    }

    public function update(string $ref, string $course, string $assignment): Response
    {
        [$i, $m, $c, $a] = $this->assignmentFor($ref, $course, $assignment, true);
        return $this->attempt($i, $c, $a, fn() => $this->s->coursework()->save($i, $m, $c, $a, $this->fields(), $this->timezone()), 'Saved.');
    }

    public function status(string $ref, string $course, string $assignment): Response
    {
        [$i, , $c, $a] = $this->assignmentFor($ref, $course, $assignment, true);
        $to = (string) $this->request->input('to', '');
        return $this->attempt($i, $c, $a, fn() => $this->s->coursework()->setStatus($i, $c, $a, $to),
            $to === 'published' ? 'Open: the learners can see it and hand in.' : 'Closed: nothing more can be handed in.');
    }

    public function rubric(string $ref, string $course, string $assignment): Response
    {
        [$i, , $c, $a] = $this->assignmentFor($ref, $course, $assignment, true);
        $rows = [];
        $titles = (array) ($this->request->post['criterion'] ?? []);
        $marks = (array) ($this->request->post['criterion_marks'] ?? []);
        $notes = (array) ($this->request->post['criterion_description'] ?? []);
        foreach ($titles as $k => $t) {
            $rows[] = ['title' => is_string($t) ? $t : '', 'max_marks' => is_string($marks[$k] ?? null) ? $marks[$k] : '', 'description' => is_string($notes[$k] ?? null) ? $notes[$k] : ''];
        }
        return $this->attempt($i, $c, $a, fn() => $this->s->coursework()->setRubric($i, $c, $a, $rows), 'Rubric saved.');
    }

    public function release(string $ref, string $course, string $assignment): Response
    {
        [$i, , $c, $a] = $this->assignmentFor($ref, $course, $assignment, true);
        return $this->attempt($i, $c, $a, function () use ($i, $c, $a): void {
            $n = $this->s->coursework()->release($i, $c, $a);
            $this->flash('success', 'The marks are out. ' . ($n === 1 ? 'One learner was' : $n . ' learners were') . ' told.');
        });
    }

    /** A learner hands in. */
    public function submit(string $ref, string $course, string $assignment): Response
    {
        [$i, $m, $c, $a, $manage] = $this->assignmentFor($ref, $course, $assignment, false);
        if ($manage || !$this->s->courses()->learns($m, $c)) {
            throw new HttpException(403, 'Only the course\'s learners hand in.');
        }
        $files = [];
        $raw = $this->request->files['files'] ?? null;
        if (is_array($raw) && is_array($raw['name'] ?? null)) {
            foreach ((array) $raw['name'] as $k => $name) {
                $files[] = ['name' => (string) $name, 'tmp_name' => (string) ($raw['tmp_name'][$k] ?? ''), 'size' => (int) ($raw['size'][$k] ?? 0), 'error' => (int) ($raw['error'][$k] ?? UPLOAD_ERR_NO_FILE)];
            }
        } elseif (($single = $this->request->file('files')) !== null) {
            $files[] = $single;
        }
        return $this->attempt($i, $c, $a, function () use ($i, $m, $c, $a, $files): void {
            $late = $this->s->coursework()->submit($i, $m, $c, $a, $files, (string) $this->request->input('note', ''));
            $this->flash($late ? 'warning' : 'success', $late ? 'Handed in, after the deadline: it is marked late.' : 'Handed in.');
        });
    }

    /** A handed-in file: for the course's staff, and for the learner who handed it in. */
    public function file(string $ref, string $course, string $assignment, string $file): Response
    {
        [$i, $m, , $a, $manage] = $this->assignmentFor($ref, $course, $assignment, false);
        $f = $this->s->coursework()->file((int) $i['id'], (int) $a['id'], $file);
        if ($f === null || (!$manage && (int) $f['membership_id'] !== (int) $m['id'])) {
            throw HttpException::notFound('File');
        }
        $path = $this->s->coursework()->path($i, (string) $f['uuid']);
        if (!is_file($path)) {
            throw HttpException::notFound('File');
        }
        return Response::download($path, (string) $f['filename'], (string) $f['mime']);
    }

    /** One learner's work, to mark. */
    public function marking(string $ref, string $course, string $assignment, string $member): Response
    {
        [$i, $m, $c, $a] = $this->assignmentFor($ref, $course, $assignment, true);
        $learner = $this->learner($i, $c, $member);
        $cw = $this->s->coursework();
        $roster = $cw->roster((int) $i['id'], $c, $a);
        $at = array_search($learner['uuid'], array_column($roster, 'membership_uuid'), true);
        return $this->render('institutions/assignment_mark', $this->pageVars($i, $m, 'courses') + ['course' => $c, 'assignment' => $a, 'learner' => $learner,
            'submission' => $cw->submissionOf((int) $i['id'], (int) $a['id'], (int) $learner['id']), 'mark' => $cw->markOf((int) $i['id'], (int) $a['id'], (int) $learner['id']),
            'criteria' => $cw->criteria((int) $i['id'], (int) $a['id']),
            'next' => $at !== false && isset($roster[$at + 1]) ? $roster[$at + 1]['membership_uuid'] : null,
            'crumb' => ['Courses' => '/institutions/' . $i['uuid'] . '/courses', $c['title'] => $this->base($i, $c) . '?tab=assignments',
                $a['title'] => $this->base($i, $c) . '/assignments/' . $a['uuid'], Access::displayName($learner) => null]]);
    }

    public function mark(string $ref, string $course, string $assignment, string $member): Response
    {
        [$i, $m, $c, $a] = $this->assignmentFor($ref, $course, $assignment, true);
        $learner = $this->learner($i, $c, $member);
        $back = $this->base($i, $c) . '/assignments/' . $a['uuid'] . '/submissions/' . $learner['uuid'];
        try {
            $criteria = array_filter((array) ($this->request->post['criteria'] ?? []), 'is_string');
            $this->s->coursework()->mark($i, $m, $c, $a, $learner, (string) $this->request->input('marks', ''), $criteria, (string) $this->request->input('feedback', ''));
            $this->flash('success', $a['released_at'] === null ? 'Saved. Learners see it once the marks are released.' : 'Saved. The learner sees the new mark.');
            $next = (string) $this->request->input('next', '');
            if ($next !== '' && $this->request->input('go') === 'next') {
                return $this->redirect($this->base($i, $c) . '/assignments/' . $a['uuid'] . '/submissions/' . $next);
            }
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect($back);
    }

    // Helpers --------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function fields(): array
    {
        $in = [];
        foreach (['title', 'instructions', 'due_at', 'late_policy', 'max_marks', 'max_files', 'max_mb'] as $f) {
            $in[$f] = (string) $this->request->input($f, '');
        }
        $in['file_kinds'] = array_filter((array) ($this->request->post['file_kinds'] ?? []), 'is_string');
        return $in;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $c @return array<string,mixed> a learner of the course */
    private function learner(array $i, array $c, string $member): array
    {
        $p = $this->s->institutions()->repo()->member((int) $i['id'], $member);
        if ($p === null || $this->s->structure()->repo()->activeEnrolment((int) $i['id'], (int) $c['class_id'], (int) $p['id']) === null) {
            throw HttpException::notFound('Learner');
        }
        return $p;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $c */
    private function base(array $i, array $c): string
    {
        return '/institutions/' . $i['uuid'] . '/courses/' . $c['uuid'];
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function courseFor(string $ref, string $course, bool $manage): array
    {
        [$i, $m] = $this->tenant($ref, null);
        $courses = $this->s->courses();
        $c = $courses->find((int) $i['id'], $course) ?? throw HttpException::notFound('Course');
        if ($manage ? !$courses->canManage($m, $c) : !$courses->canSee($m, $c)) {
            throw new HttpException(403, $manage ? 'Only the course\'s instructors, and people who manage classes, can change it.'
                : 'Only the course\'s learners and instructors, and people who can see every class, can open it.');
        }
        return [$i, $m, $c];
    }

    /**
     * The assignment, for someone who may see it: learners see it once it is open.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>,3:array<string,mixed>,4:bool}
     */
    private function assignmentFor(string $ref, string $course, string $assignment, bool $manage): array
    {
        [$i, $m, $c] = $this->courseFor($ref, $course, $manage);
        $a = $this->s->coursework()->assignment((int) $i['id'], (int) $c['id'], $assignment) ?? throw HttpException::notFound('Assignment');
        $runs = $manage || $this->s->courses()->canManage($m, $c);
        if (!$runs && $a['status'] === 'draft') {
            throw HttpException::notFound('Assignment');
        }
        // Someone who sees every class but does not run the course reads it as a learner would, without handing in.
        return [$i, $m, $c, $a, $runs];
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $c @param array<string,mixed> $a */
    private function attempt(array $i, array $c, array $a, \Closure $fn, ?string $ok = null): Response
    {
        try {
            $fn();
            if ($ok !== null) {
                $this->flash('success', $ok);
            }
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect($this->base($i, $c) . '/assignments/' . $a['uuid']);
    }
}
