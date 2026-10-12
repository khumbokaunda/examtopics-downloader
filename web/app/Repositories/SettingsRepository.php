<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Non-secret runtime preferences. Secrets stay in config.local.php / environment. */
final class SettingsRepository
{
    public function __construct(private Database $db)
    {
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $v = $this->db->value('SELECT value FROM settings WHERE name = ?', [$name]);
        return $v === null ? $default : (string) $v;
    }

    public function set(string $name, string $value): void
    {
        $this->db->run(
            'INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$name, $value, Database::now()]
        );
    }
}
