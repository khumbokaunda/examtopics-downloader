<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Ids;
use App\Core\Logger;
use App\Core\Paths;
use App\Core\Redactor;
use App\Repositories\CacheVersionRepository;
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

    /** When the current job started; the runtime limit covers all of its steps. */
    private int $startedAt = 0;

    public function __construct(
        private Config $config,
        private Paths $paths,
        private Logger $log,
        private JobRepository $jobs,
        private ProviderRepository $providers,
        private ExamRepository $exams,
        private InventoryRefreshRepository $refreshes,
        private CacheService $cache,
        private CacheVersionRepository $versions,
        private MediaStore $media,
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
        $this->startedAt = time();

        $dir = $this->paths->jobDir($id);
        Paths::ensureDir($dir);
        $out = $dir . '/output.json';
        $events = $dir . '/events.jsonl';
        $log = $dir . '/tool.log';
        @unlink($out);

        $binary = (string) $this->config->get('tool.binary');
        if (!is_file($binary) || !is_executable($binary)) {
            $this->jobs->finish($id, 'failed', 'Collection tool not found',
                'The collection tool is not installed at the configured path. Build it with: go build -o bin/examtopics-downloader ./cmd');
            return;
        }

        if ($type === 'images') {
            $this->runImagesJob($id, $options);
            return;
        }

        // The argument list is built only from validated values held in the database; no
        // request data reaches it directly. There is no shell involved.
        $argv = [$binary, '-batch', '-rps', $this->rpsArg($options), '-out-json', $out, '-events', $events];
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
                $links = $this->linkArgs((int) $provider['id'], (string) $provider['slug'], (string) $job['exam_code'], $options, $dir);
                if (is_string($links)) {
                    $this->jobs->finish($id, 'failed', 'Collection tool out of date', $links);
                    return;
                }
                array_push($argv, ...$links);
                break;
            default:
                $this->jobs->finish($id, 'failed', 'Unknown job type', 'Unknown job type.');
                return;
        }

        $this->log->info('jobs', 'Starting tool', ['job_id' => $id, 'type' => $type, 'exam' => $job['exam_code']]);
        $run = $this->runTool($id, $argv, $events, $log);
        if ($run['stop'] !== null) {
            $this->finishStopped($id, $run['stop'], $run['exit']);
            return;
        }

        $exit = (int) $run['exit'];
        $terminal = $run['terminal'];
        $failure = is_array($terminal) && ($terminal['event'] ?? '') === 'failed' ? (string) ($terminal['message'] ?? '') : '';
        match ($type) {
            'providers' => $this->finishProviders($id, $exit, $out, $failure, $log),
            'inventory' => $this->finishInventory($id, $exit, $out, $failure, $log, $provider),
            'collect' => $this->finishCollect($id, $exit, $out, $failure, $log, $provider, $job, $options),
        };
    }

    /** @param array<string,mixed> $options */
    private function rpsArg(array $options): string
    {
        $rps = (float) ($options['rps'] ?? $this->config->get('tool.default_rps', 1.0));
        return rtrim(rtrim(sprintf('%.3f', max(0.05, min(5.0, $rps))), '0'), '.');
    }

    /**
     * Run the tool to completion, mirroring its progress events into the job row and
     * stopping it on cancellation, the runtime limit or a worker shutdown.
     *
     * @param list<string> $argv
     * @return array{exit:int|null,terminal:array<string,mixed>|null,stop:string|null}
     */
    private function runTool(int $id, array $argv, string $events, string $log): array
    {
        @unlink($events);
        $process = new Process($argv, dirname($events), WorkerSupervisor::toolEnv($this->config), $log);
        $this->jobs->setChildPid($id, $process->pid());

        $tail = new EventTail($events);
        $terminal = null;
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
                } elseif (time() - $this->startedAt > $maxRuntime) {
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
        $this->jobs->setChildPid($id, null);
        return ['exit' => $exit, 'terminal' => $terminal, 'stop' => $stopReason];
    }

    private function finishStopped(int $id, string $reason, ?int $exit): void
    {
        if ($reason === 'cancelled') {
            $this->jobs->finish($id, 'cancelled', 'Cancelled', 'Cancelled by the user. Previously cached data is unchanged.', $exit);
            $this->log->info('jobs', 'Job cancelled', ['job_id' => $id]);
            return;
        }
        $msg = $reason === 'timeout'
            ? 'Stopped after exceeding the maximum runtime of ' . intdiv((int) $this->config->get('tool.max_runtime', 21600), 60) . ' minutes.'
            : 'Stopped because the worker was shut down.';
        $this->jobs->finish($id, 'failed', 'Stopped', $msg . ' Previously cached data is unchanged.', $exit);
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
                $listed = is_int($p['exam_count'] ?? null) && $p['exam_count'] > 0 ? $p['exam_count'] : null;
                $this->providers->upsert($p['slug'], $name, 'discovered', $listed);
                $n++;
            }
        }
        $this->jobs->finish($id, 'completed', "Found {$n} providers", null, $exit);
        $this->log->info('inventory', 'Providers discovered', ['job_id' => $id, 'count' => $n]);
    }

    /**
     * Discussion links to hand the tool: the ones the last inventory found (so only the
     * newest listing pages are read) and any the user added for questions ExamTopics does
     * not list. For an exam the listing pages do not show at all, only the added links are
     * fetched and no listing page is read.
     *
     * @param array<string,mixed> $options
     * @return list<string>|string the arguments, or why the collection cannot run
     */
    private function linkArgs(int $providerId, string $provider, string $exam, array $options, string $dir): array|string
    {
        $store = new InventoryLinks($this->paths);
        $fullScan = !empty($options['full_scan']);
        $known = $fullScan ? null : $store->forExam($provider, $exam);
        $added = $store->added($provider, $exam);
        if ($known === null && $added === []) {
            return [];
        }
        $missing = ToolCheck::missingOptions((string) $this->config->get('tool.binary'));
        if ($added === []) {
            // A tool older than the remembered links scans every listing page instead.
            return in_array('-links-from', $missing, true) ? [] : ['-links-from', (string) $known['path'], '-newer-than', (string) $known['newest_id']];
        }
        if (array_intersect(['-links-from', '-links-only'], $missing) !== []) {
            return 'The collection tool on the server is older than the web app, so it cannot collect the links you added. '
                . ToolCheck::rebuildHint() . ' Then try again.';
        }
        $file = $dir . '/links.txt';
        $lines = $known !== null ? (file((string) $known['path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        Paths::atomicWrite($file, implode("\n", array_values(array_unique(array_merge($lines, $added)))) . "\n");
        $listed = (int) ($this->exams->find($providerId, $exam)['in_inventory'] ?? 0) === 1;
        return match (true) {
            $known !== null => ['-links-from', $file, '-newer-than', (string) $known['newest_id']],
            $listed || $fullScan => ['-links-from', $file, '-newer-than', '0'], // every listing page as well
            default => ['-links-from', $file, '-links-only'],
        };
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
        // Each exam's links, so its next collection can skip most listing pages.
        (new InventoryLinks($this->paths))->remember((string) $provider['slug'], $doc);
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
        $warnings = $published['warnings'];

        // The questions are published; their images are a second step. Stopping it, or its
        // failure, leaves the version in place and the missing images for a later run.
        $version = $this->versions->find((int) $published['version_id']);
        if ($version !== null && (bool) $this->config->get('media.download', true)) {
            $images = $this->saveImages($id, $version, $options);
            if ($images['note'] !== null) {
                $msg .= '; ' . $images['note'];
            }
            if ($images['warning'] !== null) {
                $warnings[] = $images['warning'];
            }
        }
        $this->jobs->finish($id, 'completed', $msg, $warnings === [] ? null : implode("\n", $warnings),
            $exit, $published['status'], $published['version_id']);
    }

    /**
     * The "images" job: download the missing images of one published version.
     *
     * @param array<string,mixed> $options
     */
    private function runImagesJob(int $id, array $options): void
    {
        $version = $this->versions->find((int) ($options['version_id'] ?? 0));
        if ($version === null) {
            $this->jobs->finish($id, 'failed', 'Version not found', 'The version whose images were requested no longer exists.');
            return;
        }
        $images = $this->saveImages($id, $version, $options);
        if ($images['stop'] === 'cancelled') {
            $this->jobs->finish($id, 'cancelled', 'Cancelled', 'Cancelled by the user. Images saved before that are kept.', null, null, (int) $version['id']);
            return;
        }
        if ($images['stop'] !== null) {
            $this->finishStopped($id, $images['stop'], null);
            return;
        }
        $failed = $images['saved'] === 0 && $images['failed'] > 0;
        $this->jobs->finish($id, $failed ? 'failed' : 'completed', ucfirst((string) ($images['note'] ?? 'no images to download')),
            $images['warning'], $images['exit'], null, (int) $version['id']);
    }

    /**
     * Download the version's images that are not saved yet, with the tool, and keep them.
     *
     * @param array<string,mixed> $version cache_versions row
     * @param array<string,mixed> $options job options (rps)
     * @return array{note:?string,warning:?string,stop:?string,saved:int,failed:int,exit:?int}
     */
    private function saveImages(int $id, array $version, array $options): array
    {
        $result = ['note' => null, 'warning' => null, 'stop' => null, 'saved' => 0, 'failed' => 0, 'exit' => null];
        try {
            $urls = MediaStore::urlsOf($this->cache->load($version));
        } catch (Throwable $e) {
            $result['warning'] = 'Images were not downloaded: ' . Redactor::clean($e->getMessage());
            return $result;
        }
        $missing = $this->media->missing($urls);
        if ($urls === []) {
            return $result;
        }
        if ($missing === []) {
            $result['note'] = 'all ' . count($urls) . ' images already saved';
            return $result;
        }
        if (in_array('-images-from', ToolCheck::missingOptions((string) $this->config->get('tool.binary')), true)) {
            $result['note'] = (count($urls) - count($missing)) . ' of ' . count($urls) . ' images saved';
            $result['warning'] = 'Images were not downloaded: the collection tool on the server is older than the web app. '
                . ToolCheck::rebuildHint() . ' Then use "Download missing images" on the exam page.';
            $result['failed'] = count($missing);
            return $result;
        }

        $dir = $this->paths->jobDir($id);
        $imagesDir = $dir . '/images';
        $list = $dir . '/images.txt';
        $out = $dir . '/images.json';
        Paths::ensureDir($imagesDir);
        Paths::atomicWrite($list, implode("\n", $missing) . "\n");
        @unlink($out);

        $this->jobs->updateProgress($id, 'images', 0, count($missing), 'Downloading ' . count($missing) . ' images');
        $argv = [(string) $this->config->get('tool.binary'), '-batch', '-rps', $this->rpsArg($options),
                 '-images-from', $list, '-images-dir', $imagesDir, '-out-json', $out, '-events', $dir . '/images-events.jsonl'];
        $this->log->info('jobs', 'Downloading images', ['job_id' => $id, 'exam' => $version['exam_code'], 'images' => count($missing)]);
        $run = $this->runTool($id, $argv, $dir . '/images-events.jsonl', $dir . '/tool.log');
        $result['exit'] = $run['exit'];
        $result['stop'] = $run['stop'];

        // Whatever finished before a stop is kept.
        $doc = is_file($out) ? $this->readDoc($out, 'images') : null;
        $counts = $doc !== null ? $this->media->import($imagesDir, $doc, $missing) : ['saved' => 0, 'failed' => 0, 'skipped' => 0];
        $this->removeTree($imagesDir);
        @unlink($list);
        @unlink($out);

        $result['saved'] = $counts['saved'];
        $result['failed'] = $counts['failed'];
        $already = count($urls) - count($missing);
        $note = ($counts['saved'] + $already) . ' of ' . count($urls) . ' images saved';
        if ($run['stop'] === 'cancelled') {
            $note .= ' (image download cancelled)';
        }
        $result['note'] = $note;
        if ($doc === null && $run['stop'] === null) {
            $result['warning'] = 'The image download did not finish: ' . $this->failureText((int) $run['exit'], '', $dir . '/tool.log');
            $result['failed'] = count($missing);
        } elseif ($counts['failed'] > 0 || $counts['skipped'] > 0) {
            $parts = [];
            if ($counts['failed'] > 0) {
                $parts[] = $counts['failed'] . ' could not be downloaded (Download missing images on the exam page tries them again)';
            }
            if ($counts['skipped'] > 0) {
                $parts[] = $counts['skipped'] . ' were not ExamTopics images or were too large';
            }
            $result['warning'] = 'Images: ' . implode('; ', $parts) . '.';
        }
        $this->log->info('jobs', 'Images saved', ['job_id' => $id, 'saved' => $counts['saved'], 'failed' => $counts['failed'], 'skipped' => $counts['skipped']]);
        return $result;
    }

    /** Remove a job's scratch folder of downloaded images (plain files only). */
    private function removeTree(string $dir): void
    {
        foreach (glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f) || is_link($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
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
        // A tool built before the web app was updated rejects the newer options. Say so,
        // instead of showing its usage text.
        if ($exit === 64 && preg_match('/flag provided but not defined: (-[a-z][a-z-]*)/', $this->logTail($log, 400), $m) === 1) {
            return 'The collection tool on the server is older than the web app: it does not know the ' . $m[1]
                . ' option. Rebuild it in the project folder with: go build -o bin/examtopics-downloader ./cmd'
                . ' Then try again. (Exit code 64.)';
        }
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
        $offset = max(0, (int) $size - max(16384, $lines * 400));
        fseek($fh, $offset);
        $chunk = (string) stream_get_contents($fh);
        fclose($fh);
        $all = preg_split('/\r?\n/', trim($chunk)) ?: [];
        if ($offset > 0) {
            array_shift($all); // the read started mid-line
        }
        return Redactor::clean(implode("\n", array_slice($all, -$lines)));
    }
}
