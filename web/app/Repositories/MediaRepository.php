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
