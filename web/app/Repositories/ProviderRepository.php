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
                    (SELECT COUNT(*) FROM inventory_refreshes s WHERE s.provider_id = p.id AND s.status IN (\'complete\', \'partial\')) AS success_count,
                    r.status AS refresh_status, r.finished_at AS refresh_at, r.error AS refresh_error,
                    (SELECT j.uuid FROM jobs j WHERE j.id = r.job_id) AS refresh_job_uuid
             FROM providers p
             LEFT JOIN inventory_refreshes r ON r.id = (
                 SELECT MAX(id) FROM inventory_refreshes WHERE provider_id = p.id)
             ORDER BY p.slug'
        );
    }

    /**
     * Has this provider's exam list never been loaded (no exams, no successful refresh)?
     * Members may start the first refresh of such a provider; later ones are an administrator's.
     */
    public function neverFetched(int $id): bool
    {
        return (int) $this->db->value(
            "SELECT (SELECT COUNT(*) FROM exams WHERE provider_id = ? AND in_inventory = 1)
                  + (SELECT COUNT(*) FROM inventory_refreshes WHERE provider_id = ? AND status IN ('complete','partial'))",
            [$id, $id]
        ) === 0;
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

    /**
     * Insert or update; a discovered name never overwrites a manual provider's source. A name
     * like "Cisco (120 exams)" is stored as "Cisco" with the count kept separately.
     */
    public function upsert(string $slug, ?string $name, string $source, ?int $listedExams = null): int
    {
        if ($name !== null) {
            [$name, $count] = self::splitName($name);
            $listedExams ??= $count;
            $name = $name === '' ? null : $name;
        }
        $now = Database::now();
        $this->db->run(
            'INSERT INTO providers (slug, name, source, listed_exam_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = COALESCE(VALUES(name), name),
                 listed_exam_count = COALESCE(VALUES(listed_exam_count), listed_exam_count), updated_at = VALUES(updated_at)',
            [$slug, $name, $source, $listedExams, $now, $now]
        );
        return (int) $this->db->value('SELECT id FROM providers WHERE slug = ?', [$slug]);
    }

    /**
     * "Certinia (1 exams)" becomes ["Certinia", 1]; a name without a count is returned as is.
     *
     * @return array{0:string,1:?int}
     */
    public static function splitName(string $name): array
    {
        $name = trim($name);
        if (preg_match('/^(.*?)\s*\((\d+)\s+exams?\)$/i', $name, $m) === 1) {
            return [trim($m[1]), (int) $m[2]];
        }
        return [$name, null];
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM providers');
    }
}
