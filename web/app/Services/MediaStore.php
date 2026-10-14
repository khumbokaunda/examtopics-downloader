<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Paths;
use App\Core\Redactor;
use App\Repositories\MediaRepository;
use App\Services\Documents\ImageSource;

/**
 * Images that questions refer to, kept on this server. The collection tool downloads them
 * (only from ExamTopics, paced like page requests); import() checks each file again and
 * keeps it once, named by its content hash. The reader serves saved images from /media/<hash>
 * and PDFs embed them, so neither depends on ExamTopics serving images to the browser.
 */
final class MediaStore
{
    /** Kept formats and their extensions. SVG is never accepted: it can carry script. */
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    public function __construct(
        private Paths $paths,
        private MediaRepository $media,
        private Logger $log,
        private int $maxBytes,
    ) {
    }

    /**
     * Question and answer image addresses of a dataset, in order, without duplicates.
     *
     * @param array<string,mixed> $dataset
     * @return list<string>
     */
    public static function urlsOf(array $dataset): array
    {
        $urls = [];
        foreach ((array) ($dataset['questions'] ?? []) as $q) {
            $lists = [(array) ($q['question_images'] ?? []), (array) ($q['answer_images'] ?? [])];
            foreach ((array) ($q['choices'] ?? []) as $c) {
                $lists[] = is_array($c) ? (array) ($c['images'] ?? []) : [];
            }
            foreach ($lists as $list) {
                foreach ($list as $u) {
                    if (is_string($u) && $u !== '') {
                        $urls[$u] = true;
                    }
                }
            }
        }
        return array_keys($urls);
    }

    /**
     * Addresses worth downloading: never tried, or failed before. Skipped ones (not an
     * ExamTopics address, not an image, too large) are not tried again.
     *
     * @param list<string> $urls
     * @return list<string>
     */
    public function missing(array $urls): array
    {
        $known = $this->media->lookup($urls);
        return array_values(array_filter($urls, static fn(string $u) =>
            !isset($known[$u]) || $known[$u]['status'] === 'failed'));
    }

    /**
     * @param list<string> $urls
     * @return array{total:int,saved:int,failed:int,skipped:int,pending:int}
     */
    public function summary(array $urls): array
    {
        $known = $this->media->lookup($urls);
        $s = ['total' => count($urls), 'saved' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0];
        foreach ($urls as $u) {
            $status = $known[$u]['status'] ?? 'pending';
            $s[isset($s[$status]) ? $status : 'pending']++;
        }
        return $s;
    }

    /**
     * Content hashes of the saved copies, keyed by address.
     *
     * @param list<string> $urls
     * @return array<string,string>
     */
    public function savedHashes(array $urls): array
    {
        $out = [];
        foreach ($this->media->lookup($urls) as $url => $row) {
            if ($row['status'] === 'saved' && is_string($row['sha256']) && $this->file((string) $row['sha256']) !== null) {
                $out[$url] = (string) $row['sha256'];
            }
        }
        return $out;
    }

    /** @return array{path:string,mime:string,bytes:int}|null a saved image by content hash */
    public function file(string $sha256): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            return null;
        }
        $row = $this->media->findSaved($sha256);
        $ext = self::TYPES[(string) ($row['mime'] ?? '')] ?? null;
        if ($row === null || $ext === null) {
            return null;
        }
        $path = $this->paths->mediaFile($sha256, $ext);
        $rel = substr($path, strlen($this->paths->mediaRoot()) + 1);
        $real = Paths::resolveInside($this->paths->mediaRoot(), $rel);
        return $real !== null && is_file($real) ? ['path' => $real, 'mime' => (string) $row['mime'], 'bytes' => (int) filesize($real)] : null;
    }

    /**
     * Take in the tool's images document: check every saved file again (name, size, hash,
     * image type), keep it under its hash, and record each outcome. Entries for addresses
     * that were not requested are ignored.
     *
     * @param array<string,mixed> $doc the tool's images document
     * @param list<string> $requested
     * @return array{saved:int,failed:int,skipped:int}
     */
    public function import(string $dir, array $doc, array $requested): array
    {
        $wanted = array_fill_keys($requested, true);
        $counts = ['saved' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ((array) ($doc['images'] ?? []) as $item) {
            $url = is_array($item) && is_string($item['url'] ?? null) ? $item['url'] : '';
            if (!isset($wanted[$url])) {
                continue;
            }
            unset($wanted[$url]);
            $status = (string) ($item['status'] ?? '');
            $error = is_string($item['error'] ?? null) ? Redactor::clean($item['error']) : null;
            if ($status === 'saved') {
                $kept = $this->keep($dir, $item);
                if (is_array($kept)) {
                    $this->media->record($url, 'saved', $kept['sha256'], $kept['mime'], $kept['bytes'], null);
                    $counts['saved']++;
                    continue;
                }
                $status = 'skipped';
                $error = $kept;
            }
            $status = $status === 'skipped' ? 'skipped' : 'failed';
            $this->media->record($url, $status, null, null, null, $error ?? 'Not downloaded.');
            $counts[$status]++;
        }
        return $counts;
    }

    /**
     * Check and move one downloaded file into the store.
     *
     * @param array<string,mixed> $item
     * @return array{sha256:string,mime:string,bytes:int}|string the kept file, or why it was refused
     */
    private function keep(string $dir, array $item): array|string
    {
        $file = (string) ($item['file'] ?? '');
        $sha = (string) ($item['sha256'] ?? '');
        if (!preg_match('/^([0-9a-f]{64})\.(png|jpg|gif|webp)$/', $file, $m) || $m[1] !== $sha) {
            return 'The downloaded file has an unexpected name.';
        }
        $path = Paths::resolveInside($dir, $file);
        if ($path === null || !is_file($path) || is_link($dir . '/' . $file)) {
            return 'The downloaded file is missing.';
        }
        $bytes = (int) filesize($path);
        if ($bytes === 0 || $bytes > $this->maxBytes) {
            return 'The image is larger than ' . intdiv($this->maxBytes, 1048576) . ' MB.';
        }
        if (!hash_equals($sha, (string) hash_file('sha256', $path))) {
            return 'The downloaded file does not match its checksum.';
        }
        $info = @getimagesize($path);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if ((self::TYPES[$mime] ?? null) !== $m[2]) {
            return 'The file is not a PNG, JPEG, GIF or WebP image.';
        }
        $target = $this->paths->mediaFile($sha, $m[2]);
        if (!is_file($target)) {
            Paths::ensureDir(dirname($target));
            $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
            if (!copy($path, $tmp) || !rename($tmp, $target)) {
                @unlink($tmp);
                return 'The image could not be stored.';
            }
            @chmod($target, 0640);
        }
        @unlink($path);
        return ['sha256' => $sha, 'mime' => $mime, 'bytes' => $bytes];
    }

    /**
     * Saved images for one PDF, up to a total size. PNG, JPEG and GIF files are used as they
     * are; WebP is converted to a PNG in $tmpDir when GD can read it.
     *
     * @param list<string> $urls
     */
    public function imageSource(array $urls, int $maxTotal, string $tmpDir): ImageSource
    {
        $files = [];
        foreach ($this->savedHashes($urls) as $url => $sha) {
            $files[$url] = $this->file($sha);
        }
        return new class ($files, $maxTotal, $tmpDir) implements ImageSource {
            private int $used = 0;

            /** @param array<string,array{path:string,mime:string,bytes:int}|null> $files */
            public function __construct(private array $files, private int $maxTotal, private string $tmpDir)
            {
            }

            public function file(string $url): ?string
            {
                $f = $this->files[$url] ?? null;
                if ($f === null || $this->used + $f['bytes'] > $this->maxTotal) {
                    return null;
                }
                $path = $f['path'];
                if ($f['mime'] === 'image/webp') {
                    $path = $this->tmpDir . '/' . basename($f['path'], '.webp') . '.png';
                    if (!is_file($path)) {
                        $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f['path']) : false;
                        if ($img === false || !@imagepng($img, $path)) {
                            return null;
                        }
                    }
                }
                $this->used += $f['bytes'];
                return $path;
            }
        };
    }

    /**
     * Forget images that no cached version refers to any more, and delete their files when
     * no other address shares them.
     *
     * @param list<string> $referenced every image address of every cached version
     * @return array{addresses:int,files:int,bytes:int}
     */
    public function prune(array $referenced, bool $dryRun = false): array
    {
        $keep = array_fill_keys($referenced, true);
        $drop = [];
        $files = [];
        foreach ($this->media->all() as $row) {
            if (!isset($keep[$row['url']])) {
                $drop[] = (string) $row['url'];
                if (is_string($row['sha256']) && isset(self::TYPES[(string) $row['mime']])) {
                    $files[$row['sha256']] = self::TYPES[(string) $row['mime']];
                }
            }
        }
        $out = ['addresses' => count($drop), 'files' => 0, 'bytes' => 0];
        if (!$dryRun) {
            $this->media->forget($drop);
        }
        foreach ($files as $sha => $ext) {
            $path = $this->paths->mediaFile((string) $sha, $ext);
            if (!is_file($path) || (!$dryRun && $this->media->shaInUse((string) $sha))) {
                continue;
            }
            $out['files']++;
            $out['bytes'] += (int) filesize($path);
            if (!$dryRun) {
                @unlink($path);
            }
        }
        return $out;
    }

    /** @return array{files:int,bytes:int} */
    public function totals(): array
    {
        return $this->media->totals();
    }
}
