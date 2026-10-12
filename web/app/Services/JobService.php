<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Ids;
use App\Core\Logger;
use App\Repositories\JobRepository;

/**
 * Creates and cancels jobs on behalf of the web UI. Every job that runs the collection
 * engine goes through the single worker, so the engine never runs twice at once:
 *
 *  slot "collect"            one collection in the whole application
 *  slot "inventory:<slug>"   one inventory refresh per provider
 *  slot "providers"          one provider discovery
 *
 * Slots are enforced by a UNIQUE index (jobs.active_slot), so a duplicate submission fails
 * atomically even if two requests arrive at the same instant.
 */
final class JobService
{
    public function __construct(
        private JobRepository $jobs,
        private WorkerSupervisor $supervisor,
        private Config $config,
        private Logger $log,
    ) {
    }

    /**
     * @param array<string,mixed> $provider providers row
     * @param array{source_mode:string,rps:float} $options already validated
     * @throws ActiveJobException
     */
    public function enqueueCollect(array $provider, string $examCode, array $options): int
    {
        if (!Ids::isExam($examCode)) {
            throw new \InvalidArgumentException('Invalid exam code.');
        }
        return $this->enqueue('collect', 'collect', (int) $provider['id'], $examCode, $options);
    }

    /** @param array<string,mixed> $provider */
    public function enqueueInventory(array $provider): int
    {
        return $this->enqueue('inventory', 'inventory:' . $provider['slug'], (int) $provider['id'], null,
            ['rps' => (float) $this->config->get('tool.default_rps', 1.0)]);
    }

    public function enqueueProviders(): int
    {
        return $this->enqueue('providers', 'providers', null, null, ['rps' => (float) $this->config->get('tool.default_rps', 1.0)]);
    }

    /** @return string message for the user */
    public function cancel(int $jobId): string
    {
        if ($this->jobs->cancelQueued($jobId)) {
            $this->log->info('jobs', 'Cancelled queued job', ['job_id' => $jobId]);
            return 'The job was cancelled before it started.';
        }
        if ($this->jobs->requestCancel($jobId)) {
            $this->log->info('jobs', 'Cancellation requested', ['job_id' => $jobId]);
            return 'Cancellation requested; the worker will stop the job within a few seconds.';
        }
        return 'This job is not running.';
    }

    /**
     * Validate collection options from a form. Only verified tool options are accepted.
     *
     * @return array{source_mode:string,rps:float}
     */
    public function collectOptions(?string $sourceMode, ?string $rps): array
    {
        $mode = in_array($sourceMode, ['auto', 'live'], true) ? $sourceMode : 'auto';
        $allowed = array_map('floatval', (array) $this->config->get('tool.allowed_rps', [0.25, 0.5, 1.0]));
        $rate = (float) $this->config->get('tool.default_rps', 1.0);
        if ($rps !== null && is_numeric($rps) && in_array((float) $rps, $allowed, true)) {
            $rate = (float) $rps;
        }
        return ['source_mode' => $mode, 'rps' => $rate];
    }

    /** @param array<string,mixed> $options */
    private function enqueue(string $type, string $slot, ?int $providerId, ?string $examCode, array $options): int
    {
        try {
            $id = $this->jobs->createQueued($type, $slot, $providerId, $examCode, $options);
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                $existing = $this->jobs->findBySlot($slot);
                throw new ActiveJobException($existing);
            }
            throw $e;
        }
        $this->log->info('jobs', 'Job queued', ['job_id' => $id, 'type' => $type, 'exam' => $examCode]);
        $this->supervisor->ensure();
        return $id;
    }
}
