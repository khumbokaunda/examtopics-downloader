<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        if ($this->auth->can('exams.collect')) {
            $this->s->supervisor()->ensure();
        }
        $jobs = $this->s->jobs();
        return $this->render('dashboard', [
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'totals' => $this->s->versions()->totals(),
            'providerCount' => $this->s->providers()->count(),
            'active' => $jobs->active(),
            'recentVersions' => $this->s->versions()->recent(6),
            'recentCompleted' => $jobs->list(['status' => 'completed'], 5),
            'recentFailed' => $jobs->list(['status' => 'failed'], 5),
            'recentDocuments' => $this->s->documentRepo()->recent(6),
            'jobCounts' => $jobs->countsByStatus(),
            'workerRunning' => $this->s->supervisor()->workerRunning(),
            'activity' => $this->auth->can('activity.view') ? $this->activity() : null,
            'studying' => $this->studying(),
        ]);
    }

    /**
     * The exams this person studied most recently, with where they were and how they are doing.
     *
     * @return list<array{exam:array<string,mixed>,provider:array<string,mixed>,place:array<string,mixed>,progress:array<string,mixed>,questions:int}>
     */
    private function studying(): array
    {
        $userId = (int) ($this->auth->user()['id'] ?? 0);
        $progress = $this->s->progress();
        $totals = $progress->byExam($userId);
        $out = [];
        foreach ($progress->recent($userId, 4) as $place) {
            $exam = $this->s->exams()->findById($place['exam_id']);
            $provider = $exam === null ? null : $this->s->providers()->find((int) $exam['provider_id']);
            $best = $exam === null ? null : $this->s->versions()->bestForExam((int) $exam['id']);
            if ($provider === null || $best === null) {
                continue;
            }
            $out[] = ['exam' => $exam, 'provider' => $provider, 'place' => $place, 'questions' => (int) $best['collected_count'],
                'progress' => $totals[(int) $exam['id']] ?? ['answered' => 0, 'correct' => 0, 'mistakes' => 0, 'last_at' => null]];
        }
        return $out;
    }

    /**
     * The newest entries of the activity history in plain English, with failed sign-ins
     * counted on one line rather than listed (a public site sees many).
     *
     * @return array{rows:list<array<string,mixed>>,failed:int}
     */
    private function activity(): array
    {
        $names = [];
        foreach ($this->s->users()->all() as $u) {
            $names[(int) $u['id']] = \App\Services\Access::displayName($u);
        }
        $audit = $this->s->audit();
        return [
            'rows' => array_map(static fn(array $r): array => $r + \App\Services\AuditText::describe($r, $names), $audit->recent(8)),
            'failed' => $audit->failedSignIns(86400),
        ];
    }
}
