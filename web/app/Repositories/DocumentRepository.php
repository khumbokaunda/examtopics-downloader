<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class DocumentRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT d.*, v.version, v.exam_id, e.code AS exam_code, p.slug AS provider_slug
             FROM documents d JOIN cache_versions v ON v.id = d.cache_version_id
             JOIN exams e ON e.id = v.exam_id JOIN providers p ON p.id = e.provider_id WHERE d.id = ?',
            [$id]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByKey(int $versionId, string $format, string $variant, string $hash): ?array
    {
        $id = $this->db->value(
            'SELECT id FROM documents WHERE cache_version_id = ? AND format = ? AND variant = ? AND options_hash = ?',
            [$versionId, $format, $variant, $hash]
        );
        return $id === null ? null : $this->find((int) $id);
    }

    /** @param array<string,mixed> $d */
    public function save(array $d): int
    {
        $this->db->run(
            'INSERT INTO documents (cache_version_id, format, variant, options_hash, options, status, rel_path, filename,
                 size_bytes, checksum, error, generated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE options = VALUES(options), status = VALUES(status), rel_path = VALUES(rel_path),
                 filename = VALUES(filename), size_bytes = VALUES(size_bytes), checksum = VALUES(checksum),
                 error = VALUES(error), generated_at = VALUES(generated_at)',
            [$d['cache_version_id'], $d['format'], $d['variant'], $d['options_hash'], json_encode($d['options']),
             $d['status'], $d['rel_path'], $d['filename'], $d['size_bytes'], $d['checksum'], $d['error'], Database::now()]
        );
        return (int) $this->db->value(
            'SELECT id FROM documents WHERE cache_version_id = ? AND format = ? AND variant = ? AND options_hash = ?',
            [$d['cache_version_id'], $d['format'], $d['variant'], $d['options_hash']]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forVersion(int $versionId): array
    {
        return $this->db->all('SELECT * FROM documents WHERE cache_version_id = ? ORDER BY variant, format', [$versionId]);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 5): array
    {
        return $this->db->all(
            "SELECT d.*, v.version, e.code AS exam_code, p.slug AS provider_slug
             FROM documents d JOIN cache_versions v ON v.id = d.cache_version_id
             JOIN exams e ON e.id = v.exam_id JOIN providers p ON p.id = e.provider_id
             WHERE d.status = 'ready' ORDER BY d.generated_at DESC, d.id DESC LIMIT " . max(1, min(50, $limit))
        );
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM documents WHERE id = ?', [$id]);
    }
}
