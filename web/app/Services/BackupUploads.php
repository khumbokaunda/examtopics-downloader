<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Paths;

/**
 * Backup archives uploaded from the browser in pieces. One large upload request can outlast
 * the web server's timeouts and its body size limit, so the page sends the file a few
 * megabytes at a time and the pieces are appended here, in storage/backups/uploads:
 *
 *   <id>.json   who is uploading which file (name, size, last modified)
 *   <id>.part   the bytes received so far
 *
 * The id comes from the person and the file's name, size and date, so choosing the same file
 * again after a dropped connection or a closed tab carries on where it stopped. Each piece
 * names the offset it starts at; a piece for any other offset is refused with the size
 * received, and the page resends from there. A finished file is checked like any other
 * upload, then moved into storage/backups under a name of our own.
 *
 * Methods return the response body; a failure has "error" and "http" (the status to send).
 */
final class BackupUploads
{
    /** Largest piece the page is told to send; it halves it if the web server refuses that. */
    public const MAX_CHUNK = 8 * 1024 * 1024;
    private const ID_RE = '/^[a-f0-9]{32}$/';

    public function __construct(private App $app, private Backup $backup)
    {
    }

    public function dir(): string
    {
        return $this->backup->dir() . '/uploads';
    }

    /**
     * Start an upload, or carry on with an unfinished one of the same file.
     *
     * @return array<string,mixed>
     */
    public function start(int $userId, string $name, int $size, int $modified): array
    {
        $name = self::cleanName($name);
        if ($size <= 0) {
            return ['error' => 'Choose a backup file to upload.', 'http' => 422];
        }
        if (!class_exists(\ZipArchive::class)) {
            return ['error' => 'The PHP zip extension is missing, so backups cannot be read. On the server: apt install php-zip, then restart PHP.', 'http' => 422];
        }
        $id = substr(hash('sha256', $userId . "\n" . $name . "\n" . $size . "\n" . $modified), 0, 32);
        Paths::ensureDir($this->dir());
        $part = $this->dir() . '/' . $id . '.part';
        $received = is_file($part) && is_file($this->dir() . '/' . $id . '.json') ? (int) filesize($part) : 0;
        $free = @disk_free_space($this->dir());
        // Room for the rest of the file, with some to spare for everything else.
        if ($free !== false && $free < ($size - $received) + 64 * 1024 * 1024) {
            return ['error' => 'There is not enough disk space for this file: it needs ' . fmt_bytes($size - $received)
                . ' and ' . fmt_bytes((int) $free) . ' is free.', 'http' => 507];
        }
        if ($received === 0) {
            Paths::atomicWrite($this->dir() . '/' . $id . '.json', (string) json_encode(
                ['user_id' => $userId, 'name' => $name, 'size' => $size, 'modified' => $modified, 'started_at' => time()], JSON_UNESCAPED_SLASHES));
            if (@file_put_contents($part, '') === false) {
                return ['error' => 'Cannot write to ' . $this->dir() . '. Check that the web server can write to storage/backups.', 'http' => 500];
            }
        }
        return ['id' => $id, 'name' => $name, 'size' => $size, 'received' => min($received, $size), 'chunk' => self::chunkSize()];
    }

    /**
     * Append one piece, read from $file, if it starts where the received bytes end.
     *
     * @return array<string,mixed>
     */
    public function append(string $id, int $userId, int $offset, string $file): array
    {
        $meta = $this->meta($id, $userId);
        if ($meta === null) {
            return ['error' => 'This upload is no longer on the server. Choose the file again to start over.', 'http' => 404];
        }
        $length = (int) filesize($file);
        $handle = fopen($this->dir() . '/' . $id . '.part', 'ab');
        if ($handle === false) {
            return ['error' => 'Cannot write to ' . $this->dir() . '.', 'http' => 500];
        }
        try {
            // Two tabs sending the same file take turns; the second one learns the new size.
            flock($handle, LOCK_EX);
            clearstatcache(true, $this->dir() . '/' . $id . '.part');
            $received = (int) fstat($handle)['size'];
            if ($offset !== $received) {
                return ['error' => 'The server has ' . $received . ' bytes of this file.', 'received' => $received, 'http' => 409];
            }
            if ($offset + $length > (int) $meta['size']) {
                return ['error' => 'The file is larger than the size given when the upload started.', 'http' => 422];
            }
            $in = fopen($file, 'rb');
            $copied = $in !== false ? stream_copy_to_stream($in, $handle) : false;
            if ($in !== false) {
                fclose($in);
            }
            fflush($handle);
            if ($copied !== $length) {
                // A short write (a full disk): drop it, so the next attempt starts from a known size.
                ftruncate($handle, $received);
                return ['error' => 'Writing to the server\'s disk failed. Is it full?', 'http' => 507];
            }
            return ['received' => $received + $length, 'size' => (int) $meta['size']];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Check the complete file and move it among the backups.
     *
     * @return array<string,mixed> the backup's name and the check's warnings, or an error
     */
    public function finish(string $id, int $userId): array
    {
        $meta = $this->meta($id, $userId);
        if ($meta === null) {
            return ['error' => 'This upload is no longer on the server. Choose the file again to start over.', 'http' => 404];
        }
        $part = $this->dir() . '/' . $id . '.part';
        $received = (int) filesize($part);
        if ($received !== (int) $meta['size']) {
            return ['error' => 'The upload is not complete yet (' . fmt_bytes($received) . ' of ' . fmt_bytes((int) $meta['size']) . ').', 'received' => $received, 'http' => 409];
        }
        $result = $this->adopt($part);
        $this->discard($id, $userId);
        return isset($result['error']) ? $result + ['http' => 422] : $result;
    }

    /**
     * Check an uploaded archive and keep it among the backups. Used for whole-file uploads too.
     *
     * @return array{name?:string,warnings?:list<string>,error?:string}
     */
    public function adopt(string $file): array
    {
        $check = $this->backup->inspect($file);
        if ($check['errors'] !== []) {
            @unlink($file);
            return ['error' => 'That file cannot be used: ' . implode(' ', $check['errors'])];
        }
        Paths::ensureDir($this->backup->dir());
        // A name of our own, never the uploaded one; two finishing in the same second get the next.
        $at = time();
        do {
            $name = 'examtopics-backup-' . gmdate('Ymd-His', $at++) . '-uploaded.zip';
        } while (is_file($this->backup->dir() . '/' . $name));
        $target = $this->backup->dir() . '/' . $name;
        if (!@rename($file, $target) && !@copy($file, $target)) {
            return ['error' => 'The file could not be saved in ' . $this->backup->dir() . '.'];
        }
        @unlink($file);
        return ['name' => $name, 'warnings' => $check['warnings']];
    }

    public function discard(string $id, int $userId): bool
    {
        if ($this->meta($id, $userId) === null) {
            return false;
        }
        @unlink($this->dir() . '/' . $id . '.part');
        return @unlink($this->dir() . '/' . $id . '.json');
    }

    /**
     * This person's unfinished uploads, newest first.
     *
     * @return list<array{id:string,name:string,size:int,received:int,updated:int}>
     */
    public function pending(int $userId): array
    {
        $out = [];
        foreach (glob($this->dir() . '/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            $meta = $this->meta($id, $userId);
            $part = $this->dir() . '/' . $id . '.part';
            if ($meta !== null && is_file($part)) {
                $out[] = ['id' => $id, 'name' => (string) $meta['name'], 'size' => (int) $meta['size'],
                    'received' => (int) filesize($part), 'updated' => (int) filemtime($part)];
            }
        }
        usort($out, static fn($a, $b) => $b['updated'] <=> $a['updated']);
        return $out;
    }

    /** Delete unfinished uploads nobody has added to for $days days. */
    public function prune(int $days): int
    {
        $removed = 0;
        clearstatcache();
        foreach ([...(glob($this->dir() . '/*.json') ?: []), ...(glob($this->dir() . '/*.part') ?: [])] as $file) {
            $id = substr(basename($file), 0, 32);
            $part = $this->dir() . '/' . $id . '.part';
            $touched = is_file($part) ? (int) filemtime($part) : (int) filemtime($file);
            if ($touched < time() - $days * 86400 && @unlink($file)) {
                $removed += str_ends_with($file, '.json') ? 1 : 0;
            }
        }
        return $removed;
    }

    /**
     * The piece size: as large as PHP accepts in one request, up to MAX_CHUNK, leaving room for
     * the rest of the form.
     */
    public static function chunkSize(): int
    {
        $limits = array_filter([self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size') - 64 * 1024], static fn($n) => $n > 0);
        return max(64 * 1024, min([self::MAX_CHUNK, ...$limits]));
    }

    public static function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        };
    }

    /** @return array<string,mixed>|null the upload's details, if it exists and is this person's */
    private function meta(string $id, int $userId): ?array
    {
        if (preg_match(self::ID_RE, $id) !== 1) {
            return null;
        }
        $meta = json_decode((string) @file_get_contents($this->dir() . '/' . $id . '.json'), true);
        return is_array($meta) && (int) ($meta['user_id'] ?? 0) === $userId && isset($meta['size']) ? $meta : null;
    }

    private static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))));
        return mb_substr($name !== '' ? $name : 'backup.zip', 0, 200);
    }
}
