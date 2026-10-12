<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class JobRepository
{
    public const ACTIVE = ['queued', 'running'];
    public const FINAL = ['completed', 'failed', 'cancelled'];

    public function __construct(private Database $db)
    {
    }

    /**
     * Insert a queued job holding $slot. Throws a duplicate-key PDOException when another
     * queued/running job already holds the slot; callers translate that into a user message.
     *
     * @param array<string,mixed> $options
     */
    public function createQueued(string $type, string $slot, ?int $providerId, ?string $examCode, array $options): int
    {
        $this->db->run(
            'INSERT INTO jobs (type, status, active_slot, provider_id, exam_code, options, message, queued_at)
             VALUES (?, \'queued\', ?, ?, ?, ?, ?, ?)',
            [$type, $slot, $providerId, $examCode, json_encode($options), 'Waiting for the worker', Database::now()]
        );
        return $this->db->lastId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT j.*, p.slug AS provider_slug FROM jobs j LEFT JOIN providers p ON p.id = j.provider_id WHERE j.id = ?',
            [$id]
        );
    }

    /** @return array<string,mixed>|null */
    public function findBySlot(string $slot): ?array
    {
        return $this->db->one('SELECT * FROM jobs WHERE active_slot = ?', [$slot]);
    }

    /**
     * @param array{status?:string,type?:string,provider_id?:int,exam_code?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];
        foreach (['status', 'type', 'provider_id', 'exam_code'] as $f) {
            if (isset($filters[$f]) && $filters[$f] !== '') {
                $where[] = 'j.' . $f . ' = ?';
                $params[] = $filters[$f];
            }
        }
        $sql = 'SELECT j.*, p.slug AS provider_slug FROM jobs j LEFT JOIN providers p ON p.id = j.provider_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY j.id DESC LIMIT ' . max(1, min(5000, $limit)) . ' OFFSET ' . max(0, $offset);
        return $this->db->all($sql, $params);
    }

    /** @return list<array<string,mixed>> */
    public function active(): array
    {
        return $this->db->all(
            "SELECT j.*, p.slug AS provider_slug FROM jobs j LEFT JOIN providers p ON p.id = j.provider_id
             WHERE j.status IN ('queued','running') ORDER BY j.id"
        );
    }

    /** @return list<array<string,mixed>> */
    public function running(): array
    {
        return $this->db->all("SELECT * FROM jobs WHERE status = 'running'");
    }

    public function hasQueued(): bool
    {
        return (bool) $this->db->value("SELECT 1 FROM jobs WHERE status = 'queued' LIMIT 1");
    }

    /** Atomically claim the oldest queued job. Returns null when there is none. */
    public function claimNext(int $workerPid): ?array
    {
        while (true) {
            $id = $this->db->value("SELECT id FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 1");
            if ($id === null) {
                return null;
            }
            $now = Database::now();
            $n = $this->db->run(
                "UPDATE jobs SET status = 'running', started_at = ?, heartbeat_at = ?, worker_pid = ?, message = 'Starting'
                 WHERE id = ? AND status = 'queued'",
                [$now, $now, $workerPid, $id]
            )->rowCount();
            if ($n === 1) {
                return $this->find((int) $id);
            }
        }
    }

    public function setChildPid(int $id, ?int $pid): void
    {
        $this->db->run('UPDATE jobs SET child_pid = ? WHERE id = ?', [$pid, $id]);
    }

    public function updateProgress(int $id, ?string $phase, ?int $done, ?int $total, ?string $message): void
    {
        $this->db->run(
            'UPDATE jobs SET phase = COALESCE(?, phase), progress_done = ?, progress_total = ?,
                 message = COALESCE(?, message), heartbeat_at = ? WHERE id = ?',
            [$phase, $done, $total, $message === null ? null : mb_substr($message, 0, 500), Database::now(), $id]
        );
    }

    public function heartbeat(int $id): void
    {
        $this->db->run('UPDATE jobs SET heartbeat_at = ? WHERE id = ?', [Database::now(), $id]);
    }

    /** Move a job to a final state and release its active slot. */
    public function finish(int $id, string $status, ?string $message, ?string $error = null, ?int $exitCode = null,
                           ?string $resultStatus = null, ?int $cacheVersionId = null): void
    {
        $this->db->run(
            'UPDATE jobs SET status = ?, active_slot = NULL, finished_at = ?, message = ?, error_summary = ?,
                 exit_code = ?, result_status = ?, cache_version_id = ?, child_pid = NULL
             WHERE id = ?',
            [$status, Database::now(), $message === null ? null : mb_substr($message, 0, 500), $error,
             $exitCode, $resultStatus, $cacheVersionId, $id]
        );
    }

    /** Cancel a job that has not started yet. Returns false if it was already claimed. */
    public function cancelQueued(int $id): bool
    {
        return $this->db->run(
            "UPDATE jobs SET status = 'cancelled', active_slot = NULL, finished_at = ?, message = 'Cancelled before it started'
             WHERE id = ? AND status = 'queued'",
            [Database::now(), $id]
        )->rowCount() === 1;
    }

    public function requestCancel(int $id): bool
    {
        return $this->db->run(
            "UPDATE jobs SET cancel_requested = 1, message = 'Cancelling…' WHERE id = ? AND status = 'running'",
            [$id]
        )->rowCount() === 1;
    }

    public function cancelRequested(int $id): bool
    {
        return (bool) $this->db->value('SELECT cancel_requested FROM jobs WHERE id = ?', [$id]);
    }

    /** @return array<string,int> counts by status */
    public function countsByStatus(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
