<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class CacheVersionRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string,mixed> $v */
    public function insert(array $v): int
    {
        $this->db->run(
            'INSERT INTO cache_versions (exam_id, version, status, source, collected_at, published_at, reported_count,
                 collected_count, checksum, size_bytes, schema_version, tool_revision, warnings, job_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$v['exam_id'], $v['version'], $v['status'], $v['source'], $v['collected_at'], $v['published_at'],
             $v['reported_count'], $v['collected_count'], $v['checksum'], $v['size_bytes'], $v['schema_version'],
             $v['tool_revision'], json_encode($v['warnings'] ?? []), $v['job_id']]
        );
        return $this->db->lastId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT v.*, e.code AS exam_code, e.name AS exam_name, p.slug AS provider_slug, p.name AS provider_name
             FROM cache_versions v JOIN exams e ON e.id = v.exam_id JOIN providers p ON p.id = e.provider_id WHERE v.id = ?',
            [$id]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByVersion(int $examId, string $version): ?array
    {
        $id = $this->db->value('SELECT id FROM cache_versions WHERE exam_id = ? AND version = ?', [$examId, $version]);
        return $id === null ? null : $this->find((int) $id);
    }

    /** @return list<array<string,mixed>> newest first */
    public function forExam(int $examId): array
    {
        return $this->db->all(
            'SELECT v.*, (SELECT COUNT(*) FROM documents d WHERE d.cache_version_id = v.id AND d.status = \'ready\') AS document_count
             FROM cache_versions v WHERE v.exam_id = ? ORDER BY v.collected_at DESC, v.id DESC',
            [$examId]
        );
    }

    /** Latest complete version, else latest partial (shown with a warning). */
    public function bestForExam(int $examId): ?array
    {
        $id = $this->db->value(
            "SELECT id FROM cache_versions WHERE exam_id = ? ORDER BY (status = 'complete') DESC, collected_at DESC, id DESC LIMIT 1",
            [$examId]
        );
        return $id === null ? null : $this->find((int) $id);
    }

    public function latestCompleteId(int $examId): ?int
    {
        $id = $this->db->value(
            "SELECT id FROM cache_versions WHERE exam_id = ? AND status = 'complete' ORDER BY collected_at DESC, id DESC LIMIT 1",
            [$examId]
        );
        return $id === null ? null : (int) $id;
    }

    /**
     * Library listing: every exam with at least one version.
     * @param array{provider?:string,q?:string,status?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function library(array $filters = []): array
    {
        $where = ['EXISTS (SELECT 1 FROM cache_versions cv WHERE cv.exam_id = e.id)'];
        $params = [];
        if (($filters['provider'] ?? '') !== '') {
            $where[] = 'p.slug = ?';
            $params[] = $filters['provider'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(e.code LIKE ? OR e.name LIKE ?)';
            $like = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $rows = $this->db->all(
            "SELECT e.id AS exam_id, e.code, e.name, e.reported_count, e.latest_version_id, p.slug AS provider_slug, p.name AS provider_name,
                    (SELECT COUNT(*) FROM cache_versions cv WHERE cv.exam_id = e.id) AS version_count,
                    (SELECT COUNT(*) FROM documents d JOIN cache_versions cv ON cv.id = d.cache_version_id
                        WHERE cv.exam_id = e.id AND d.status = 'ready') AS document_count,
                    (SELECT j.uuid FROM jobs j WHERE j.provider_id = e.provider_id AND j.exam_code = e.code
                        AND j.type = 'collect' AND j.status IN ('queued','running') LIMIT 1) AS active_job_uuid
             FROM exams e JOIN providers p ON p.id = e.provider_id
             WHERE " . implode(' AND ', $where) . ' ORDER BY p.slug, e.code',
            $params
        );
        foreach ($rows as &$r) {
            $best = $this->bestForExam((int) $r['exam_id']);
            $r['best'] = $best;
            $r['cache_status'] = $best === null ? 'none' : ((int) $r['latest_version_id'] === (int) $best['id'] ? 'complete' : 'partial-only');
        }
        unset($r);
        $status = $filters['status'] ?? '';
        if ($status !== '') {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['cache_status'] === $status));
        }
        return $rows;
    }

    /** @return array{exams:int,questions:int} totals over each exam's best version */
    public function totals(): array
    {
        $rows = $this->db->all(
            "SELECT v.collected_count FROM cache_versions v
             WHERE v.id = (SELECT v2.id FROM cache_versions v2 WHERE v2.exam_id = v.exam_id
                           ORDER BY (v2.status = 'complete') DESC, v2.collected_at DESC, v2.id DESC LIMIT 1)"
        );
        return ['exams' => count($rows), 'questions' => array_sum(array_map(static fn($r) => (int) $r['collected_count'], $rows))];
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 5): array
    {
        return $this->db->all(
            'SELECT v.*, e.code AS exam_code, e.name AS exam_name, p.slug AS provider_slug
             FROM cache_versions v JOIN exams e ON e.id = v.exam_id JOIN providers p ON p.id = e.provider_id
             ORDER BY v.published_at DESC, v.id DESC LIMIT ' . max(1, min(50, $limit))
        );
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM cache_versions WHERE id = ?', [$id]);
    }
}
