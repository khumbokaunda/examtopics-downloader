<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE username = ?', [$username]);
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ?', [self::normalizeEmail($email)]);
    }

    /** A username or an email address, as typed on the sign-in page. @return array<string,mixed>|null */
    public function findByLogin(string $login): ?array
    {
        return str_contains($login, '@') ? $this->findByEmail($login) : $this->findByUsername($login);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * @param array{display_name?:?string,email?:?string,status?:string,email_verified?:bool,permissions?:list<string>} $fields
     */
    public function create(string $username, string $password, string $role = 'admin', array $fields = []): int
    {
        $email = isset($fields['email']) && $fields['email'] !== '' ? self::normalizeEmail((string) $fields['email']) : null;
        $this->db->run(
            'INSERT INTO users (username, display_name, email, email_verified_at, role, permissions, status, password_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $username, self::blankToNull($fields['display_name'] ?? null), $email,
                ($fields['email_verified'] ?? false) && $email !== null ? Database::now() : null,
                $role, json_encode($fields['permissions'] ?? []), $fields['status'] ?? 'active',
                password_hash($password, PASSWORD_DEFAULT), Database::now(),
            ]
        );
        return $this->db->lastId();
    }

    /** @return list<array<string,mixed>> every account, administrators first */
    public function all(): array
    {
        return $this->db->all("SELECT * FROM users ORDER BY role = 'admin' DESC, username");
    }

    /**
     * Change account fields. A changed email address needs verifying again.
     *
     * @param array{display_name?:?string,email?:?string,role?:string,status?:string,permissions?:list<string>} $fields
     */
    public function update(int $id, array $fields): void
    {
        $user = $this->find($id);
        if ($user === null) {
            return;
        }
        $sets = [];
        $args = [];
        if (array_key_exists('display_name', $fields)) {
            $sets[] = 'display_name = ?';
            $args[] = self::blankToNull($fields['display_name']);
        }
        if (array_key_exists('email', $fields)) {
            $email = $fields['email'] !== null && $fields['email'] !== '' ? self::normalizeEmail($fields['email']) : null;
            if ($email !== $user['email']) {
                $sets[] = 'email = ?';
                $args[] = $email;
                $sets[] = 'email_verified_at = NULL';
            }
        }
        foreach (['role', 'status'] as $key) {
            if (isset($fields[$key])) {
                $sets[] = $key . ' = ?';
                $args[] = $fields[$key];
            }
        }
        if (isset($fields['permissions'])) {
            $sets[] = 'permissions = ?';
            $args[] = json_encode(array_values($fields['permissions']));
        }
        if ($sets !== []) {
            $args[] = $id;
            $this->db->run('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);
        }
    }

    public function markEmailVerified(int $id): void
    {
        $this->db->run('UPDATE users SET email_verified_at = ? WHERE id = ?', [Database::now(), $id]);
    }

    /** Is this address used by an account other than $exceptId? */
    public function emailTaken(string $email, int $exceptId = 0): bool
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [self::normalizeEmail($email), $exceptId]) > 0;
    }

    public function activeAdminCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM users WHERE id = ?', [$id]);
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function validEmail(string $email): bool
    {
        return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validUsername(string $username): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) === 1;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === null || $value === '' ? null : mb_substr($value, 0, 128);
    }

    public function setPassword(int $id, string $password): void
    {
        $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public function rehashIfNeeded(int $id, string $password, string $hash): void
    {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->setPassword($id, $password);
        }
    }

    /**
     * Merge preferences into the stored ones (appearance and timezone are saved separately and
     * must not erase each other).
     *
     * @param array<string,string> $preferences
     */
    public function setPreferences(int $id, array $preferences): void
    {
        $stored = json_decode((string) $this->db->value('SELECT preferences FROM users WHERE id = ?', [$id]), true);
        $merged = array_merge(is_array($stored) ? $stored : [], $preferences);
        $this->db->run('UPDATE users SET preferences = ? WHERE id = ?', [json_encode($merged, JSON_UNESCAPED_SLASHES), $id]);
    }

    public function hasColumn(string $column): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', $column]
        ) > 0;
    }

    public function touchLogin(int $id): void
    {
        $this->db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [Database::now(), $id]);
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users');
    }
}
