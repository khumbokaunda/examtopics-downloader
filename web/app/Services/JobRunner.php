<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Ids;
use App\Core\Logger;
use App\Core\Paths;
use App\Core\Redactor;
use App\Repositories\ExamRepository;
use App\Repositories\InventoryRefreshRepository;
use App\Repositories\JobRepository;
use App\Repositories\ProviderRepository;
use Throwable;

/**
 * Runs one claimed job in the worker process: starts the tool, mirrors its progress events
 * into the job row, honours cancellation and the runtime limit, then applies the result.
 */
final class JobRunner
{
    /** Set by the worker's SIGTERM handler: stop the current job and exit. */
    public bool $shutdownRequested = false;

    public function __construct(
        private Config $config,
        private Paths $paths,
        private Logger $log,
        private JobRepository $jobs,
        private ProviderRepository $providers,
        private ExamRepository $exams,
        private InventoryRefreshRepository $refreshes,
        private CacheService $cache,
    ) {
    }

    /** @param array<string,mixed> $job a job already marked running */
    public function run(array $job): void
    {
        $id = (int) $job['id'];
        try {
            $this->execute($job);
        } catch (Throwable $e) {
            $this->log->error('jobs', 'Job crashed', ['job_id' => $id, 'error' => $e->getMessage()]);
            $this->jobs->finish($id, 'failed', 'Internal error', 'Internal error while running the job: ' . Redactor::clean($e->getMessage()));
        }
    }

    /** @param array<string,mixed> $job */
    private function execute(array $job): void
    {
        $id = (int) $job['id'];
        $type = (string) $job['type'];
        $options = json_decode((string) $job['options'], true) ?: [];
        $provider = $job['provider_id'] !== null ? $this->providers->find((int) $job['provider_id']) : null;

        $dir = $this->paths->jobDir($id);
        Paths::ensureDir($dir);
        $out = $dir . '/output.json';
        $events = $dir . '/events.jsonl';
        $log = $dir . '/tool.log';
        @unlink($out);

        $binary = (string) $this->config->get('tool.binary');
        if (!is_file($binary) || !is_executable($binary)) {
            $this->jobs->finish($id, 'failed', 'Collection tool not found',
                'The collection tool is not installed at the configured path. Build it with: go build -o bin/examtopics-downloader ./cmd/main.go');
            return;
        }

        // The argument list is built only from validated values held in the database; no
        // request data reaches it directly. There is no shell involved.
        $rps = (float) ($options['rps'] ?? $this->config->get('tool.default_rps', 1.0));
        $argv = [$binary, '-batch', '-rps', rtrim(rtrim(sprintf('%.3f', max(0.05, min(5.0, $rps))), '0'), '.'),
                 '-out-json', $out, '-events', $events];
        switch ($type) {
            case 'providers':
                $argv[] = '-providers';
                break;
            case 'inventory':
                if ($provider === null || !Ids::isProvider($provider['slug'])) {
                    $this->jobs->finish($id, 'failed', 'Invalid provider', 'The provider for this job is invalid.');
                    return;
                }
                array_push($argv, '-inventory', '-p', (string) $provider['slug']);
                break;
            case 'collect':
                if ($provider === null || !Ids::isProvider($provider['slug']) || !Ids::isExam($job['exam_code'])) {
                    $this->jobs->finish($id, 'failed', 'Invalid exam', 'The provider or exam for this job is invalid.');
                    return;
                }
                array_push($argv, '-p', (string) $provider['slug'], '-exam', (string) $job['exam_code']);
                if (($options['source_mode'] ?? 'auto') === 'live') {
                    $argv[] = '-no-cache';
                }
                break;
            default:
                $this->jobs->finish($id, 'failed', 'Unknown job type', 'Unknown job type.');
                return;
        }

        $this->log->info('jobs', 'Starting tool', ['job_id' => $id, 'type' => $type, 'exam' => $job['exam_code']]);
        $process = new Process($argv, $dir, WorkerSupervisor::toolEnv($this->config), $log);
        $this->jobs->setChildPid($id, $process->pid());

        $tail = new EventTail($events);
        $terminal = null;
        $started = time();
        $maxRuntime = (int) $this->config->get('tool.max_runtime', 21600);
        $grace = (int) $this->config->get('tool.kill_grace', 10);
        $stopReason = null;

        while (true) {
            $exit = $process->poll();
            foreach ($tail->read() as $e) {
                $terminal = $this->applyEvent($id, $e) ?? $terminal;
            }
            if ($exit !== null) {
                break;
            }
            if ($stopReason === null) {
                if ($this->jobs->cancelRequested($id)) {
                    $stopReason = 'cancelled';
                } elseif (time() - $started > $maxRuntime) {
                    $stopReason = 'timeout';
                } elseif ($this->shutdownRequested) {
                    $stopReason = 'shutdown';
                }
                if ($stopReason !== null) {
                    $this->jobs->updateProgress($id, null, null, null, 'Stopping…');
                    $exit = $process->stop($grace);
                    foreach ($tail->read() as $e) {
                        $terminal = $this->applyEvent($id, $e) ?? $terminal;
                    }
                    break;
                }
            }
            usleep(500_000);
        }

        if ($stopReason === 'cancelled') {
            $this->jobs->finish($id, 'cancelled', 'Cancelled', 'Cancelled by the user. Previously cached data is unchanged.', $exit);
            $this->log->info('jobs', 'Job cancelled', ['job_id' => $id]);
            return;
        }
        if ($stopReason !== null) {
            $msg = $stopReason === 'timeout'
                ? 'Stopped after exceeding the maximum runtime of ' . intdiv($maxRuntime, 60) . ' minutes.'
                : 'Stopped because the worker was shut down.';
            $this->jobs->finish($id, 'failed', 'Stopped', $msg . ' Previously cached data is unchanged.', $exit);
            return;
        }

        $failure = is_array($terminal) && ($terminal['event'] ?? '') === 'failed' ? (string) ($terminal['message'] ?? '') : '';
        match ($type) {
            'providers' => $this->finishProviders($id, (int) $exit, $out, $failure, $log),
            'inventory' => $this->finishInventory($id, (int) $exit, $out, $failure, $log, $provider),
            'collect' => $this->finishCollect($id, (int) $exit, $out, $failure, $log, $provider, $job, $options),
        };
    }

    /**
     * Mirror one progress event into the job row.
     * @param array<string,mixed> $e
     * @return array<string,mixed>|null the event if it is terminal
     */
    private function applyEvent(int $jobId, array $e): ?array
    {
        $phase = isset($e['phase']) && is_string($e['phase']) ? substr($e['phase'], 0, 32) : null;
        $msg = isset($e['message']) && is_string($e['message']) ? Redactor::clean($e['message']) : null;
        switch ($e['event']) {
            case 'phase':
                $this->jobs->updateProgress($jobId, $phase, null, null, $msg);
                return null;
            case 'progress':
                $done = isset($e['done']) && is_int($e['done']) ? max(0, $e['done']) : null;
                $total = isset($e['total']) && is_int($e['total']) ? max(0, $e['total']) : null;
                $this->jobs->updateProgress($jobId, $phase, $done, $total, null);
                return null;
            case 'warning':
                $this->jobs->heartbeat($jobId);
                return null;
            case 'completed':
            case 'failed':
                return $e;
        }
        return null;
    }

    private function finishProviders(int $id, int $exit, string $out, string $failure, string $log): void
    {
        $doc = $exit === 0 ? $this->readDoc($out, 'providers') : null;
        if ($doc === null) {
            $this->jobs->finish($id, 'failed', 'Provider discovery failed', $this->failureText($exit, $failure, $log), $exit);
            return;
        }
        $n = 0;
        foreach ((array) ($doc['providers'] ?? []) as $p) {
            if (is_array($p) && Ids::isProvider($p['slug'] ?? null)) {
                $name = is_string($p['name'] ?? null) ? mb_substr($p['name'], 0, 128) : null;
                $this->providers->upsert($p['slug'], $name, 'discovered');
                $n++;
            }
        }
        $this->jobs->finish($id, 'completed', "Found {$n} providers", null, $exit);
        $this->log->info('inventory', 'Providers discovered', ['job_id' => $id, 'count' => $n]);
    }

    /** @param array<string,mixed>|null $provider */
    private function finishInventory(int $id, int $exit, string $out, string $failure, string $log, ?array $provider): void
    {
        $providerId = (int) $provider['id'];
        $doc = in_array($exit, [0, 2], true) ? $this->readDoc($out, 'inventory') : null;
        if ($doc === null || ($doc['provider'] ?? null) !== $provider['slug']) {
            $error = $this->failureText($exit, $failure, $log);
            $this->refreshes->record($providerId, $id, 'failed', null, null, null, $error);
            $this->jobs->finish($id, 'failed', 'Inventory refresh failed', $error, $exit);
            $this->log->warning('inventory', 'Inventory refresh failed', ['job_id' => $id, 'provider' => $provider['slug'], 'exit' => $exit]);
            return;
        }
        $exams = [];
        foreach ((array) ($doc['exams'] ?? []) as $e) {
            // Exclude anything that would not be a valid -exam argument.
            if (is_array($e) && Ids::isExam($e['code'] ?? null) && is_int($e['question_count'] ?? null) && $e['question_count'] > 0) {
                $exams[] = [
                    'code' => $e['code'],
                    'name' => is_string($e['name'] ?? null) ? mb_substr($e['name'], 0, 255) : '',
                    'question_count' => $e['question_count'],
                ];
            }
        }
        $complete = ($doc['status'] ?? '') === 'complete';
        $this->exams->applyInventory($providerId, $exams, $complete);
        $errors = (array) ($doc['errors'] ?? []);
        $this->refreshes->record($providerId, $id, $complete ? 'complete' : 'partial', count($exams),
            (int) ($doc['listing_pages'] ?? 0), (int) ($doc['failed_listing_pages'] ?? 0),
            $errors === [] ? null : Redactor::clean(implode("\n", array_map('strval', array_slice($errors, 0, 10)))));
        $msg = count($exams) . ' exams found' . ($complete ? '' : ' (partial: ' . (int) ($doc['failed_listing_pages'] ?? 0) . ' listing pages failed, counts may be low)');
        $this->jobs->finish($id, 'completed', $msg, null, $exit, $complete ? 'complete' : 'partial');
        $this->log->info('inventory', 'Inventory refreshed', ['job_id' => $id, 'provider' => $provider['slug'], 'exams' => count($exams), 'status' => $doc['status'] ?? '']);
    }

    /**
     * @param array<string,mixed>|null $provider
     * @param array<string,mixed> $job
     * @param array<string,mixed> $options
     */
    private function finishCollect(int $id, int $exit, string $out, string $failure, string $log, ?array $provider, array $job, array $options): void
    {
        if (!in_array($exit, [0, 2], true)) {
            $msg = $exit === 3 ? 'No questions found for this exam' : 'Collection failed';
            $this->jobs->finish($id, 'failed', $msg, $this->failureText($exit, $failure, $log), $exit);
            $this->log->warning('jobs', 'Collection failed', ['job_id' => $id, 'exam' => $job['exam_code'], 'exit' => $exit]);
            return;
        }
        $exam = $this->exams->find((int) $provider['id'], (string) $job['exam_code']);
        if ($exam === null) {
            $examId = $this->exams->ensure((int) $provider['id'], (string) $job['exam_code']);
            $exam = $this->exams->findById($examId);
        }
        try {
            $published = $this->cache->publish($provider, $exam, $out, $id, $options);
        } catch (PublishException $e) {
            // The output is kept in the job directory for inspection; nothing was published.
            $this->jobs->finish($id, 'failed', 'Output failed validation',
                "The collected data failed validation and was not published. Previously cached data is unchanged.\n- "
                . implode("\n- ", array_slice($e->errors, 0, 15)), $exit);
            $this->log->error('jobs', 'Dataset failed validation', ['job_id' => $id, 'errors' => implode(' | ', array_slice($e->errors, 0, 5))]);
            return;
        }
        @unlink($out); // the published copy is canonical now
        $partial = $published['status'] === 'partial';
        $msg = $partial
            ? 'Partial: ' . $published['collected'] . ' questions saved as a partial version (the latest complete version, if any, is unchanged)'
            : $published['collected'] . ' questions saved';
        $this->jobs->finish($id, 'completed', $msg,
            $published['warnings'] === [] ? null : implode("\n", $published['warnings']),
            $exit, $published['status'], $published['version_id']);
    }

    /** @return array<string,mixed>|null */
    private function readDoc(string $path, string $kind): ?array
    {
        if (!is_file($path) || filesize($path) > 64 * 1024 * 1024) {
            return null;
        }
        $doc = json_decode((string) file_get_contents($path), true);
        return is_array($doc) && ($doc['kind'] ?? null) === $kind && ($doc['schema_version'] ?? null) === 1 ? $doc : null;
    }

    /** A sanitised, bounded failure summary: the tool's own failure message, else its log tail. */
    private function failureText(int $exit, string $failure, string $log): string
    {
        $parts = [];
        if ($failure !== '') {
            $parts[] = $failure;
        }
        $tail = $this->logTail($log, 15);
        if ($tail !== '') {
            $parts[] = "Tool output (last lines):\n" . $tail;
        }
        $parts[] = 'Exit code ' . $exit . match ($exit) {
            1 => ' (failed)', 2 => ' (partial)', 3 => ' (nothing matched)', 64 => ' (usage error)',
            137 => ' (killed)', 143 => ' (terminated)', default => '',
        } . '.';
        return Redactor::clean(implode("\n\n", $parts));
    }

    public function logTail(string $path, int $lines): string
    {
        if (!is_file($path)) {
            return '';
        }
        $size = filesize($path);
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }
        fseek($fh, max(0, (int) $size - 16384));
        $chunk = (string) stream_get_contents($fh);
        fclose($fh);
        $all = preg_split('/\r?\n/', trim($chunk)) ?: [];
        return Redactor::clean(implode("\n", array_slice($all, -$lines)));
    }
}
