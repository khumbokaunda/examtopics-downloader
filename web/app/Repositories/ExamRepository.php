<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ExamRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Inventory exams for a provider, with library status.
     * @return list<array<string,mixed>>
     */
    public function forProvider(int $providerId, string $search = '', bool $inventoryOnly = true): array
    {
        $sql = 'SELECT e.*, v.version AS latest_version, v.collected_at AS latest_collected_at,
                       v.collected_count AS latest_collected_count
                FROM exams e
                LEFT JOIN cache_versions v ON v.id = e.latest_version_id
                WHERE e.provider_id = ?';
        $params = [$providerId];
        if ($inventoryOnly) {
            $sql .= ' AND e.in_inventory = 1';
        }
        if ($search !== '') {
            $sql .= ' AND (e.code LIKE ? OR e.name LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }
        return $this->db->all($sql . ' ORDER BY e.code', $params);
    }

    /** @return array<string,mixed>|null */
    public function find(int $providerId, string $code): ?array
    {
        return $this->db->one('SELECT * FROM exams WHERE provider_id = ? AND code = ?', [$providerId, $code]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->one('SELECT * FROM exams WHERE id = ?', [$id]);
    }

    /** Create the exam row if needed (e.g. when rebuilding from manifests); returns its id. */
    public function ensure(int $providerId, string $code, ?string $name = null): int
    {
        $now = Database::now();
        $this->db->run(
            'INSERT INTO exams (provider_id, code, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = COALESCE(name, VALUES(name))',
            [$providerId, $code, $name === '' ? null : $name, $now, $now]
        );
        return (int) $this->db->value('SELECT id FROM exams WHERE provider_id = ? AND code = ?', [$providerId, $code]);
    }

    /**
     * Apply an inventory document. Exams missing from a COMPLETE inventory are marked as no
     * longer listed (rows are kept: the library may hold data for them). A partial inventory
     * never un-lists anything, since the missing exams may be on the pages that failed.
     *
     * @param list<array{code:string,name:string,question_count:int}> $exams
     */
    public function applyInventory(int $providerId, array $exams, bool $complete): void
    {
        $now = Database::now();
        $this->db->transaction(function (Database $db) use ($providerId, $exams, $complete, $now): void {
            if ($complete) {
                $db->run('UPDATE exams SET in_inventory = 0 WHERE provider_id = ?', [$providerId]);
            }
            foreach ($exams as $e) {
                $db->run(
                    'INSERT INTO exams (provider_id, code, name, reported_count, in_inventory, inventory_refreshed_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 1, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE name = COALESCE(VALUES(name), name), reported_count = VALUES(reported_count),
                         in_inventory = 1, inventory_refreshed_at = VALUES(inventory_refreshed_at), updated_at = VALUES(updated_at)',
                    [$providerId, $e['code'], $e['name'] === '' ? null : $e['name'], $e['question_count'], $now, $now, $now]
                );
            }
        });
    }

    public function setLatestVersion(int $examId, ?int $versionId): void
    {
        $this->db->run('UPDATE exams SET latest_version_id = ?, updated_at = ? WHERE id = ?', [$versionId, Database::now(), $examId]);
    }

    public function setName(int $examId, string $name): void
    {
        if ($name !== '') {
            $this->db->run('UPDATE exams SET name = COALESCE(name, ?) WHERE id = ?', [$name, $examId]);
        }
    }
}
