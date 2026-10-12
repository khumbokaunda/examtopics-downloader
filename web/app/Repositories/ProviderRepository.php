<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ProviderRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string,mixed>> providers with exam counts and latest inventory refresh */
    public function allWithStats(): array
    {
        return $this->db->all(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM exams e WHERE e.provider_id = p.id AND e.in_inventory = 1) AS exam_count,
                    (SELECT COUNT(*) FROM exams e WHERE e.provider_id = p.id AND e.latest_version_id IS NOT NULL) AS cached_count,
                    r.status AS refresh_status, r.finished_at AS refresh_at, r.error AS refresh_error
             FROM providers p
             LEFT JOIN inventory_refreshes r ON r.id = (
                 SELECT MAX(id) FROM inventory_refreshes WHERE provider_id = p.id)
             ORDER BY p.slug'
        );
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->one('SELECT * FROM providers WHERE slug = ?', [$slug]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM providers WHERE id = ?', [$id]);
    }

    /** Insert or update; a discovered name never overwrites a manual provider's source. */
    public function upsert(string $slug, ?string $name, string $source): int
    {
        $now = Database::now();
        $this->db->run(
            'INSERT INTO providers (slug, name, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = COALESCE(VALUES(name), name), updated_at = VALUES(updated_at)',
            [$slug, $name, $source, $now, $now]
        );
        return (int) $this->db->value('SELECT id FROM providers WHERE slug = ?', [$slug]);
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM providers');
    }
}
