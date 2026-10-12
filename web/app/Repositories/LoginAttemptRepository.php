<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class LoginAttemptRepository
{
    public function __construct(private Database $db)
    {
    }

    public function record(string $ip, ?string $username, bool $succeeded): void
    {
        $this->db->run(
            'INSERT INTO login_attempts (ip, username, succeeded, attempted_at) VALUES (?, ?, ?, ?)',
            [$ip, $username === null ? null : mb_substr($username, 0, 64), $succeeded ? 1 : 0, Database::now()]
        );
    }

    public function recentFailures(string $ip, int $windowSeconds): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = 0 AND attempted_at > ?',
            [$ip, gmdate('Y-m-d H:i:s', time() - $windowSeconds)]
        );
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        return $this->db->all('SELECT * FROM login_attempts ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
    }
}
