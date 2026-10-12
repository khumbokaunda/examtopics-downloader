<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Applies database/migrations/NNN_name.sql in order, recording each in schema_migrations.
 * Migrations are append-only: never edit one that has been applied; add a new file.
 */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** @return list<string> names of migrations applied by this call */
    public function migrate(): array
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version    VARCHAR(128) NOT NULL PRIMARY KEY,
                applied_at DATETIME     NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $applied = array_column($this->db->all('SELECT version FROM schema_migrations'), 'version');

        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $done = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }
            // MySQL DDL auto-commits, so a migration is applied statement by statement.
            // Keep each migration idempotent-safe to re-run after a partial failure.
            foreach (self::splitStatements((string) file_get_contents($file)) as $sql) {
                $this->db->pdo()->exec($sql);
            }
            $this->db->run(
                'INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)',
                [$version, Database::now()]
            );
            $done[] = $version;
        }
        return $done;
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        // Strip "-- comment" (whole-line and trailing) before splitting, so a semicolon inside
        // a comment can never split a statement. Migrations must not put "--" inside literals.
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            $line = preg_replace('/(^|\s)--(\s.*)?$/', '', $line) ?? $line;
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }
        $out = [];
        foreach (preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [] as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }
        return $out;
    }
}
