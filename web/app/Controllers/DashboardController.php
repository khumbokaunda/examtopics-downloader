<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        $this->s->supervisor()->ensure();
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
        ]);
    }
}
