<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Downloaded images, one row per image address. The files themselves are in MediaStore. */
final class MediaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** A short mark that changes whenever any image's status changes, or one is added or removed. */
    public function mark(): string
    {
        return md5((string) json_encode($this->db->all(
            'SELECT status, COUNT(*) AS n, MAX(updated_at) AS last FROM media GROUP BY status ORDER BY status')));
    }

    /**
     * How many of these addresses are known in each status, counted by the database rather than
     * by reading every row.
     *
     * @param list<string> $urls
     * @return array<string,int> status => count
     */
    public function statusCounts(array $urls): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($urls)), 1000) as $chunk) {
            $hashes = array_map(static fn(string $u) => hash('sha256', $u), $chunk);
            $marks = implode(',', array_fill(0, count($hashes), '?'));
            foreach ($this->db->all("SELECT status, COUNT(*) AS n FROM media WHERE url_hash IN ($marks) GROUP BY status", $hashes) as $row) {
                $out[(string) $row['status']] = ($out[(string) $row['status']] ?? 0) + (int) $row['n'];
            }
        }
        return $out;
    }

    /**
     * Rows for the given addresses, keyed by address. Unknown addresses are absent.
     *
     * @param list<string> $urls
     * @return array<string,array<string,mixed>>
     */
    public function lookup(array $urls): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($urls)), 500) as $chunk) {
            $hashes = array_map(static fn(string $u) => hash('sha256', $u), $chunk);
            $marks = implode(',', array_fill(0, count($hashes), '?'));
            foreach ($this->db->all("SELECT * FROM media WHERE url_hash IN ($marks)", $hashes) as $row) {
                $out[(string) $row['url']] = $row;
            }
        }
        return $out;
    }

    /** Record the outcome for one address. A saved image is never downgraded by a later failure. */
    public function record(string $url, string $status, ?string $sha256, ?string $mime, ?int $bytes, ?string $error): void
    {
        $error = $error === null ? null : mb_substr($error, 0, 500);
        $this->db->run(
            "INSERT INTO media (url_hash, url, status, sha256, mime, bytes, error, attempts, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                 sha256 = IF(status = 'saved' AND VALUES(status) <> 'saved', sha256, VALUES(sha256)),
                 mime = IF(status = 'saved' AND VALUES(status) <> 'saved', mime, VALUES(mime)),
                 bytes = IF(status = 'saved' AND VALUES(status) <> 'saved', bytes, VALUES(bytes)),
                 error = IF(status = 'saved' AND VALUES(status) <> 'saved', error, VALUES(error)),
                 attempts = attempts + 1, updated_at = VALUES(updated_at),
                 status = IF(status = 'saved' AND VALUES(status) <> 'saved', status, VALUES(status))",
            [hash('sha256', $url), mb_substr($url, 0, 2048), $status, $sha256, $mime, $bytes, $error, Database::now()]
        );
    }

    /** @return array<string,mixed>|null a saved image with this content hash */
    public function findSaved(string $sha256): ?array
    {
        return $this->db->one("SELECT * FROM media WHERE sha256 = ? AND status = 'saved' LIMIT 1", [$sha256]);
    }

    /** @return array<string,mixed>|null a picture uploaded by hand, by content hash */
    public function findUpload(string $sha256): ?array
    {
        return $this->db->one('SELECT * FROM uploaded_images WHERE sha256 = ?', [$sha256]);
    }

    public function recordUpload(string $sha256, string $mime, int $bytes, ?int $userId): void
    {
        $this->db->run('INSERT IGNORE INTO uploaded_images (sha256, mime, bytes, user_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$sha256, $mime, $bytes, $userId, Database::now()]);
    }

    /** @return list<array{url:string,sha256:?string,mime:?string}> every recorded address */
    public function all(): array
    {
        return $this->db->all('SELECT url, sha256, mime FROM media');
    }

    /** @param list<string> $urls */
    public function forget(array $urls): void
    {
        foreach (array_chunk($urls, 500) as $chunk) {
            $hashes = array_map(static fn(string $u) => hash('sha256', $u), $chunk);
            $this->db->run('DELETE FROM media WHERE url_hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')', $hashes);
        }
    }

    public function shaInUse(string $sha256): bool
    {
        return $this->db->value("SELECT 1 FROM media WHERE sha256 = ? AND status = 'saved' LIMIT 1", [$sha256]) !== null;
    }

    /** @return array{files:int,bytes:int} distinct saved files and their total size */
    public function totals(): array
    {
        $row = $this->db->one("SELECT COUNT(*) AS files, COALESCE(SUM(bytes), 0) AS bytes
                               FROM (SELECT sha256, MAX(bytes) AS bytes FROM media WHERE status = 'saved' GROUP BY sha256) t");
        return ['files' => (int) ($row['files'] ?? 0), 'bytes' => (int) ($row['bytes'] ?? 0)];
    }
}
