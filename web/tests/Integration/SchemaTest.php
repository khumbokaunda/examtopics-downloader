<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Migrator;
use Tests\Support\DbTestCase;

final class SchemaTest extends DbTestCase
{
    public function testAllTablesExistAndMigrationsAreIdempotent(): void
    {
        $tables = array_map(static fn(array $r): string => array_values($r)[0], $this->app->db()->all('SHOW TABLES'));
        foreach (['users', 'login_attempts', 'providers', 'exams', 'jobs', 'inventory_refreshes',
                  'cache_versions', 'documents', 'settings', 'schema_migrations'] as $t) {
            self::assertContains($t, $tables);
        }
        $again = (new Migrator($this->app->db(), dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        self::assertSame([], $again, 'second run must apply nothing');
    }

    public function testActiveSlotAllowsOnlyOneActiveJobPerSlot(): void
    {
        $db = $this->app->db();
        $insert = static fn(?string $slot) => $db->run(
            "INSERT INTO jobs (type, status, active_slot, queued_at) VALUES ('collect', 'queued', ?, ?)",
            [$slot, Database::now()]
        );
        $insert('collect');
        try {
            $insert('collect');
            self::fail('a second active collect job must be rejected by the unique slot');
        } catch (\PDOException $e) {
            self::assertTrue(Database::isDuplicateKey($e));
        }
        // Finished jobs release the slot (NULL is not unique-constrained).
        $insert(null);
        $insert(null);
        self::assertSame(3, (int) $db->value('SELECT COUNT(*) FROM jobs'));
    }
}
