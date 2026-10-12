<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class InventoryRefreshRepository
{
    public function __construct(private Database $db)
    {
    }

    public function record(int $providerId, ?int $jobId, string $status, ?int $examCount, ?int $pages, ?int $failedPages, ?string $error): void
    {
        $this->db->run(
            'INSERT INTO inventory_refreshes (provider_id, job_id, status, exam_count, listing_pages, failed_pages, error, finished_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$providerId, $jobId, $status, $examCount, $pages, $failedPages, $error, Database::now()]
        );
    }

    /** @return array<string,mixed>|null */
    public function latest(int $providerId): ?array
    {
        return $this->db->one('SELECT * FROM inventory_refreshes WHERE provider_id = ? ORDER BY id DESC LIMIT 1', [$providerId]);
    }

    /** @return array<string,mixed>|null */
    public function latestSuccessful(int $providerId): ?array
    {
        return $this->db->one(
            "SELECT * FROM inventory_refreshes WHERE provider_id = ? AND status IN ('complete','partial') ORDER BY id DESC LIMIT 1",
            [$providerId]
        );
    }
}
