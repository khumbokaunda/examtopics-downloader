<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\InstitutionAccess;
use App\Services\Invigilation;
use RuntimeException;

/**
 * Live invigilation of an assessment: the live page and its polling feed, invigilator
 * assignment, observations and interventions.
 */
final class InvigilationController extends TenantController
{
    public function live(string $ref, string $assessment): Response
    {
        [$i, $m, $a] = $this->watchable($ref, $assessment);
        $this->s->sittings()->expireOverdue();
        $iid = (int) $i['id'];
        return $this->render('institutions/assessment_live', $this->pageVars($i, $m, 'assessments') + [
            'assessment' => $a, 'rows' => $this->s->invigilation()->live($iid, (int) $a['id']),
            'canIntervene' => InstitutionAccess::can($m, 'assessments.intervene'),
            'invigilators' => $this->s->invigilation()->invigilators($iid, (int) $a['id']),
            'staff' => InstitutionAccess::can($m, 'assessments.schedule') ? array_values(array_filter($this->s->institutions()->repo()->members($iid),
                static fn(array $p): bool => $p['role'] !== InstitutionAccess::STUDENT)) : [],
        ]);
    }

    public function liveJson(string $ref, string $assessment): Response
    {
        [$i, , $a] = $this->watchable($ref, $assessment);
        $this->s->sittings()->expireOverdue();
        return Response::json(['rows' => array_map(static fn(array $r): array => [
            'member' => $r['membership_uuid'], 'state' => $r['state'], 'seconds_left' => $r['seconds_left'], 'answered' => (int) $r['answered'],
            'questions' => (int) $r['question_count'], 'connected' => $r['connected'], 'level' => $r['level'],
        ], $this->s->invigilation()->live((int) $i['id'], (int) $a['id']))]);
    }

    public function invigilators(string $ref, string $assessment): Response
    {
        [$i, $m, $a] = $this->watchable($ref, $assessment);
        if (!InstitutionAccess::can($m, 'assessments.schedule')) {
            throw new HttpException(403, 'Assigning invigilators needs the "assessments.schedule" permission.');
        }
        $people = [];
        foreach ((array) ($this->request->post['invigilators'] ?? []) as $uuid) {
            $people[] = (is_string($uuid) ? $this->s->institutions()->repo()->member((int) $i['id'], $uuid) : null) ?? throw HttpException::notFound('Member');
        }
        return $this->attempt($i, $a, fn() => $this->s->invigilation()->setInvigilators($i, $a, $people), 'Invigilators saved.');
    }

    public function observe(string $ref, string $assessment, string $sitting): Response
    {
        [$i, $m, $a] = $this->watchable($ref, $assessment);
        $s = $this->sitting($i, $a, $sitting);
        return $this->attempt($i, $a, fn() => $this->s->invigilation()->observe($i, $m, $s, (string) $this->request->input('category', ''),
            (string) $this->request->input('note', '')), 'Observation recorded.');
    }

    public function intervene(string $ref, string $assessment, string $sitting): Response
    {
        [$i, $m, $a] = $this->watchable($ref, $assessment);
        if (!InstitutionAccess::can($m, 'assessments.intervene')) {
            throw new HttpException(403, 'Pausing, extra time and ending a sitting need the "assessments.intervene" permission.');
        }
        $s = $this->sitting($i, $a, $sitting);
        try {
            $this->flash('success', $this->s->invigilation()->intervene($i, $s, (string) $this->request->input('action', ''), (int) $this->request->input('minutes', '0'),
                (string) $this->request->input('reason', ''), $this->s->sittings()));
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/assessments/' . $a['uuid'] . '/live');
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>} */
    private function watchable(string $ref, string $assessment): array
    {
        [$i, $m] = $this->tenant($ref, null);
        $a = $this->s->assessments()->repo()->find((int) $i['id'], $assessment);
        if ($a === null || !$this->s->invigilation()->canWatch($m, $a)) {
            throw HttpException::notFound('Assessment');
        }
        return [$i, $m, $a];
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $a @return array<string,mixed> the full sitting (with its assessment's settings) */
    private function sitting(array $i, array $a, string $uuid): array
    {
        $s = $this->s->sittings()->repo()->sessionOf((int) $i['id'], (int) $a['id'], $uuid) ?? throw HttpException::notFound('Sitting');
        return (array) $this->s->sittings()->repo()->session((string) $s['uuid']);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $a */
    private function attempt(array $i, array $a, \Closure $fn, string $ok): Response
    {
        try {
            $fn();
            $this->flash('success', $ok);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/assessments/' . $a['uuid'] . '/live');
    }

    /** For the view: the categories of observation. @return array<string,string> */
    public static function categories(): array
    {
        return Invigilation::CATEGORIES;
    }
}
