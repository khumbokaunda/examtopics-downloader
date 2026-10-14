<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * All filesystem locations, built only from validated identifiers and then checked to stay
 * inside their root. Nothing from a request is ever used as a path directly.
 */
final class Paths
{
    private string $storage;

    public function __construct(string $storageDir)
    {
        $real = realpath($storageDir);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('Storage directory does not exist: ' . $storageDir);
        }
        $this->storage = $real;
    }

    public function storage(): string
    {
        return $this->storage;
    }

    public function cacheRoot(): string
    {
        return $this->storage . '/cache';
    }

    public function documentsRoot(): string
    {
        return $this->storage . '/documents';
    }

    public function jobsRoot(): string
    {
        return $this->storage . '/jobs';
    }

    public function logsRoot(): string
    {
        return $this->storage . '/logs';
    }

    /** Logo files, outside the web root and served by BrandingController. */
    public function brandingRoot(): string
    {
        return $this->storage . '/branding';
    }

    public function mediaRoot(): string
    {
        return $this->storage . '/media';
    }

    /** A saved image: media/<first two hex digits>/<sha256>.<ext>. */
    public function mediaFile(string $sha256, string $ext): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256) || !in_array($ext, ['png', 'jpg', 'gif', 'webp'], true)) {
            throw new RuntimeException('Invalid media identifier');
        }
        return $this->mediaRoot() . '/' . substr($sha256, 0, 2) . '/' . $sha256 . '.' . $ext;
    }

    public function examCacheDir(string $provider, string $exam): string
    {
        self::assertIds($provider, $exam);
        return $this->cacheRoot() . '/' . $provider . '/' . $exam;
    }

    public function versionFile(string $provider, string $exam, string $version): string
    {
        self::assertVersion($version);
        return $this->examCacheDir($provider, $exam) . '/' . $version . '.json';
    }

    public function manifestFile(string $provider, string $exam, string $version): string
    {
        self::assertVersion($version);
        return $this->examCacheDir($provider, $exam) . '/' . $version . '.manifest.json';
    }

    public function documentDir(string $provider, string $exam, string $version): string
    {
        self::assertIds($provider, $exam);
        self::assertVersion($version);
        return $this->documentsRoot() . '/' . $provider . '/' . $exam . '/' . $version;
    }

    public function jobDir(int $jobId): string
    {
        if ($jobId <= 0) {
            throw new RuntimeException('Invalid job id');
        }
        return $this->jobsRoot() . '/' . $jobId;
    }

    public function workerLock(): string
    {
        return $this->jobsRoot() . '/worker.lock';
    }

    /**
     * Resolve a stored relative path (e.g. documents.rel_path) under $root, refusing anything
     * that escapes it: "..", absolute paths, or symlinks pointing outside.
     */
    public static function resolveInside(string $root, string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $relative)) {
            return null;
        }
        $realRoot = realpath($root);
        $real = realpath($root . '/' . $relative);
        if ($realRoot === false || $real === false) {
            return null;
        }
        if (!str_starts_with($real, $realRoot . '/')) {
            return null;
        }
        return $real;
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create directory: ' . $dir);
        }
    }

    /** Write a file atomically: temp file in the same directory, fsync, rename. */
    public static function atomicWrite(string $path, string $contents): void
    {
        self::ensureDir(dirname($path));
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $fh = fopen($tmp, 'xb');
        if ($fh === false) {
            throw new RuntimeException('Cannot create temp file for ' . basename($path));
        }
        try {
            if (fwrite($fh, $contents) !== strlen($contents)) {
                throw new RuntimeException('Short write (disk full?) for ' . basename($path));
            }
            fflush($fh);
            if (function_exists('fsync')) {
                fsync($fh);
            }
        } finally {
            fclose($fh);
        }
        chmod($tmp, 0640);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot publish ' . basename($path));
        }
    }

    private static function assertIds(string $provider, string $exam): void
    {
        if (!Ids::isProvider($provider) || !Ids::isExam($exam)) {
            throw new RuntimeException('Invalid provider or exam identifier');
        }
    }

    private static function assertVersion(string $version): void
    {
        if (!Ids::isVersion($version)) {
            throw new RuntimeException('Invalid version identifier');
        }
    }
}
