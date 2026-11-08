<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\InstitutionAccess;
use App\Services\QuestionBanks;
use RuntimeException;

/**
 * Building assessments: the list, one assessment with its settings, questions, blueprint,
 * assignments and readiness checks, picking questions from a bank, a preview of a candidate's
 * form, and publishing. Candidates sit them in phase 4.
 */
final class InstitutionAssessmentController extends TenantController
{
    public function index(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, null);
        $perms = array_filter(['assessments.view', 'assessments.create', 'assessments.assign', 'assessments.schedule'], static fn(string $p): bool => InstitutionAccess::can($m, $p));
        if ($perms === []) {
            throw new HttpException(403, 'Your role in ' . $i['name'] . ' does not include assessments.');
        }
        $all = InstitutionAccess::can($m, 'assessments.view') || InstitutionAccess::can($m, 'assessments.schedule');
        $status = (string) $this->request->query('status', '');
        $status = isset(\App\Services\Assessments::STATUSES[$status]) ? $status : '';
        return $this->render('institutions/assessments', $this->pageVars($i, $m, 'assessments') + [
            'rows' => $this->s->assessments()->repo()->all((int) $i['id'], $status, $all ? null : (int) $m['id']),
            'status' => $status, 'all' => $all, 'canCreate' => InstitutionAccess::can($m, 'assessments.create'),
        ]);
    }

    public function create(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'assessments.create');
        try {
            $uuid = $this->s->assessments()->create($i, $m, (string) $this->request->input('title', ''), (string) $this->request->input('type', ''));
            $this->flash('success', 'Assessment created as a draft. Set it up below, add its questions, assign it and publish it.');
            return $this->redirect('/institutions/' . $i['uuid'] . '/assessments/' . $uuid);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/institutions/' . $i['uuid'] . '/assessments');
        }
    }

    public function show(string $ref, string $assessment): Response
    {
        [$i, $m, $a] = $this->assessmentFor($ref, $assessment, 'see');
        $svc = $this->s->assessments();
        $iid = (int) $i['id'];
        $all = InstitutionAccess::can($m, 'classes.view');
        $canAssign = $svc->canAssign($m, $a);
        return $this->render('institutions/assessment', $this->pageVars($i, $m, 'assessments') + [
            'assessment' => $a, 'items' => $svc->repo()->items($iid, (int) $a['id']), 'blueprints' => $svc->repo()->blueprints($iid, (int) $a['id']),
            'assignments' => $svc->repo()->assignments($iid, (int) $a['id']), 'candidates' => $svc->repo()->candidateCount($iid, (int) $a['id']),
            'checks' => $a['status'] === 'draft' ? $svc->checks($i, $a) : [],
            'canEdit' => $svc->canEdit($m, $a), 'canAssign' => $canAssign, 'canSchedule' => InstitutionAccess::can($m, 'assessments.schedule'),
            'banks' => $svc->canEdit($m, $a) ? $this->visibleBanks($i, $m) : [],
            'classes' => $canAssign ? $this->s->structure()->repo()->classes($iid, 'active', $all ? null : (int) $m['id']) : [],
            'students' => $canAssign && $all ? $this->s->structure()->repo()->students($iid) : [],
            'timezone' => $this->timezone(),
        ]);
    }

    public function settings(string $ref, string $assessment): Response
    {
        [$i, , $a] = $this->assessmentFor($ref, $assessment, 'edit');
        $in = [];
        foreach (\App\Repositories\AssessmentRepository::SETTINGS as $f) {
            $in[$f] = $this->request->input($f, '');
        }
        return $this->attempt($i, $a, fn() => $this->s->assessments()->saveSettings($i, $a, $in, $this->timezone()), 'Settings saved.', 'settings');
    }

    public function schedule(string $ref, string $assessment): Response
    {
        [$i, $m, $a] = $this->assessmentFor($ref, $assessment, 'see');
        if (!InstitutionAccess::can($m, 'assessments.schedule') || $a['status'] !== 'published') {
            throw new HttpException(403, 'Only someone who schedules assessments can change when a published one opens and closes.');
        }
        return $this->attempt($i, $a, fn() => $this->s->assessments()->reschedule($i, $a, (string) $this->request->input('opens_at', ''),
            (string) $this->request->input('closes_at', ''), $this->timezone()), 'Times saved.');
    }

    public function pick(string $ref, string $assessment): Response
    {
        [$i, $m, $a] = $this->assessmentFor($ref, $assessment, 'edit');
        $banks = $this->visibleBanks($i, $m);
        $chosen = null;
        foreach ($banks as $b) {
            $chosen = $b['uuid'] === $this->request->query('bank') ? $b : $chosen;
        }
        $chosen ??= $banks[0] ?? null;
        $iid = (int) $i['id'];
        $in = array_flip(array_map(static fn(array $it): int => (int) $it['question_id'], $this->s->assessments()->repo()->items($iid, (int) $a['id'])));
        $questions = [];
        if ($chosen !== null) {
            $full = $this->s->questionBanks()->repo()->bank($iid, (string) $chosen['uuid']);
            foreach ($this->s->questionBanks()->repo()->questions($iid, (int) $full['id'], ['status' => '']) as $q) {
                if ($q['published_version'] !== null && !isset($in[(int) $q['id']])) {
                    $questions[] = $q;
                }
            }
        }
        return $this->render('institutions/assessment_pick', $this->pageVars($i, $m, 'assessments') + [
            'assessment' => $a, 'banks' => $banks, 'bank' => $chosen, 'questions' => $questions,
            'topics' => $chosen !== null ? $this->s->questionBanks()->repo()->topics($iid, (int) $chosen['id']) : [],
        ]);
    }

    public function addQuestions(string $ref, string $assessment): Response
    {
        [$i, $m, $a] = $this->assessmentFor($ref, $assessment, 'edit');
        $bank = $this->bankFor($i, $m, (string) $this->request->input('bank', ''));
        $uuids = array_values(array_filter((array) ($this->request->post['questions'] ?? []), 'is_string'));
        return $this->attempt($i, $a, function () use ($i, $m, $a, $bank, $uuids): void {
            $n = $this->s->assessments()->addQuestions($i, $m, $a, $bank, $uuids);
            $this->flash($n > 0 ? 'success' : 'info', $n > 0 ? ($n === 1 ? 'One question added.' : $n . ' questions added.') : 'Choose the questions to add.');
        }, null, 'questions');
    }

    public function addBlueprint(string $ref, string $assessment): Response
    {
        [$i, $m, $a] = $this->assessmentFor($ref, $assessment, 'edit');
        $bank = $this->bankFor($i, $m, (string) $this->request->input('bank', ''));
        return $this->attempt($i, $a, fn() => $this->s->assessments()->addBlueprint($i, $m, $a, $bank, (string) $this->request->input('topic', ''),
            (string) $this->request->input('difficulty', ''), (string) $this->request->input('count', '')), 'Added to the blueprint.', 'questions');
    }

    public function removeItem(string $ref, string $assessment, string $item): Response
    {
        [$i, , $a] = $this->assessmentFor($ref, $assessment, 'edit');
        $this->s->assessments()->repo()->removeItem((int) $i['id'], (int) $a['id'], (int) $item);
        $this->flash('success', 'Question taken out.');
        return $this->redirect('/institutions/' . $i['uuid'] . '/assessments/' . $a['uuid'] . '#questions');
    }

    public function moveItem(string $ref, string $assessment, string $item): Response
    {
        [$i, , $a] = $this->assessmentFor($ref, $assessment, 'edit');
        $this->s->assessments()->repo()->moveItem((int) $i['id'], (int) $a['id'], (int) $item, $this->request->input('direction') === 'up' ? -1 : 1);
        return $this->redirect('/institutions/' . $i['uuid'] . '/assessments/' . $a['uuid'] . '#questions');
    }

    public function removeBlueprint(string $ref, string $assessment, string $row): Response
    {
        [$i, , $a] = $this->assessmentFor($ref, $assessment, 'edit');
        $this->s->assessments()->repo()->removeBlueprint((int) $i['id'], (int) $a['id'], (int) $row);
        $this->flash('success', 'Taken out of the blueprint.');
        return $this->redirect('/institutions/' . $i['uuid'] . '/assessments/' . $a['uuid'] . '#questions');
    }

    public function assign(string $ref, string $assessment): Response
    {
        [$i, $m, $a] = $this->assessmentFor($ref, $assessment, 'assign');
        return $this->attempt($i, $a, function () use ($i, $m, $a): void {
            $done = $this->s->assessments()->assign($i, $m, $a, (string) $this->request->input('class', ''), (string) $this->request->input('student', ''));
            $this->flash($done ? 'success' : 'info', $done ? 'Assigned.' : 'It was already assigned there.');
        }, null, 'assigned');
    }

    public function unassign(string $ref, string $assessment, string $assignment): Response
    {
        [$i, $m, $a] = $this->assessmentFor($ref, $assessment, 'assign');
        $row = $this->s->assessments()->repo()->assignment((int) $i['id'], (int) $a['id'], (int) $assignment) ?? throw HttpException::notFound('Assignment');
        if ($row['class_id'] !== null && !InstitutionAccess::can($m, 'classes.view') && !$this->s->structure()->repo()->teaches((int) $i['id'], (int) $row['class_id'], (int) $m['id'])) {
            throw new HttpException(403, 'You can change only the classes you teach.');
        }
        if ($row['class_id'] === null && !InstitutionAccess::can($m, 'classes.view')) {
            throw new HttpException(403, 'You can change only the classes you teach.');
        }
        $this->s->assessments()->repo()->unassign((int) $i['id'], (int) $a['id'], (int) $assignment);
        $this->s->audit()->record('institution.assessment_unassigned', ['institution' => $i['name'], 'assessment' => $a['title']], 'institution', (string) $i['uuid']);
        $this->flash('success', 'No longer assigned there.');
        return $this->redirect('/institutions/' . $i['uuid'] . '/assessments/' . $a['uuid'] . '#assigned');
    }

    public function status(string $ref, string $assessment): Response
    {
        [$i, $m, $a] = $this->assessmentFor($ref, $assessment, 'see');
        $action = (string) $this->request->input('action', '');
        $svc = $this->s->assessments();
        $scheduler = InstitutionAccess::can($m, 'assessments.schedule');
        $allowed = in_array($action, ['publish', 'unpublish', 'close', 'reopen'], true) ? $scheduler
            : (in_array($action, ['archive', 'restore'], true) && ($scheduler || ((int) $a['created_by'] === (int) $m['id'] && InstitutionAccess::can($m, 'assessments.create'))));
        if (!$allowed) {
            throw new HttpException(403, 'Your role does not include that. Publishing, closing and reopening need the "assessments.schedule" permission.');
        }
        return $this->attempt($i, $a, function () use ($svc, $i, $m, $a, $action): void {
            if ($action === 'publish') {
                $svc->publish($i, $m, $a);
                $this->flash('success', 'Published. Its chosen questions are fixed to the versions published now.');
            } else {
                $to = $svc->move($i, $a, $action);
                $this->flash('success', 'The assessment is now ' . strtolower(\App\Services\Assessments::STATUSES[$to]) . '.');
            }
        });
    }

    public function preview(string $ref, string $assessment): Response
    {
        [$i, $m, $a] = $this->assessmentFor($ref, $assessment, 'see');
        $seed = ctype_digit((string) $this->request->query('seed', '')) ? (int) $this->request->query('seed') : random_int(1, 2147483647);
        $form = $this->s->assessments()->draw($i, $a, $seed);
        return $this->render('institutions/assessment_preview', $this->pageVars($i, $m, 'assessments') + [
            'assessment' => $a, 'form' => $form, 'seed' => $seed,
            'total' => array_sum(array_map(static fn(array $f): float => (float) $f['version']['marks'], $form)),
        ]);
    }

    public function results(string $ref, string $assessment): Response
    {
        [$i, $m, $a] = $this->assessmentFor($ref, $assessment, 'see');
        $this->s->sittings()->expireOverdue();
        $rows = $this->s->sittings()->repo()->forAssessment((int) $i['id'], (int) $a['id']);
        $done = array_values(array_filter($rows, static fn(array $r): bool => $r['status'] !== 'in_progress'));
        $percents = array_map(static fn(array $r): float => (float) $r['percent'], $done);
        return $this->render('institutions/assessment_results', $this->pageVars($i, $m, 'assessments') + [
            'assessment' => $a, 'rows' => $rows,
            'summary' => ['sat' => count($done), 'in_progress' => count($rows) - count($done), 'candidates' => $this->s->assessments()->repo()->candidateCount((int) $i['id'], (int) $a['id']),
                'average' => $percents !== [] ? round(array_sum($percents) / count($percents), 1) : null,
                'passed' => $a['pass_percent'] !== null ? count(array_filter($done, static fn(array $r): bool => (int) $r['passed'] === 1)) : null],
        ]);
    }

    public function sitting(string $ref, string $assessment, string $sitting): Response
    {
        [$i, $m, $a] = $this->assessmentFor($ref, $assessment, 'see');
        $s = $this->s->sittings()->repo()->sessionOf((int) $i['id'], (int) $a['id'], $sitting) ?? throw HttpException::notFound('Sitting');
        return $this->render('institutions/assessment_sitting', $this->pageVars($i, $m, 'assessments') + [
            'assessment' => $a, 'sitting' => $s, 'questions' => $this->s->sittings()->repo()->questions((int) $s['id']),
            'responses' => $this->s->sittings()->repo()->responses((int) $s['id']),
        ]);
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function assessmentFor(string $ref, string $assessment, string $need): array
    {
        [$i, $m] = $this->tenant($ref, null);
        $svc = $this->s->assessments();
        $a = $svc->repo()->find((int) $i['id'], $assessment) ?? throw HttpException::notFound('Assessment');
        if (!$svc->canSee($m, $a)) {
            throw HttpException::notFound('Assessment');
        }
        $ok = match ($need) {
            'edit' => $svc->canEdit($m, $a),
            'assign' => $svc->canAssign($m, $a),
            default => true,
        };
        if (!$ok) {
            throw new HttpException(403, $a['status'] !== 'draft' && $need === 'edit'
                ? 'A published assessment cannot be changed. Take it back to a draft first (only while nobody has sat it).'
                : 'Your role does not include changing this assessment.');
        }
        return [$i, $m, $a];
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $m @return array<string,mixed> */
    private function bankFor(array $i, array $m, string $uuid): array
    {
        $b = $this->s->questionBanks()->repo()->bank((int) $i['id'], $uuid);
        if ($b === null || !$this->s->questionBanks()->canSee($m, $b)) {
            throw HttpException::notFound('Question bank');
        }
        return $b;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $m @return list<array<string,mixed>> active banks the person may see */
    private function visibleBanks(array $i, array $m): array
    {
        $all = InstitutionAccess::can($m, 'question_banks.view') || InstitutionAccess::can($m, 'question_banks.edit') || InstitutionAccess::can($m, 'question_banks.publish');
        return $this->s->questionBanks()->repo()->banks((int) $i['id'], 'active', $all ? null : (int) $m['id']);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $a */
    private function attempt(array $i, array $a, \Closure $fn, ?string $ok = null, string $anchor = ''): Response
    {
        try {
            $fn();
            if ($ok !== null) {
                $this->flash('success', $ok);
            }
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return \App\Core\Http\Response::redirect(\App\Core\View::buildUrl((string) $this->app->config()->get('base_path', ''),
            '/institutions/' . $i['uuid'] . '/assessments/' . $a['uuid']) . ($anchor !== '' ? '#' . $anchor : ''));
    }

    /** A UTC time as the value of a datetime-local box in $timezone. */
    public static function localInput(?string $utc, string $timezone): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d\TH:i');
    }

    /** Marks without trailing zeros. */
    public static function marks(mixed $m): string
    {
        return rtrim(rtrim(number_format((float) $m, 2, '.', ''), '0'), '.');
    }

    /** For the view. @return array<string,string> */
    public static function difficulties(): array
    {
        return QuestionBanks::DIFFICULTIES;
    }
}
