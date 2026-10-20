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
        ]);
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
