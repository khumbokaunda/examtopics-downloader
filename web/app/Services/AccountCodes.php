<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Six-digit codes sent by email: "verify" proves an address (signing up, or changing it),
 * "reset" sets a new password. Only a hash is stored. A code lasts 30 minutes, allows five
 * wrong tries, and works once; sending a new one does not cancel older ones, so the newest
 * email always works. check() says which of these went wrong, because "that code is not
 * right" and "that code has expired" call for different things.
 */
final class AccountCodes
{
    public const LIFETIME = 1800;
    public const MAX_TRIES = 5;
    /** At most this many codes per account and purpose an hour, at least a minute apart. */
    public const SENDS_PER_HOUR = 5;
    public const RESEND_AFTER = 60;

    public function __construct(private Database $db, private Mailer $mailer, private Throttle $throttle, private Logger $log)
    {
    }

    /**
     * Make a code and email it.
     *
     * @param array<string,mixed> $user
     * @return string|null an error to show, or null when sent
     */
    public function send(array $user, string $purpose, string $email): ?string
    {
        $subject = $purpose . ':' . (int) $user['id'];
        $since = $this->throttle->since('code', $subject);
        if ($since !== null && $since < self::RESEND_AFTER) {
            return 'A code was sent less than a minute ago. Wait a moment, then send another if it has not arrived.';
        }
        if (!$this->throttle->hit('code', $subject, self::SENDS_PER_HOUR, 3600)) {
            return 'Too many codes were sent in the last hour. Try again later.';
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->db->run(
            'INSERT INTO account_codes (user_id, purpose, code_hash, email, attempts, expires_at, created_at) VALUES (?, ?, ?, ?, 0, ?, ?)',
            [(int) $user['id'], $purpose, self::hash($code, (int) $user['id'], $purpose), $email,
                gmdate('Y-m-d H:i:s', time() + self::LIFETIME), Database::now()]
        );
        [$subjectLine, $intro] = $purpose === 'reset'
            ? ['Your password reset code', 'Use this code to set a new password for your account.']
            : ['Your verification code', 'Use this code to confirm your email address.'];
        $fragment = '<p>' . e($intro) . '</p>'
            . '<p style="font-size:28px;font-weight:700;letter-spacing:6px;margin:16px 0">' . $code . '</p>'
            . '<p>It works once, for 30 minutes. If you did not ask for it, ignore this email.</p>';
        if (!$this->mailer->send($email, $subjectLine, $fragment, null, Access::displayName($user))) {
            return 'The email could not be sent. Try again in a few minutes; if it keeps failing, tell the administrator.';
        }
        $this->log->info('security', 'Code sent', ['username' => $user['username'], 'purpose' => $purpose]);
        return null;
    }

    /**
     * Check a code typed for this account. On success the code is used up.
     *
     * @return string "ok", "wrong", "expired", "used" or "none"
     */
    public function check(int $userId, string $purpose, string $code): string
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $rows = $this->db->all(
            'SELECT * FROM account_codes WHERE user_id = ? AND purpose = ? AND created_at > ? ORDER BY id DESC',
            [$userId, $purpose, gmdate('Y-m-d H:i:s', time() - 86400)]
        );
        if ($rows === []) {
            return 'none';
        }
        $hash = self::hash($code, $userId, $purpose);
        foreach ($rows as $row) {
            if (strlen($code) === 6 && hash_equals((string) $row['code_hash'], $hash)) {
                if ($row['used_at'] !== null || (int) $row['attempts'] >= self::MAX_TRIES) {
                    return 'used';
                }
                if (strtotime($row['expires_at'] . ' UTC') < time()) {
                    return 'expired';
                }
                $this->db->run('UPDATE account_codes SET used_at = ? WHERE id = ?', [Database::now(), (int) $row['id']]);
                return 'ok';
            }
        }
        // A wrong code counts against the newest live one, which stops working after five.
        $this->db->run('UPDATE account_codes SET attempts = attempts + 1 WHERE id = ?', [(int) $rows[0]['id']]);
        $live = array_filter($rows, static fn(array $r): bool => $r['used_at'] === null && strtotime($r['expires_at'] . ' UTC') >= time());
        if ($live === []) {
            return 'expired';
        }
        return (int) $rows[0]['attempts'] + 1 >= self::MAX_TRIES ? 'used' : 'wrong';
    }

    /** Cancel an account's unused codes (its email address changed, so they prove nothing now). */
    public function forget(int $userId, string $purpose): void
    {
        $this->db->run('UPDATE account_codes SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [Database::now(), $userId, $purpose]);
    }

    /** Remove codes older than a day (bin/cleanup.php). */
    public function prune(): int
    {
        return $this->db->run('DELETE FROM account_codes WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)])->rowCount();
    }

    /** The message for a failed check. */
    public static function message(string $result): string
    {
        return match ($result) {
            'expired' => 'That code has expired (they last 30 minutes). Send a new one.',
            'used' => 'That code can no longer be used: it was used already, or wrong codes were typed too often. Send a new one.',
            'none' => 'No code has been sent yet. Send one below.',
            default => 'That code is not right. Check the newest email we sent and type its six digits.',
        };
    }

    private static function hash(string $code, int $userId, string $purpose): string
    {
        return hash('sha256', $purpose . ':' . $userId . ':' . $code);
    }
}
