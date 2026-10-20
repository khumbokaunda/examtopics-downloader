<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ClientIp;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;

/**
 * Sign-in protection, in three parts:
 *
 *  - An account locks after security.lockout_attempts wrong passwords in a row (5), for
 *    security.lockout_minutes (15). A right password resets the count. The owner and everyone
 *    with security.manage are told, and an administrator can unlock it at once.
 *  - A network (one address) is refused for security.network_block_minutes (30) after
 *    security.network_max_failures (20) failed sign-ins in security.network_window seconds
 *    (900), across any names: a script trying a new name each time never reaches an account's
 *    limit. Only public addresses are blocked, never Cloudflare's, and never a shared one
 *    (three or more accounts signed in from it in 30 days), since blocking those would lock out
 *    everybody behind them. The block is recorded once, when it starts.
 *  - Every attempt is kept in login_attempts with the account it named and why it failed, which
 *    is what the Sign-in attempts page reads, including whether any attempt got in: a sign-in
 *    from a network that had just failed against that account, or had been trying names that
 *    do not exist, or of an account locked in the day before.
 */
final class SignInGuard
{
    /** Possible reasons an attempt failed, as stored. */
    public const WRONG_PASSWORD = 'wrong password';
    public const NO_ACCOUNT = 'no such account';
    public const LOCKED = 'account locked';
    public const REFUSED = 'account not active';
    public const WRONG_CODE = 'wrong two-step code';

    public function __construct(
        private Database $db,
        private Config $config,
        private Logger $log,
        private AuditLog $audit,
        /** @var \Closure(string,array<string,mixed>):void|null told when an account locks or a network is blocked */
        private ?\Closure $notify = null,
    ) {
    }

    public function lockoutAttempts(): int
    {
        return max(1, (int) $this->config->get('security.lockout_attempts', 5));
    }

    public function lockoutMinutes(): int
    {
        return max(1, (int) $this->config->get('security.lockout_minutes', 15));
    }

    /** Seconds until a blocked network may try again, or null when it is not blocked. */
    public function networkBlocked(string $ip): ?int
    {
        $until = $this->db->value('SELECT blocked_until FROM blocked_networks WHERE ip = ?', [$ip]);
        $left = $until === null ? 0 : (int) strtotime($until . ' UTC') - time();
        return $left > 0 ? $left : null;
    }

    /** Seconds until a locked account may sign in again, or null. @param array<string,mixed> $user */
    public static function lockedFor(array $user): ?int
    {
        $until = $user['locked_until'] ?? null;
        $left = $until === null ? 0 : (int) strtotime((string) $until . ' UTC') - time();
        return $left > 0 ? $left : null;
    }

    /**
     * A failed attempt. Locks the account or blocks the network when it reaches the limit.
     *
     * @param array<string,mixed>|null $user the account the name matched
     * @return bool whether this attempt locked the account
     */
    public function failed(string $ip, ?array $user, string $shown, string $reason): bool
    {
        $this->store($ip, $shown, false, $user !== null ? (int) $user['id'] : null, $reason);
        $locked = false;
        if ($user !== null && $reason === self::WRONG_PASSWORD) {
            $id = (int) $user['id'];
            $this->db->run('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?', [$id]);
            if ((int) $this->db->value('SELECT failed_logins FROM users WHERE id = ?', [$id]) >= $this->lockoutAttempts()) {
                $until = gmdate('Y-m-d H:i:s', time() + $this->lockoutMinutes() * 60);
                $this->db->run('UPDATE users SET failed_logins = 0, locked_until = ? WHERE id = ?', [$until, $id]);
                $this->log->warning('security', 'Account locked after wrong passwords', ['username' => $user['username'], 'ip' => $ip]);
                $this->audit->recordBySystem('auth.account_locked', ['username' => (string) $user['username'], 'minutes' => $this->lockoutMinutes(),
                    'attempts' => $this->lockoutAttempts(), 'ip' => $ip], 'user', (string) $user['username']);
                $this->tell('account_locked', ['user' => $user, 'minutes' => $this->lockoutMinutes(), 'ip' => $ip]);
                $locked = true;
            }
        }
        $this->maybeBlock($ip);
        return $locked;
    }

    /** A sign-in that got in: the account's count of wrong passwords starts again. @param array<string,mixed> $user */
    public function succeeded(string $ip, array $user): void
    {
        $this->store($ip, (string) $user['username'], true, (int) $user['id'], null);
        $this->db->run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [(int) $user['id']]);
    }

    /** Three or more accounts signed in from this address in 30 days: an office, a school, a phone network. */
    public function isShared(string $ip): bool
    {
        try {
            return (int) $this->db->value('SELECT COUNT(DISTINCT user_id) FROM login_attempts WHERE ip = ? AND succeeded = 1 AND attempted_at > ?',
                [$ip, gmdate('Y-m-d H:i:s', time() - 30 * 86400)]) >= 3;
        } catch (\Throwable) {
            return true; // unsure means shared: never block on a guess
        }
    }

    /** Whether an address could be blocked at all. */
    public function blockable(string $ip): bool
    {
        return ClientIp::isPublic($ip) && !ClientIp::isCloudflare($ip) && !$this->isShared($ip);
    }

    /** @param array<string,mixed> $by who unlocked it */
    public function unlock(array $account): bool
    {
        $changed = $this->db->run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ? AND (locked_until IS NOT NULL OR failed_logins > 0)',
            [(int) $account['id']])->rowCount() > 0;
        if ($changed) {
            $this->audit->record('security.account_unlocked', ['username' => (string) $account['username']], 'user', (string) $account['username']);
        }
        return $changed;
    }

    /** Lift a block, and forgive the failures that caused it so they do not block it again at once. */
    public function unblock(string $ip): bool
    {
        $gone = $this->db->run('DELETE FROM blocked_networks WHERE ip = ?', [$ip])->rowCount() > 0;
        $this->db->run('UPDATE login_attempts SET forgiven = 1 WHERE ip = ? AND succeeded = 0 AND forgiven = 0', [$ip]);
        if ($gone) {
            $this->audit->record('security.network_unblocked', ['ip' => $ip], 'network', $ip);
        }
        return $gone;
    }

    private function maybeBlock(string $ip): void
    {
        $max = max(1, (int) $this->config->get('security.network_max_failures', 20));
        $window = max(60, (int) $this->config->get('security.network_window', 900));
        $failures = (int) $this->db->value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = 0 AND forgiven = 0 AND attempted_at > ?',
            [$ip, gmdate('Y-m-d H:i:s', time() - $window)]);
        if ($failures < $max || $this->networkBlocked($ip) !== null || !$this->blockable($ip)) {
            return;
        }
        $minutes = max(1, (int) $this->config->get('security.network_block_minutes', 30));
        $this->db->run('INSERT INTO blocked_networks (ip, blocked_until, failures, created_at) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until), failures = VALUES(failures), created_at = VALUES(created_at)',
            [$ip, gmdate('Y-m-d H:i:s', time() + $minutes * 60), $failures, Database::now()]);
        $this->log->warning('security', 'Network blocked after failed sign-ins', ['ip' => $ip, 'failures' => $failures]);
        $this->audit->recordBySystem('security.network_blocked', ['ip' => $ip, 'failures' => $failures, 'minutes' => $minutes], 'network', $ip);
        $this->tell('network_blocked', ['ip' => $ip, 'failures' => $failures, 'minutes' => $minutes]);
    }

    private function store(string $ip, string $shown, bool $ok, ?int $userId, ?string $reason): void
    {
        $this->db->run('INSERT INTO login_attempts (ip, username, succeeded, attempted_at, user_id, reason) VALUES (?, ?, ?, ?, ?, ?)',
            [$ip, mb_substr($shown, 0, 64), $ok ? 1 : 0, Database::now(), $userId, $reason]);
    }

    /** @param array<string,mixed> $data */
    private function tell(string $event, array $data): void
    {
        if ($this->notify === null) {
            return;
        }
        try {
            ($this->notify)($event, $data);
        } catch (\Throwable $e) {
            $this->log->error('security', 'Sign-in notification failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    // ---- The Sign-in attempts page ----------------------------------------------------------

    /**
     * Everything the page shows for the last $seconds.
     *
     * @return array<string,mixed>
     */
    public function report(int $seconds): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - $seconds);
        $now = Database::now();
        $totals = $this->db->one(
            'SELECT SUM(succeeded = 0) AS failed, SUM(succeeded = 1) AS succeeded,
                    SUM(succeeded = 0 AND user_id IS NULL) AS unknown, COUNT(DISTINCT CASE WHEN succeeded = 0 AND user_id IS NULL THEN username END) AS names
             FROM login_attempts WHERE attempted_at > ?', [$since]) ?? [];
        $accounts = $this->db->all(
            'SELECT u.id, u.username, u.display_name, u.locked_until, u.failed_logins, COUNT(a.id) AS failures, MAX(a.attempted_at) AS last_at,
                    COUNT(DISTINCT a.ip) AS networks
             FROM users u LEFT JOIN login_attempts a ON a.user_id = u.id AND a.succeeded = 0 AND a.attempted_at > ?
             GROUP BY u.id HAVING failures > 0 OR u.locked_until > ? ORDER BY (u.locked_until > ?) DESC, failures DESC LIMIT 200',
            [$since, $now, $now]);
        $names = $this->db->all(
            'SELECT username, COUNT(*) AS attempts, MAX(attempted_at) AS last_at, COUNT(DISTINCT ip) AS networks
             FROM login_attempts WHERE succeeded = 0 AND user_id IS NULL AND attempted_at > ?
             GROUP BY username ORDER BY attempts DESC, last_at DESC LIMIT 200', [$since]);
        $networks = $this->db->all(
            'SELECT a.ip, SUM(a.succeeded = 0) AS failures, SUM(a.succeeded = 1) AS successes, COUNT(DISTINCT a.username) AS names,
                    MAX(a.attempted_at) AS last_at, b.blocked_until
             FROM login_attempts a LEFT JOIN blocked_networks b ON b.ip = a.ip AND b.blocked_until > ?
             WHERE a.attempted_at > ? GROUP BY a.ip, b.blocked_until ORDER BY failures DESC, last_at DESC LIMIT 100', [$now, $since]);
        foreach ($networks as &$n) {
            $ip = (string) $n['ip'];
            $n['kind'] = ClientIp::isCloudflare($ip) ? 'cloudflare' : (!ClientIp::isPublic($ip) ? 'private' : ($this->isShared($ip) ? 'shared' : 'public'));
        }
        unset($n);
        // A network blocked earlier whose attempts fall outside the period is still listed.
        $listed = array_column($networks, 'ip');
        foreach ($this->db->all('SELECT ip, blocked_until, failures FROM blocked_networks WHERE blocked_until > ?', [$now]) as $b) {
            if (!in_array($b['ip'], $listed, true)) {
                $networks[] = ['ip' => $b['ip'], 'failures' => $b['failures'], 'successes' => 0, 'names' => 0, 'last_at' => null,
                    'blocked_until' => $b['blocked_until'], 'kind' => 'public'];
            }
        }
        return [
            'failed' => (int) ($totals['failed'] ?? 0),
            'succeeded' => (int) ($totals['succeeded'] ?? 0),
            'unknown' => (int) ($totals['unknown'] ?? 0),
            'unknown_names' => (int) ($totals['names'] ?? 0),
            'locked' => (int) $this->db->value('SELECT COUNT(*) FROM users WHERE locked_until > ?', [$now]),
            'blocked' => (int) $this->db->value('SELECT COUNT(*) FROM blocked_networks WHERE blocked_until > ?', [$now]),
            'flagged' => $this->flagged($since),
            'accounts' => $accounts,
            'names' => $names,
            'networks' => $networks,
        ];
    }

    /**
     * Sign-ins that may not have been the account's owner: from a network that, in the day
     * before, failed 3 or more times against that account or tried 5 or more names that do
     * not exist, or of an account locked in the day before. On a shared, private or
     * Cloudflare address only the lock counts, since everyone's attempts arrive from there.
     * Each comes with whether someone cleared it ("It was them").
     *
     * @return list<array<string,mixed>>
     */
    public function flagged(string $since): array
    {
        $out = [];
        $shared = [];
        foreach ($this->db->all(
            'SELECT a.*, u.display_name, c.cleared_at, c.cleared_by FROM login_attempts a
             LEFT JOIN users u ON u.id = a.user_id LEFT JOIN sign_in_flag_clearances c ON c.attempt_id = a.id
             WHERE a.succeeded = 1 AND a.user_id IS NOT NULL AND a.attempted_at > ? ORDER BY a.id DESC LIMIT 500', [$since]) as $s) {
            $ip = (string) $s['ip'];
            $dayBefore = gmdate('Y-m-d H:i:s', (int) strtotime($s['attempted_at'] . ' UTC') - 86400);
            $against = (int) $this->db->value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND user_id = ? AND succeeded = 0 AND id < ? AND attempted_at > ?',
                [$ip, (int) $s['user_id'], (int) $s['id'], $dayBefore]);
            $unknown = (int) $this->db->value('SELECT COUNT(DISTINCT username) FROM login_attempts WHERE ip = ? AND user_id IS NULL AND succeeded = 0 AND id < ? AND attempted_at > ?',
                [$ip, (int) $s['id'], $dayBefore]);
            $wasLocked = (int) $this->db->value("SELECT COUNT(*) FROM login_attempts WHERE user_id = ? AND reason = ? AND id < ? AND attempted_at > ?",
                [(int) $s['user_id'], self::LOCKED, (int) $s['id'], $dayBefore]) > 0
                || (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.account_locked' AND subject_id = ? AND created_at > ? AND created_at <= ?",
                    [(string) $s['username'], $dayBefore, (string) $s['attempted_at']]) > 0;
            $shared[$ip] ??= !ClientIp::isPublic($ip) || ClientIp::isCloudflare($ip) || $this->isShared($ip);
            $reasons = [];
            if ($wasLocked) {
                $reasons[] = 'the account was locked after wrong passwords in the day before';
            }
            if (!$shared[$ip] && $against >= 3) {
                $reasons[] = 'this network had typed ' . $against . ' wrong passwords for it first';
            }
            if (!$shared[$ip] && $unknown >= 5) {
                $reasons[] = 'this network had tried ' . $unknown . ' names that do not exist';
            }
            if ($reasons !== []) {
                $out[] = $s + ['reasons' => $reasons];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $by */
    public function clearFlag(int $attemptId, array $by): void
    {
        $this->db->run('INSERT IGNORE INTO sign_in_flag_clearances (attempt_id, cleared_by, cleared_at) VALUES (?, ?, ?)',
            [$attemptId, (int) $by['id'], Database::now()]);
        $this->audit->record('security.flag_cleared', ['attempt' => $attemptId] + $this->attemptDetails($attemptId), 'sign_in', (string) $attemptId);
    }

    public function restoreFlag(int $attemptId): void
    {
        $this->db->run('DELETE FROM sign_in_flag_clearances WHERE attempt_id = ?', [$attemptId]);
        $this->audit->record('security.flag_restored', ['attempt' => $attemptId] + $this->attemptDetails($attemptId), 'sign_in', (string) $attemptId);
    }

    /** @return array<string,mixed> */
    private function attemptDetails(int $attemptId): array
    {
        $a = $this->db->one('SELECT username, ip, attempted_at FROM login_attempts WHERE id = ?', [$attemptId]);
        return $a === null ? [] : ['username' => (string) $a['username'], 'ip' => (string) $a['ip'], 'at' => (string) $a['attempted_at']];
    }

    /**
     * This person's own recent sign-ins and failed attempts at their account, for their account page.
     *
     * @return list<array<string,mixed>>
     */
    public function recentFor(int $userId, int $limit = 10): array
    {
        return $this->db->all('SELECT ip, succeeded, reason, attempted_at FROM login_attempts WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$userId]);
    }

    /** Delete attempts older than $days days (bin/cleanup.php). */
    public function prune(int $days): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $n = $this->db->run('DELETE FROM login_attempts WHERE attempted_at < ?', [$cutoff])->rowCount();
        $this->db->run('DELETE FROM blocked_networks WHERE blocked_until < ?', [$cutoff]);
        $this->db->run('DELETE c FROM sign_in_flag_clearances c LEFT JOIN login_attempts a ON a.id = c.attempt_id WHERE a.id IS NULL');
        return $n;
    }
}
