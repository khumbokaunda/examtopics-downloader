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
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function create(string $username, string $password): int
    {
        $this->db->run(
            'INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), Database::now()]
        );
        return $this->db->lastId();
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

    /** @param array<string,string> $preferences */
    public function setPreferences(int $id, array $preferences): void
    {
        $this->db->run('UPDATE users SET preferences = ? WHERE id = ?', [json_encode($preferences, JSON_UNESCAPED_SLASHES), $id]);
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
