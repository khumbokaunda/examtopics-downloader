<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;

/**
 * The activity history: who did what, and when, kept in the database (audit_log) for the
 * Activity page. Rows are only ever added, and each carries the hash of the row before it,
 * so changing or removing one afterwards shows: verify() walks the chain and names the first
 * row that does not fit.
 *
 * Rows are written under a named lock, so two requests never chain off the same row. Inside
 * a transaction they go through a short-lived connection of their own, so the entry is kept
 * even if the work around it rolls back, and no uncommitted row is chained from. Writing the
 * history never stops the action it records: a failure is logged instead.
 *
 * Who acted: the signed-in person (set per request by the Kernel), or the account a sign-in
 * was for; without one, a web request records a visitor and anything else (the worker, cron,
 * the command line) records the system. Secrets never go in: details whose key looks like a
 * password, token, secret or code are replaced, and long values are cut short.
 */
final class AuditLog
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
    /** Detail keys never stored: passwords, secrets, tokens, hashes and one-time codes. */
    private const SECRET_KEY = '/(^|_)(password|secret|token|hash|otp)$|^code$/i';

    /** @var (\Closure():?array<string,mixed>)|null */
    private ?\Closure $user = null;
    private ?string $ip = null;

    public function __construct(private App $app)
    {
    }

    /** Called by the Kernel for each web request: who is acting, and from which address. */
    public function forRequest(\Closure $user, string $ip): void
    {
        $this->user = $user;
        $this->ip = $ip;
    }

    /**
     * Record something a person did: the signed-in person, unless $actor names the account
     * (a sign-in, a sign-up), or a visitor when nobody is signed in.
     *
     * @param array<string,mixed> $details
     * @param array<string,mixed>|null $actor
     */
    public function record(string $action, array $details = [], ?string $subjectType = null, ?string $subjectId = null, ?array $actor = null): void
    {
        $actor ??= $this->user !== null ? ($this->user)() : null;
        if ($actor !== null && isset($actor['id'])) {
            $this->write('user', (int) $actor['id'], (string) ($actor['username'] ?? ''), $action, $details, $subjectType, $subjectId);
            return;
        }
        $this->write($this->user !== null ? 'visitor' : 'system', null, '', $action, $details, $subjectType, $subjectId);
    }

    /**
     * Something the system did by itself (a collection finishing, a scheduled backup), even
     * when it happens during someone's request: it is not credited to whoever opened the page.
     *
     * @param array<string,mixed> $details
     */
    public function recordBySystem(string $action, array $details = [], ?string $subjectType = null, ?string $subjectId = null): void
    {
        $this->write('system', null, '', $action, $details, $subjectType, $subjectId);
    }

    /**
     * A finished job, as the system: a collection or exam list that arrived, or a job that
     * failed. Cancelled jobs were recorded when someone cancelled them; image downloads are
     * left out.
     *
     * @param array<string,mixed> $job the job's final row
     */
    public function recordJob(array $job): void
    {
        $provider = isset($job['provider_slug']) ? (string) $job['provider_slug'] : null;
        $exam = isset($job['exam_code']) ? (string) $job['exam_code'] : null;
        $base = array_filter(['provider' => $provider, 'exam' => $exam, 'message' => $job['message'] ?? null], static fn($v) => $v !== null && $v !== '');
        $subject = $exam !== null ? (string) $provider . '/' . $exam : $provider;
        match (true) {
            $job['status'] === 'completed' && $job['type'] === 'collect' => $this->recordBySystem('collect.completed', $base, 'exam', $subject),
            $job['status'] === 'completed' && $job['type'] === 'inventory' => $this->recordBySystem('provider.refreshed', $base, 'provider', $subject),
            $job['status'] === 'failed' => $this->recordBySystem('job.failed', $base + ['type' => (string) $job['type']], 'job', (string) ($job['uuid'] ?? '')),
            default => null,
        };
    }

    /** @param array<string,mixed> $details */
    private function write(string $kind, ?int $userId, string $actor, string $action, array $details, ?string $subjectType, ?string $subjectId): void
    {
        try {
            $db = $this->app->db();
            if ($db->pdo()->inTransaction()) {
                $db = Database::connect($this->app->config());
            }
            $locked = (int) $db->value("SELECT GET_LOCK(CONCAT(DATABASE(), '.audit_log'), 5)") === 1;
            try {
                $prev = (string) ($db->value('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1') ?? self::GENESIS);
                $row = [
                    'created_at' => Database::now(),
                    'actor_kind' => $kind,
                    'user_id' => $userId,
                    'actor' => mb_substr($actor, 0, 190),
                    'action' => mb_substr($action, 0, 64),
                    'subject_type' => $subjectType !== null ? mb_substr($subjectType, 0, 32) : null,
                    'subject_id' => $subjectId !== null ? mb_substr($subjectId, 0, 190) : null,
                    'details' => $details === [] ? null : (string) json_encode(self::clean($details), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'ip' => $this->ip !== null && $kind !== 'system' ? $this->ip : null,
                ];
                $row['prev_hash'] = $prev;
                $row['hash'] = self::hash($prev, $row);
                $db->run('INSERT INTO audit_log (created_at, actor_kind, user_id, actor, action, subject_type, subject_id, details, ip, prev_hash, hash)
                          VALUES (:created_at, :actor_kind, :user_id, :actor, :action, :subject_type, :subject_id, :details, :ip, :prev_hash, :hash)', $row);
            } finally {
                if ($locked) {
                    $db->value("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '.audit_log'))");
                }
            }
        } catch (\Throwable $e) {
            $this->app->logger()->error('security', 'Writing the activity history failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** @param array<string,mixed> $row */
    public static function hash(string $prev, array $row): string
    {
        $fields = [
            (string) $row['created_at'], (string) $row['actor_kind'],
            $row['user_id'] === null ? null : (int) $row['user_id'], (string) $row['actor'], (string) $row['action'],
            $row['subject_type'] === null ? null : (string) $row['subject_type'],
            $row['subject_id'] === null ? null : (string) $row['subject_id'],
            $row['details'] === null ? null : (string) $row['details'],
            $row['ip'] === null ? null : (string) $row['ip'],
        ];
        return hash('sha256', $prev . "\n" . json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Walk the whole chain. ok is false at the first row whose link or contents do not match.
     *
     * @return array{ok:bool,checked:int,broken_at:?int,last_hash:string}
     */
    public function verify(): array
    {
        $db = $this->app->db();
        $prev = self::GENESIS;
        $checked = 0;
        $after = 0;
        while (true) {
            $rows = $db->all('SELECT * FROM audit_log WHERE id > ? ORDER BY id LIMIT 2000', [$after]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                if ($r['prev_hash'] !== $prev || !hash_equals((string) $r['hash'], self::hash((string) $r['prev_hash'], $r))) {
                    return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $r['id'], 'last_hash' => $prev];
                }
                $prev = (string) $r['hash'];
                $after = (int) $r['id'];
                $checked++;
            }
        }
        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'last_hash' => $prev];
    }

    /** What the Activity page can filter by: groups of actions, by prefix. */
    public const GROUPS = [
        'signin' => ['label' => 'Sign-in and security', 'prefixes' => ['auth.', 'security.', 'activity.']],
        'accounts' => ['label' => 'Accounts and users', 'prefixes' => ['account.', 'user.', 'users.']],
        'library' => ['label' => 'Library and collecting', 'prefixes' => ['provider.', 'collect.', 'job.', 'exam.', 'answers.', 'document.', 'request.']],
        'backups' => ['label' => 'Backups', 'prefixes' => ['backup.']],
        'settings' => ['label' => 'Settings', 'prefixes' => ['settings.']],
    ];

    /**
     * Rows for the Activity page, newest first.
     *
     * @param array{from?:string,to?:string,who?:string,group?:string} $filters dates as Y-m-d (UTC)
     * @return array{rows:list<array<string,mixed>>,more:bool}
     */
    public function search(array $filters, int $limit = 1000): array
    {
        [$where, $args] = self::where($filters);
        $rows = $this->app->db()->all('SELECT * FROM audit_log' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY id DESC LIMIT ' . ($limit + 1), $args);
        $more = count($rows) > $limit;
        return ['rows' => array_slice($rows, 0, $limit), 'more' => $more];
    }

    /**
     * Every matching row, oldest first, a batch at a time (for the CSV download).
     *
     * @param array{from?:string,to?:string,who?:string,group?:string} $filters
     * @return \Generator<array<string,mixed>>
     */
    public function each(array $filters): \Generator
    {
        [$where, $args] = self::where($filters);
        $after = 0;
        while (true) {
            $rows = $this->app->db()->all('SELECT * FROM audit_log WHERE ' . implode(' AND ', [...$where, 'id > ?'])
                . ' ORDER BY id LIMIT 2000', [...$args, $after]);
            if ($rows === []) {
                return;
            }
            foreach ($rows as $r) {
                $after = (int) $r['id'];
                yield $r;
            }
        }
    }

    /**
     * The newest rows for the dashboard, with failed sign-ins left out (they are counted instead).
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 8): array
    {
        return $this->app->db()->all("SELECT * FROM audit_log WHERE action <> 'auth.sign_in_failed' ORDER BY id DESC LIMIT " . max(1, $limit));
    }

    public function failedSignIns(int $seconds): int
    {
        return (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.sign_in_failed' AND created_at >= ?",
            [gmdate('Y-m-d H:i:s', time() - $seconds)]);
    }

    /**
     * @param array{from?:string,to?:string,who?:string,group?:string} $filters
     * @return array{0:list<string>,1:list<mixed>}
     */
    private static function where(array $filters): array
    {
        $where = [];
        $args = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['from'] ?? '')) === 1) {
            $where[] = 'created_at >= ?';
            $args[] = $filters['from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['to'] ?? '')) === 1) {
            $where[] = 'created_at <= ?';
            $args[] = $filters['to'] . ' 23:59:59';
        }
        $who = (string) ($filters['who'] ?? '');
        if (preg_match('/^u(\d+)$/', $who, $m) === 1) {
            $where[] = 'user_id = ?';
            $args[] = (int) $m[1];
        } elseif (in_array($who, ['system', 'visitor'], true)) {
            $where[] = 'actor_kind = ?';
            $args[] = $who;
        }
        $group = self::GROUPS[(string) ($filters['group'] ?? '')] ?? null;
        if ($group !== null) {
            $where[] = '(' . implode(' OR ', array_fill(0, count($group['prefixes']), 'action LIKE ?')) . ')';
            foreach ($group['prefixes'] as $p) {
                $args[] = $p . '%';
            }
        }
        return [$where, $args];
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private static function clean(array $details): array
    {
        $out = [];
        foreach ($details as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEY, $k) === 1) {
                continue;
            }
            $out[$k] = match (true) {
                is_array($v) => self::clean($v),
                is_string($v) => mb_substr($v, 0, 300),
                is_scalar($v), $v === null => $v,
                default => null,
            };
        }
        return $out;
    }
}
