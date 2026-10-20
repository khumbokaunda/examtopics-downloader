<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Two-step sign-in: after the password, a six-digit code from an authenticator app (Google
 * Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden and the like).
 *
 * Codes are RFC 6238 TOTP: HMAC-SHA1 over the 30-second step number, six digits, with one
 * step of leeway either side for a phone clock that is slightly off. A code is accepted once:
 * the step it matched is remembered, and that step and every earlier one are refused after.
 * Secrets are 20 random bytes, shown to the person in base32 (and as a QR code) while they
 * set it up, which is the only time they are shown.
 *
 * Ten recovery codes are made when it is turned on, for a lost phone. Each works once; only a
 * hash of each is stored, so they are shown once and can be downloaded then, never again.
 */
final class TwoStep
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    public const RECOVERY_CODES = 10;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I

    public function __construct(private Database $db)
    {
    }

    public function enabled(int $userId): bool
    {
        return $this->db->value('SELECT enabled_at FROM user_totp WHERE user_id = ? AND enabled_at IS NOT NULL', [$userId]) !== null;
    }

    /** @return array{enabled:bool,since:?string,pending:?string,codes_left:int} */
    public function status(int $userId): array
    {
        $row = $this->db->one('SELECT secret, enabled_at FROM user_totp WHERE user_id = ?', [$userId]);
        return [
            'enabled' => $row !== null && $row['enabled_at'] !== null,
            'since' => $row['enabled_at'] ?? null,
            'pending' => $row !== null && $row['enabled_at'] === null ? (string) $row['secret'] : null,
            'codes_left' => $this->codesLeft($userId),
        ];
    }

    /** Start setting it up: a new secret, not yet in force until confirmed with a code. */
    public function begin(int $userId): string
    {
        if ($this->enabled($userId)) {
            throw new \RuntimeException('Two-step sign-in is already on.');
        }
        $secret = self::base32Encode(random_bytes(20));
        $this->db->run('INSERT INTO user_totp (user_id, secret, enabled_at, last_used_step, created_at) VALUES (?, ?, NULL, NULL, ?)
                        ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled_at = NULL, last_used_step = NULL, created_at = VALUES(created_at)',
            [$userId, $secret, Database::now()]);
        return $secret;
    }

    public function cancel(int $userId): void
    {
        $this->db->run('DELETE FROM user_totp WHERE user_id = ? AND enabled_at IS NULL', [$userId]);
    }

    /**
     * Finish setting it up with a code from the app. Returns the recovery codes (shown once),
     * or null when the code is not right.
     *
     * @return list<string>|null
     */
    public function confirm(int $userId, string $code): ?array
    {
        $row = $this->db->one('SELECT secret FROM user_totp WHERE user_id = ? AND enabled_at IS NULL', [$userId]);
        $step = $row === null ? null : self::match((string) $row['secret'], $code, null);
        if ($step === null) {
            return null;
        }
        $this->db->run('UPDATE user_totp SET enabled_at = ?, last_used_step = ? WHERE user_id = ?', [Database::now(), $step, $userId]);
        return $this->newCodes($userId);
    }

    /**
     * Check a code typed at sign-in or to confirm it is you: one from the app (once only),
     * or a recovery code (spent). Returns which kind matched, or null.
     *
     * @return 'app'|'recovery'|'used'|null "used" is an app code that already worked once
     */
    public function check(int $userId, string $code): ?string
    {
        $row = $this->db->one('SELECT secret, last_used_step FROM user_totp WHERE user_id = ? AND enabled_at IS NOT NULL', [$userId]);
        if ($row === null) {
            return null;
        }
        $digits = (string) preg_replace('/\s+/', '', $code);
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $digits) === 1) {
            $last = $row['last_used_step'] === null ? null : (int) $row['last_used_step'];
            $step = self::match((string) $row['secret'], $digits, $last);
            if ($step !== null) {
                // Only one request can move the step forward, so a code raced twice works once.
                $moved = $this->db->run('UPDATE user_totp SET last_used_step = ? WHERE user_id = ? AND (last_used_step IS NULL OR last_used_step < ?)',
                    [$step, $userId, $step])->rowCount() === 1;
                return $moved ? 'app' : 'used';
            }
            return self::match((string) $row['secret'], $digits, null) !== null ? 'used' : null;
        }
        $raw = self::normalizeRecovery($code);
        if (strlen($raw) === 10 && $this->db->run('UPDATE totp_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [Database::now(), $userId, hash('sha256', $raw)])->rowCount() === 1) {
            return 'recovery';
        }
        return null;
    }

    /** Replace the recovery codes with ten new ones. @return list<string> */
    public function newCodes(int $userId): array
    {
        $codes = [];
        while (count($codes) < self::RECOVERY_CODES) {
            $raw = '';
            for ($i = 0; $i < 10; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[$raw] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        $this->db->run('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$userId]);
        foreach (array_keys($codes) as $raw) {
            $this->db->run('INSERT INTO totp_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)',
                [$userId, hash('sha256', (string) $raw), Database::now()]);
        }
        return array_values($codes);
    }

    public function codesLeft(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM totp_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    public function disable(int $userId): bool
    {
        $gone = $this->db->run('DELETE FROM user_totp WHERE user_id = ?', [$userId])->rowCount() > 0;
        $this->db->run('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$userId]);
        return $gone;
    }

    /** The address an authenticator app reads from the QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $issuer = trim((string) preg_replace('/\s*:\s*|\s+/', ' ', $issuer)); // a colon would end the label
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** The secret in groups of four, easier to type into an app by hand. */
    public static function grouped(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /** The code for a step (RFC 4226 HOTP over the 30-second step). */
    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** The step a code matches, one step either side of now, and later than $after. */
    public static function match(string $secret, string $code, ?int $after, ?int $now = null): ?int
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $current = intdiv($now ?? time(), self::PERIOD);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if (($after === null || $step > $after) && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper((string) preg_replace('/[\s=]+/', '', $text));
        $bits = '';
        foreach (str_split($text) as $c) {
            $i = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $c);
            if ($i !== false) {
                $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    private static function normalizeRecovery(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
