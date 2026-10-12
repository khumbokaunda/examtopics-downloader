<?php

declare(strict_types=1);

namespace App\Services\Documents;

/**
 * Optional, bounded image download for PDFs (off by default: documents.pdf_images).
 * Only https URLs on allow-listed hosts; per-image and total size caps; a count cap; a short
 * timeout; content must decode as an image. Results are embedded as data: URIs, so dompdf
 * itself never makes network requests (isRemoteEnabled stays false).
 */
final class ImageFetcher
{
    private int $count = 0;
    private int $total = 0;
    /** @var array<string,string|null> */
    private array $memo = [];

    /** @param list<string> $hosts */
    public function __construct(
        private array $hosts,
        private int $maxBytes,
        private int $maxCount,
        private int $maxTotal,
        private int $timeout,
    ) {
    }

    /** A data: URI, or null if the image is not allowed or unavailable. */
    public function dataUri(string $url): ?string
    {
        if (array_key_exists($url, $this->memo)) {
            return $this->memo[$url];
        }
        return $this->memo[$url] = $this->fetch($url);
    }

    private function fetch(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, $this->hosts, true)
            || $this->count >= $this->maxCount || $this->total >= $this->maxTotal) {
            return null;
        }
        $this->count++;
        $ctx = stream_context_create([
            'http' => ['timeout' => $this->timeout, 'follow_location' => 0, 'ignore_errors' => false,
                       'header' => "User-Agent: examtopics-downloader/1.0\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $fh = @fopen($url, 'rb', false, $ctx);
        if ($fh === false) {
            return null;
        }
        $data = stream_get_contents($fh, $this->maxBytes + 1);
        fclose($fh);
        if (!is_string($data) || $data === '' || strlen($data) > $this->maxBytes) {
            return null;
        }
        $info = @getimagesizefromstring($data);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return null;
        }
        $this->total += strlen($data);
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}
