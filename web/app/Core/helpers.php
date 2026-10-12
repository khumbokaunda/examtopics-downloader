<?php

declare(strict_types=1);

// Template helpers. Loaded through Composer's "files" autoload.

use App\Core\View;

if (!function_exists('e')) {
    /** HTML-escape any value for element content or a quoted attribute. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string,string|int> $query */
    function url(string $path, array $query = []): string
    {
        return View::current()->url($path, $query);
    }

    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(View::current()->csrf()) . '">';
    }

    function csrf_token(): string
    {
        return View::current()->csrf();
    }

    /** "2026-10-12 08:30 UTC" from a UTC DATETIME, or "n/a". The caller escapes the result. */
    function fmt_time(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return 'n/a';
        }
        $t = strtotime($utc . ' UTC');
        return $t === false ? $utc : gmdate('Y-m-d H:i', $t) . ' UTC';
    }

    function fmt_ago(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return 'n/a';
        }
        $t = strtotime($utc . ' UTC');
        if ($t === false) {
            return 'n/a';
        }
        $d = time() - $t;
        return match (true) {
            $d < 60 => 'just now',
            $d < 3600 => intdiv($d, 60) . ' min ago',
            $d < 86400 => intdiv($d, 3600) . ' h ago',
            default => intdiv($d, 86400) . ' d ago',
        };
    }

    function fmt_bytes(int|string|null $bytes): string
    {
        $b = (float) $bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($b < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? (string) (int) $b : number_format($b, 1)) . ' ' . $unit;
            }
            $b /= 1024;
        }
        return '';
    }

    function fmt_duration(?string $startUtc, ?string $endUtc = null): string
    {
        if ($startUtc === null) {
            return 'n/a';
        }
        $s = strtotime($startUtc . ' UTC');
        $e = $endUtc === null ? time() : strtotime($endUtc . ' UTC');
        if ($s === false || $e === false) {
            return 'n/a';
        }
        $d = max(0, $e - $s);
        return $d >= 3600 ? sprintf('%dh %02dm', intdiv($d, 3600), intdiv($d % 3600, 60))
            : sprintf('%dm %02ds', intdiv($d, 60), $d % 60);
    }

    /** Bootstrap badge for a job/version/document status. */
    function status_badge(?string $status): string
    {
        $cls = match ($status) {
            'completed', 'complete', 'ready' => 'text-bg-success',
            'running' => 'text-bg-primary',
            'queued' => 'text-bg-secondary',
            'partial', 'partial-only' => 'text-bg-warning',
            'failed' => 'text-bg-danger',
            'cancelled' => 'text-bg-dark',
            default => 'text-bg-light border',
        };
        return '<span class="badge ' . $cls . '">' . e($status ?? 'unknown') . '</span>';
    }

    function job_label(array $job): string
    {
        return match ($job['type'] ?? '') {
            'collect' => 'Collect ' . ($job['provider_slug'] ?? '?') . ' / ' . ($job['exam_code'] ?? '?'),
            'inventory' => 'Inventory ' . ($job['provider_slug'] ?? '?'),
            'providers' => 'Discover providers',
            default => (string) ($job['type'] ?? 'job'),
        };
    }
}
