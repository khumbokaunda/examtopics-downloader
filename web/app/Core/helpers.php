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

    /**
     * "2026-10-12 10:30 CAT" from a UTC DATETIME, in the reader's timezone (see Clock), or
     * "n/a". The caller escapes the result.
     */
    function fmt_time(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return 'n/a';
        }
        return \App\Core\Clock::format($utc) ?? $utc;
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

    /**
     * URL of a file under public/, versioned by its modification time so a deploy that only
     * changes CSS or JavaScript is never served stale from the browser cache.
     */
    function asset(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/public' . $path;
        $mtime = is_file($file) ? (int) filemtime($file) : 0;
        return url($path, ['v' => base_convert((string) $mtime, 10, 36)]);
    }

    /** A Bootstrap Icons glyph, hidden from assistive technology (always beside a label). */
    function icon(string $name, string $extra = ''): string
    {
        return '<i class="bi bi-' . e($name) . ($extra !== '' ? ' ' . e($extra) : '') . '" aria-hidden="true"></i>';
    }

    /** A status chip. $tone: neutral, primary, success, warning, danger or info. */
    function chip(string $text, string $tone = 'neutral', ?string $iconName = null): string
    {
        $tone = in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
        return '<span class="chip chip--' . $tone . '">' . ($iconName !== null ? icon($iconName) : '') . e($text) . '</span>';
    }

    /** @return array{0:string,1:string} the tone and icon for a job, version or document status */
    function status_style(?string $status): array
    {
        return match ($status) {
            'completed', 'complete', 'ready', 'ok' => ['success', 'check2-circle'],
            'running' => ['primary', 'arrow-repeat'],
            'queued' => ['neutral', 'hourglass-split'],
            'partial', 'partial-only' => ['warning', 'exclamation-triangle'],
            'failed' => ['danger', 'x-circle'],
            'cancelled' => ['neutral', 'slash-circle'],
            default => ['neutral', 'dot'],
        };
    }

    /** The status chip for a job, version or document. */
    function status_badge(?string $status): string
    {
        [$tone, $iconName] = status_style($status);
        $label = $status === 'partial-only' ? 'partial' : (string) ($status ?? 'unknown');
        return '<span class="chip chip--' . $tone . '">' . icon($iconName, $status === 'running' ? 'spin' : '')
            . e($label) . '</span>';
    }

    /**
     * An empty state: an icon, a title and one line saying what to do next. $body is HTML the
     * caller has already escaped (it may hold a link); $action is [label, href] for a button.
     *
     * @param array{0:string,1:string}|null $action
     */
    function empty_state(string $iconName, string $title, string $body = '', ?array $action = null, bool $compact = false): string
    {
        return '<div class="empty-state' . ($compact ? ' is-compact' : '') . '">' . icon($iconName, 'empty-icon')
            . '<p class="empty-title">' . e($title) . '</p>'
            . ($body !== '' ? '<p class="empty-body">' . $body . '</p>' : '')
            . ($action !== null ? '<a class="btn btn-primary btn-sm" href="' . e($action[1]) . '">' . e($action[0]) . '</a>' : '')
            . '</div>';
    }

    /** Initials for an avatar: up to two letters from a username. */
    function initials(string $name): string
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];
        $letters = count($parts) > 1 ? mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1) : mb_substr($parts[0], 0, 2);
        return mb_strtoupper($letters);
    }

    /**
     * A provider's display name: its stored name without an "(N exams)" suffix that older
     * discoveries kept, else its identifier.
     *
     * @param array<string,mixed> $p a providers row (or any row with name and slug keys)
     */
    function provider_name(array $p, string $nameKey = 'name', string $slugKey = 'slug'): string
    {
        $name = \App\Repositories\ProviderRepository::splitName((string) ($p[$nameKey] ?? ''))[0];
        return $name !== '' ? $name : (string) ($p[$slugKey] ?? '');
    }

    /**
     * A one-line, plain-words summary of a stored collection error (one error per line):
     * "Listing page 20: ExamTopics asked us to slow down (HTTP 429), retries ran out (+1 more)".
     * The full text stays available next to it.
     */
    function error_summary(string $error): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($error))), static fn($l) => $l !== ''));
        if ($lines === []) {
            return '';
        }
        $first = $lines[0];
        $where = preg_match('/^(listing page \d+|question page)\b/i', $first, $m) === 1 ? ucfirst(strtolower($m[1])) : '';
        $code = preg_match('/(?:HTTP|status code) (\d{3})/', $first, $c) === 1 ? (int) $c[1] : null;
        $reason = match (true) {
            $code === 429 => 'ExamTopics asked us to slow down (HTTP 429)',
            $code === 403 => 'ExamTopics refused access (HTTP 403)',
            $code === 404 => 'page not found (HTTP 404)',
            $code !== null && $code >= 500 => "ExamTopics had a server error (HTTP {$code})",
            $code !== null => "unexpected response (HTTP {$code})",
            (bool) preg_match('/timeout|deadline exceeded/i', $first) => 'the request timed out',
            (bool) preg_match('/no such host|connection refused|connection reset|EOF/i', $first) => 'the connection failed',
            default => '',
        };
        if ($reason !== '' && preg_match('/gave up after|exhausted retries/i', $first) === 1) {
            $reason .= ', retries ran out';
        }
        $summary = $reason !== '' ? ($where !== '' ? $where . ': ' . $reason : ucfirst($reason))
            : (mb_strlen($first) > 140 ? mb_substr($first, 0, 139) . '…' : $first);
        $more = count($lines) - 1;
        return $summary . ($more > 0 ? " (+{$more} more)" : '');
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
