<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\App;
use App\Core\Config;
use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Integration base: a real MySQL/MariaDB test database (EXAMLIB_TEST_DSN, default examlib_test),
 * rebuilt once per run and emptied before each test, plus a fresh storage directory.
 */
abstract class DbTestCase extends TestCase
{
    private static bool $schemaReady = false;
    protected App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::load();
        $storage = (string) $config->get('storage_dir');
        self::rmrf($storage);
        mkdir($storage, 0750, true);

        $this->app = App::boot($config);
        $db = $this->app->db();
        if (!self::$schemaReady) {
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($db->all('SHOW TABLES') as $row) {
                $db->pdo()->exec('DROP TABLE `' . array_values($row)[0] . '`');
            }
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
            (new Migrator($db, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
            self::$schemaReady = true;
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->all('SHOW TABLES') as $row) {
            $t = array_values($row)[0];
            if ($t !== 'schema_migrations') {
                $db->pdo()->exec('TRUNCATE TABLE `' . $t . '`');
            }
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
