<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\InstitutionAccess;
use App\Services\InstitutionStructure;
use RuntimeException;

/**
 * An institution's classes: the list, one class with its students and instructors, and the
 * changes to them. People with classes.view see every class; instructors see the classes they
 * teach; classes.manage changes them.
 */
final class InstitutionClassController extends TenantController
{
    public function index(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, null);
        $all = InstitutionAccess::can($m, 'classes.view');
        if (!$all && !in_array($m['role'], InstitutionStructure::TEACHING_ROLES, true)) {
            throw new HttpException(403, 'Your role in ' . $i['name'] . ' does not include classes.');
        }
        $repo = $this->s->structure()->repo();
        $archived = $all && $this->request->query('status') === 'archived';
        return $this->render('institutions/classes', $this->pageVars($i, $m, 'classes') + [
            'classes' => $repo->classes((int) $i['id'], $archived ? 'archived' : 'active', $all ? null : (int) $m['id']),
            'archived' => $archived, 'all' => $all, 'manage' => InstitutionAccess::can($m, 'classes.manage'),
            'programmes' => $repo->programmes((int) $i['id']),
        ]);
    }

    public function show(string $ref, string $class): Response
    {
        [$i, $m, $c] = $this->classFor($ref, $class, false);
        $repo = $this->s->structure()->repo();
        $iid = (int) $i['id'];
        $manage = InstitutionAccess::can($m, 'classes.manage');
        $withEnded = $this->request->query('ended') === '1';
        $students = $repo->classStudents($iid, (int) $c['id'], $withEnded);
        $inClass = array_flip(array_map(static fn(array $s): int => (int) $s['membership_id'], array_filter($students, static fn(array $s): bool => $s['enrolment_status'] === 'active')));
        $members = $manage ? $this->s->institutions()->repo()->members($iid) : [];
        return $this->render('institutions/class', $this->pageVars($i, $m, 'classes') + [
            'class' => $c, 'students' => $students, 'withEnded' => $withEnded, 'manage' => $manage,
            'instructors' => $repo->instructors($iid, (int) $c['id']),
            'candidates' => $manage ? array_values(array_filter($repo->students($iid), static fn(array $s): bool => !isset($inClass[(int) $s['membership_id']]))) : [],
            'teachers' => array_values(array_filter($members, static fn(array $p): bool => in_array($p['role'], InstitutionStructure::TEACHING_ROLES, true))),
            'otherClasses' => $manage ? array_values(array_filter($repo->classes($iid), static fn(array $o): bool => (int) $o['id'] !== (int) $c['id'])) : [],
            'programmes' => $manage ? $repo->programmes($iid) : [],
        ]);
    }

    public function create(string $ref): Response
    {
        [$i] = $this->tenant($ref, 'classes.manage');
        try {
            $uuid = $this->s->structure()->saveClass($i, null, (string) $this->request->input('name', ''), (string) $this->request->input('intake', ''),
                (string) $this->request->input('programme', ''));
            $this->flash('success', 'Class created. Add its students and instructors here.');
            return $this->redirect('/institutions/' . $i['uuid'] . '/classes/' . $uuid);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/institutions/' . $i['uuid'] . '/classes');
        }
    }

    public function update(string $ref, string $class): Response
    {
        [$i, , $c] = $this->classFor($ref, $class, true);
        return $this->attempt($i, $c, fn() => $this->s->structure()->saveClass($i, $c, (string) $this->request->input('name', ''),
            (string) $this->request->input('intake', ''), (string) $this->request->input('programme', '')), 'Class saved.');
    }

    public function archive(string $ref, string $class): Response
    {
        [$i, , $c] = $this->classFor($ref, $class, true);
        $archive = $c['status'] === 'active';
        return $this->attempt($i, $c, fn() => $this->s->structure()->setArchived($i, $c, $archive),
            $archive ? 'Class archived. Its students and history stay; it takes no new students.' : 'Class restored.');
    }

    public function enrol(string $ref, string $class): Response
    {
        [$i, , $c] = $this->classFor($ref, $class, true);
        $ids = [];
        foreach ((array) ($this->request->post['students'] ?? []) as $uuid) {
            $p = is_string($uuid) ? $this->s->institutions()->repo()->member((int) $i['id'], $uuid) : null;
            $ids[] = $p !== null ? (int) $p['id'] : 0;
        }
        if ($ids === []) {
            $this->flash('info', 'Choose the students to add.');
            return $this->back('/institutions/' . $i['uuid'] . '/classes/' . $c['uuid']);
        }
        return $this->attempt($i, $c, function () use ($i, $c, $ids): void {
            $n = $this->s->structure()->enrol($i, $c, $ids, (int) $this->auth->user()['id']);
            $this->flash('success', $n === 1 ? 'One student added.' : $n . ' students added.');
        });
    }

    public function remove(string $ref, string $class, string $enrolment): Response
    {
        [$i, , $c] = $this->classFor($ref, $class, true);
        $who = $this->who($i, $c, $enrolment);
        return $this->attempt($i, $c, fn() => $this->s->structure()->unenrol($i, $c, (int) $enrolment, $who, (int) $this->auth->user()['id']),
            $who . ' is no longer in the class. Their time in it stays on record.');
    }

    public function move(string $ref, string $class, string $enrolment): Response
    {
        [$i, , $c] = $this->classFor($ref, $class, true);
        $who = $this->who($i, $c, $enrolment);
        return $this->attempt($i, $c, function () use ($i, $c, $enrolment, $who): void {
            $to = $this->s->structure()->move($i, $c, (int) $enrolment, (string) $this->request->input('to', ''), $who, (int) $this->auth->user()['id']);
            $this->flash('success', $who . ' moved to ' . $to . '.');
        });
    }

    public function instructors(string $ref, string $class): Response
    {
        [$i, , $c] = $this->classFor($ref, $class, true);
        $people = [];
        foreach ((array) ($this->request->post['instructors'] ?? []) as $uuid) {
            $p = is_string($uuid) ? $this->s->institutions()->repo()->member((int) $i['id'], $uuid) : null;
            if ($p === null) {
                throw HttpException::notFound('Member');
            }
            $people[] = $p;
        }
        return $this->attempt($i, $c, fn() => $this->s->structure()->setInstructors($i, $c, $people), 'Instructors saved.');
    }

    /**
     * The institution, membership and class, for someone who may see the class (or change it,
     * when $manage).
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function classFor(string $ref, string $class, bool $manage): array
    {
        [$i, $m] = $this->tenant($ref, $manage ? 'classes.manage' : null);
        $repo = $this->s->structure()->repo();
        $c = $repo->class((int) $i['id'], $class) ?? throw HttpException::notFound('Class');
        if (!$manage && !InstitutionAccess::can($m, 'classes.view') && !$repo->teaches((int) $i['id'], (int) $c['id'], (int) $m['id'])) {
            throw new HttpException(403, 'Only the class\'s instructors, and people who can see every class, can open it.');
        }
        return [$i, $m, $c];
    }

    /** The name of the student in an enrolment of this class, for messages. @param array<string,mixed> $i @param array<string,mixed> $c */
    private function who(array $i, array $c, string $enrolment): string
    {
        foreach ($this->s->structure()->repo()->classStudents((int) $i['id'], (int) $c['id']) as $s) {
            if ((string) $s['enrolment_id'] === $enrolment) {
                return \App\Services\Access::displayName($s);
            }
        }
        throw HttpException::notFound('Enrolment');
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $c */
    private function attempt(array $i, array $c, \Closure $fn, ?string $ok = null): Response
    {
        try {
            $fn();
            if ($ok !== null) {
                $this->flash('success', $ok);
            }
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/classes/' . $c['uuid']);
    }
}
