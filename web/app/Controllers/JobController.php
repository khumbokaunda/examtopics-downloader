<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Redactor;
use App\Repositories\JobRepository;

final class JobController extends Controller
{
    private const LOG_LINES = 200;

    public function index(): Response
    {
        $this->s->supervisor()->ensure();
        $status = (string) $this->request->query('status', '');
        $type = (string) $this->request->query('type', '');
        $filters = [];
        if (in_array($status, array_merge(JobRepository::ACTIVE, JobRepository::FINAL), true)) {
            $filters['status'] = $status;
        }
        if (in_array($type, ['collect', 'images', 'inventory', 'providers'], true)) {
            $filters['type'] = $type;
        }
        // The table pages, sorts and searches in the browser; the list is capped and says so.
        $limit = 1000;
        $jobs = $this->s->jobs()->list($filters, $limit + 1);
        return $this->render('jobs/index', [
            'title' => 'Jobs & history',
            'nav' => 'jobs',
            'jobs' => array_slice($jobs, 0, $limit),
            'capped' => count($jobs) > $limit,
            'limit' => $limit,
            'status' => $filters['status'] ?? '',
            'type' => $filters['type'] ?? '',
            'workerRunning' => $this->s->supervisor()->workerRunning(),
        ]);
    }

    public function show(string $uuid): Response
    {
        $job = $this->job($uuid);
        $logTail = $this->s->runner()->logTail($this->app->paths()->jobDir((int) $job['id']) . '/tool.log', self::LOG_LINES);
        $version = $job['cache_version_id'] !== null ? $this->s->versions()->find((int) $job['cache_version_id']) : null;
        return $this->render('jobs/show', [
            'title' => 'Job #' . $job['id'],
            'nav' => 'jobs',
            'job' => $job,
            'options' => json_decode((string) $job['options'], true) ?: [],
            'logTail' => $logTail,
            'version' => $version,
        ]);
    }

    public function cancel(string $uuid): Response
    {
        $job = $this->job($uuid);
        $this->flash('info', $this->s->jobService()->cancel((int) $job['id']));
        return $this->redirect('/jobs/' . $job['uuid']);
    }

    /** Polled by the browser every few seconds while a job is active. */
    /** The end of the tool's output, polled by the job page while the job runs. */
    public function logJson(string $uuid): Response
    {
        $job = $this->job($uuid);
        return Response::json([
            'log' => $this->s->runner()->logTail($this->app->paths()->jobDir((int) $job['id']) . '/tool.log', self::LOG_LINES),
            'final' => in_array($job['status'], JobRepository::FINAL, true),
        ]);
    }

    public function statusJson(string $uuid): Response
    {
        $this->s->supervisor()->ensure();
        $job = $this->job($uuid);
        return Response::json(self::present($job));
    }

    /** Navbar indicator: the active jobs. */
    public function activeJson(): Response
    {
        $state = $this->s->supervisor()->ensure();
        return Response::json([
            'worker' => $state,
            'jobs' => array_map([self::class, 'present'], $this->s->jobs()->active()),
        ]);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function present(array $job): array
    {
        $done = $job['progress_done'] === null ? null : (int) $job['progress_done'];
        $total = $job['progress_total'] === null ? null : (int) $job['progress_total'];
        $started = $job['started_at'] !== null ? strtotime($job['started_at'] . ' UTC') : null;
        $finished = $job['finished_at'] !== null ? strtotime($job['finished_at'] . ' UTC') : null;
        return [
            'id' => (int) $job['id'],
            'uuid' => (string) $job['uuid'],
            'type' => $job['type'],
            'label' => job_label($job),
            'status' => $job['status'],
            'final' => in_array($job['status'], JobRepository::FINAL, true),
            'phase' => $job['phase'],
            'done' => $done,
            'total' => $total,
            // A percentage only when the total is known; otherwise the UI is indeterminate.
            'percent' => ($done !== null && $total !== null && $total > 0) ? min(100, (int) floor($done * 100 / $total)) : null,
            'message' => $job['message'] === null ? null : Redactor::clean((string) $job['message']),
            'result_status' => $job['result_status'],
            'elapsed_seconds' => $started ? max(0, ($finished ?: time()) - $started) : null,
            'heartbeat_at' => $job['heartbeat_at'],
            'cancel_requested' => (bool) $job['cancel_requested'],
        ];
    }

    /** @return array<string,mixed> */
    private function job(string $uuid): array
    {
        if (!\App\Core\Ids::isUuid($uuid)) {
            throw HttpException::notFound('Job');
        }
        return $this->s->jobs()->findByUuid($uuid) ?? throw HttpException::notFound('Job');
    }
}
