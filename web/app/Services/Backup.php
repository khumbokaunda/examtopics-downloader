<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Migrator;
use App\Core\Paths;
use RuntimeException;
use ZipArchive;

/**
 * Backups of the whole system, to keep or to move to another server. A backup is one zip:
 *
 *   manifest.json         what it holds: format, when, by whom, migrations, row counts, files
 *   data/<table>.json     every row of each table, as JSON
 *   files/<area>/...      storage areas: cache (the exam data), inventory (known question
 *                         links), branding (the logo), and optionally media (saved images)
 *                         and documents (built PDFs and Markdown)
 *
 * Restoring takes a database-only safety backup first and refuses to go on if that fails.
 * Every table is then replaced in one transaction (DELETE, not TRUNCATE, so it can roll back),
 * with foreign key checks off and explicit ids. Files are written after the commit, over what
 * is there; nothing else is deleted. Entry names are checked before anything is read, so an
 * archive cannot write outside its storage area. Afterwards every session ends (the accounts
 * may have changed), and jobs that were running when the backup was made are marked failed.
 *
 * Backups live in storage/backups, outside the web root, and hold password hashes and the
 * SMTP password: keep downloaded copies private.
 */
final class Backup
{
    public const FORMAT = 1;
    /** Installation-specific or short-lived tables: never exported, never replaced. */
    public const SKIP = ['schema_migrations', 'login_attempts', 'throttle_events', 'account_codes', 'mail_queue'];
    /** Storage areas, and whether a backup includes them unless told otherwise. */
    public const AREAS = ['cache' => true, 'inventory' => true, 'branding' => true, 'media' => true, 'documents' => true];
    /** Areas that can be left out, and the table whose rows describe their files. */
    public const OPTIONAL = ['media' => 'media', 'documents' => 'documents'];
    private const NAME_RE = '/^examtopics-backup-\d{8}-\d{6}(?:-[a-z]+(?:-[a-z]+)*)?\.zip$/';
    private const ENTRY_RE = '#^(?:manifest\.json|data/[a-z0-9_]{1,64}\.json|files/(cache|inventory|branding|media|documents)/[A-Za-z0-9._@+=-]+(?:/[A-Za-z0-9._@+=-]+)*)$#';

    public function __construct(private App $app)
    {
    }

    public function dir(): string
    {
        return $this->app->paths()->storage() . '/backups';
    }

    public static function validName(string $name): bool
    {
        return preg_match(self::NAME_RE, $name) === 1;
    }

    /** A backup file in storage/backups, or null. */
    public function path(string $name): ?string
    {
        $path = $this->dir() . '/' . $name;
        return self::validName($name) && is_file($path) ? $path : null;
    }

    /**
     * Make a backup.
     *
     * @param array{media?:bool,documents?:bool} $options
     * @return array{name:string,path:string,size:int,manifest:array<string,mixed>}
     */
    public function create(array $options, string $by, string $suffix = ''): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is missing. Install it (on Debian: apt install php-zip) and restart PHP.');
        }
        Paths::ensureDir($this->dir());
        $areas = [];
        foreach (self::AREAS as $area => $default) {
            $areas[$area] = isset(self::OPTIONAL[$area]) ? (bool) ($options[$area] ?? $default) : true;
        }
        $name = 'examtopics-backup-' . gmdate('Ymd-His') . ($suffix !== '' ? '-' . $suffix : '') . '.zip';
        $final = $this->dir() . '/' . $name;
        $part = $final . '.part';
        $zip = new ZipArchive();
        if ($zip->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write ' . $part . '. Check that the web server can write to storage/backups.');
        }
        try {
            $db = $this->app->db();
            $tables = [];
            foreach ($this->tables() as $table) {
                if (in_array($table, self::SKIP, true)) {
                    continue;
                }
                // Rows that describe files left out of this backup are left out too, so the
                // restored system does not point at files it does not have.
                $skipRows = in_array($table, array_values(self::OPTIONAL), true) && !$areas[array_search($table, self::OPTIONAL, true)];
                $rows = $skipRows ? [] : $db->all('SELECT * FROM `' . $table . '`');
                $zip->addFromString('data/' . $table . '.json', (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                $tables[$table] = count($rows);
            }
            $files = [];
            foreach ($areas as $area => $included) {
                $files[$area] = $included ? $this->addArea($zip, $area) : null;
            }
            $manifest = [
                'format_version' => self::FORMAT,
                'app' => 'Examtopics',
                'exported_at' => gmdate('c'),
                'exported_by' => $by,
                'schema_migrations' => array_column($db->all('SELECT version FROM schema_migrations ORDER BY version'), 'version'),
                'tables' => $tables,
                'files' => $files,
            ];
            $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (!$zip->close()) {
                throw new RuntimeException('Writing the backup failed: ' . $zip->getStatusString() . '. Is the disk full?');
            }
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($part);
            throw $e;
        }
        rename($part, $final);
        $size = (int) filesize($final);
        $this->app->logger()->info('app', 'Backup created', ['file' => $name, 'by' => $by, 'bytes' => $size, 'tables' => count($tables)]);
        return ['name' => $name, 'path' => $final, 'size' => $size, 'manifest' => $manifest];
    }

    /**
     * Check an archive without changing anything.
     *
     * @return array{manifest:array<string,mixed>|null,errors:list<string>,warnings:list<string>}
     */
    public function inspect(string $path): array
    {
        $errors = $warnings = [];
        $zip = new ZipArchive();
        if (!class_exists(ZipArchive::class) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            return ['manifest' => null, 'errors' => ['This is not a backup archive (it cannot be opened as a zip file).'], 'warnings' => []];
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (str_ends_with($entry, '/')) {
                    continue; // a directory entry
                }
                if (!self::safeEntry($entry)) {
                    $errors[] = 'The archive holds an entry with a name that is not allowed: ' . mb_substr($entry, 0, 120);
                    return ['manifest' => null, 'errors' => $errors, 'warnings' => []];
                }
            }
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'Examtopics') {
                return ['manifest' => null, 'errors' => ['The archive has no Examtopics manifest.'], 'warnings' => []];
            }
            if ((int) ($manifest['format_version'] ?? 0) !== self::FORMAT) {
                $errors[] = 'The archive uses backup format ' . (int) ($manifest['format_version'] ?? 0) . '; this version reads format ' . self::FORMAT . '.';
            }
            $theirs = array_map('strval', (array) ($manifest['schema_migrations'] ?? []));
            $ours = array_column($this->app->db()->all('SELECT version FROM schema_migrations'), 'version');
            $newer = array_diff($theirs, $ours);
            if ($newer !== []) {
                $errors[] = 'The backup was made by a newer version of Examtopics (it has database changes this one lacks: '
                    . implode(', ', $newer) . '). Update the code and run php bin/migrate.php first.';
            } elseif (array_diff($ours, $theirs) !== []) {
                $warnings[] = 'The backup was made by an older version. Its data is restored into the current tables; newer columns get their defaults.';
            }
            $current = $this->tables();
            foreach (array_keys((array) ($manifest['tables'] ?? [])) as $table) {
                if (!in_array($table, $current, true)) {
                    $warnings[] = 'The table "' . $table . '" no longer exists, so its rows are skipped.';
                }
            }
            $users = json_decode((string) $zip->getFromName('data/users.json'), true);
            $admins = is_array($users) ? array_filter($users, static fn($u): bool => is_array($u)
                && ($u['role'] ?? 'admin') === 'admin' && ($u['status'] ?? 'active') === 'active') : [];
            if ($admins === []) {
                $errors[] = 'The backup has no active administrator account, so nobody could sign in to manage the system after restoring it.';
            }
            return ['manifest' => $manifest, 'errors' => $errors, 'warnings' => $warnings];
        } finally {
            $zip->close();
        }
    }

    /**
     * Replace this system's data with an archive's.
     *
     * @return array{warnings:list<string>,safety:?string,files:int}
     */
    public function restore(string $path, string $by, bool $safetyNet = true): array
    {
        $check = $this->inspect($path);
        if ($check['errors'] !== []) {
            throw new RuntimeException(implode(' ', $check['errors']));
        }
        $warnings = $check['warnings'];
        $safety = null;
        if ($safetyNet) {
            try {
                $safety = $this->create(['media' => false, 'documents' => false], $by, 'before-restore')['name'];
            } catch (\Throwable $e) {
                throw new RuntimeException('The safety backup of the current data failed, so nothing was restored: ' . $e->getMessage());
            }
        }
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::RDONLY);
        try {
            $this->restoreTables($zip, $warnings);
            $files = $this->restoreFiles($zip, $warnings);
        } finally {
            $zip->close();
        }
        $this->afterRestore($warnings);
        $this->app->logger()->info('security', 'Backup restored', ['file' => basename($path), 'by' => $by, 'safety' => $safety, 'warnings' => count($warnings)]);
        $result = ['warnings' => $warnings, 'safety' => $safety, 'files' => $files];
        $this->recordResult(basename($path), $result, $by);
        return $result;
    }

    /**
     * Backups on this server, newest first.
     *
     * @return list<array{name:string,size:int,modified:int,manifest:array<string,mixed>|null}>
     */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->dir() . '/examtopics-backup-*.zip') ?: [] as $path) {
            $name = basename($path);
            if (!self::validName($name)) {
                continue;
            }
            $manifest = null;
            $zip = new ZipArchive();
            if (class_exists(ZipArchive::class) && $zip->open($path, ZipArchive::RDONLY) === true) {
                $m = json_decode((string) $zip->getFromName('manifest.json'), true);
                $manifest = is_array($m) ? $m : null;
                $zip->close();
            }
            $out[] = ['name' => $name, 'size' => (int) filesize($path), 'modified' => (int) filemtime($path), 'manifest' => $manifest];
        }
        usort($out, static fn($a, $b) => $b['modified'] <=> $a['modified'] ?: strcmp($b['name'], $a['name']));
        return $out;
    }

    /** A backup still being written (its .part file), or null. @return array{name:string,size:int}|null */
    public function inProgress(): ?array
    {
        foreach (glob($this->dir() . '/examtopics-backup-*.zip.part') ?: [] as $path) {
            if (filemtime($path) > time() - 600) {
                return ['name' => basename($path, '.part'), 'size' => (int) filesize($path)];
            }
        }
        return null;
    }

    /**
     * Keep the outcome of the last restore for the backups page.
     *
     * @param array{warnings:list<string>,safety:?string,files:int} $result
     */
    public function recordResult(string $name, array $result, string $by): void
    {
        Paths::atomicWrite($this->dir() . '/last-restore.json', (string) json_encode(
            ['file' => $name, 'at' => gmdate('Y-m-d H:i:s'), 'by' => $by] + $result, JSON_UNESCAPED_SLASHES));
    }

    /** @return array{file:string,at:string,by:string,warnings:list<string>,safety:?string,files:int}|null */
    public function lastResult(): ?array
    {
        $data = json_decode((string) @file_get_contents($this->dir() . '/last-restore.json'), true);
        return is_array($data) && isset($data['file'], $data['at']) ? $data : null;
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);
        return $path !== null && unlink($path);
    }

    /** Keep the newest $keep backups made on a schedule (named "-scheduled"), delete older ones. */
    public function prune(int $keep): int
    {
        $removed = 0;
        $scheduled = array_values(array_filter($this->list(), static fn($b) => str_contains($b['name'], '-scheduled')));
        foreach (array_slice($scheduled, max(1, $keep)) as $b) {
            $removed += $this->delete($b['name']) ? 1 : 0;
        }
        return $removed;
    }

    /** Is an entry name one we write, with no way out of its folder? */
    public static function safeEntry(string $entry): bool
    {
        if (preg_match(self::ENTRY_RE, $entry) !== 1) {
            return false;
        }
        foreach (explode('/', $entry) as $segment) {
            if ($segment === '.' || $segment === '..' || $segment === '') {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_map(static fn($r) => (string) array_values($r)[0], $this->app->db()->all('SHOW TABLES'));
    }

    /** @return array{count:int,bytes:int} */
    private function addArea(ZipArchive $zip, string $area): array
    {
        $root = $this->areaRoot($area);
        $count = $bytes = 0;
        if (!is_dir($root)) {
            return ['count' => 0, 'bytes' => 0];
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $entry = 'files/' . $area . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if (!self::safeEntry($entry) || str_ends_with($entry, '.part') || str_contains($relative, '/.tmp')) {
                continue; // temporary or oddly named files are not part of the system
            }
            $zip->addFile($file->getPathname(), $entry);
            // Images, PDFs and zips are compressed already.
            if (preg_match('/\.(png|jpe?g|gif|webp|pdf|zip|gz)$/i', $entry) === 1) {
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            }
            $count++;
            $bytes += (int) $file->getSize();
        }
        return ['count' => $count, 'bytes' => $bytes];
    }

    private function areaRoot(string $area): string
    {
        return $this->app->paths()->storage() . '/' . $area;
    }

    /** @param list<string> $warnings */
    private function restoreTables(ZipArchive $zip, array &$warnings): void
    {
        $pdo = $this->app->db()->pdo();
        $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        // Explicit ids, including a 0, go in as they are.
        $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $pdo->beginTransaction();
        try {
            foreach ($this->tables() as $table) {
                if (in_array($table, self::SKIP, true)) {
                    continue;
                }
                $pdo->exec('DELETE FROM `' . $table . '`');
                $json = $zip->getFromName('data/' . $table . '.json');
                if ($json === false) {
                    $warnings[] = 'The backup has no "' . $table . '" table (it is older), so that table is now empty.';
                    continue;
                }
                $rows = json_decode($json, true);
                if (!is_array($rows)) {
                    throw new RuntimeException('The data for "' . $table . '" is damaged.');
                }
                $this->insertRows($table, $rows, $warnings);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('Restoring the data failed and was rolled back, so nothing changed: ' . $e->getMessage(), 0, $e);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $pdo->prepare('SET SESSION sql_mode = ?')->execute([$mode]);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $warnings
     */
    private function insertRows(string $table, array $rows, array &$warnings): void
    {
        if ($rows === []) {
            return;
        }
        $columns = [];
        foreach ($this->app->db()->all(
            'SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) as $c) {
            // Generated columns are computed by the database and cannot be written.
            if (!str_contains(strtoupper((string) $c['EXTRA']), 'GENERATED')) {
                $columns[] = (string) $c['COLUMN_NAME'];
            }
        }
        $use = array_values(array_intersect(array_keys((array) $rows[0]), $columns));
        $dropped = array_diff(array_keys((array) $rows[0]), $columns);
        if ($dropped !== []) {
            $warnings[] = 'Columns of "' . $table . '" that no longer exist were skipped: ' . implode(', ', $dropped) . '.';
        }
        $quoted = implode(', ', array_map(static fn($c) => '`' . $c . '`', $use));
        foreach (array_chunk($rows, 200) as $chunk) {
            $values = [];
            $args = [];
            foreach ($chunk as $row) {
                $values[] = '(' . implode(', ', array_fill(0, count($use), '?')) . ')';
                foreach ($use as $c) {
                    $args[] = $row[$c] ?? null;
                }
            }
            $this->app->db()->run('INSERT INTO `' . $table . '` (' . $quoted . ') VALUES ' . implode(', ', $values), $args);
        }
    }

    /** @param list<string> $warnings */
    private function restoreFiles(ZipArchive $zip, array &$warnings): int
    {
        $written = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (!str_starts_with($entry, 'files/') || str_ends_with($entry, '/') || !self::safeEntry($entry)) {
                continue;
            }
            $relative = substr($entry, strlen('files/'));
            $target = $this->app->paths()->storage() . '/' . $relative;
            Paths::ensureDir(dirname($target));
            $in = $zip->getStream($entry);
            $tmp = $target . '.restore-' . bin2hex(random_bytes(3));
            $out = $in !== false ? fopen($tmp, 'wb') : false;
            if ($in === false || $out === false) {
                $warnings[] = 'Could not write ' . $relative . '.';
                continue;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            rename($tmp, $target);
            $written++;
        }
        return $written;
    }

    /** @param list<string> $warnings */
    private function afterRestore(array &$warnings): void
    {
        $db = $this->app->db();
        // Jobs that were running when the backup was made are not running here.
        $stuck = $db->run("UPDATE jobs SET status = 'failed', active_slot = NULL, finished_at = UTC_TIMESTAMP(),
                message = 'Interrupted: the system was restored from a backup' WHERE status IN ('queued','running')")->rowCount();
        if ($stuck > 0) {
            $warnings[] = $stuck . ' job(s) that were queued or running in the backup were marked failed; start them again if needed.';
        }
        // The logo the settings name must be on disk.
        $logo = (string) $db->value("SELECT value FROM settings WHERE name = 'brand.logo'");
        if ($logo !== '' && !is_file($this->app->paths()->brandingRoot() . '/' . $logo)) {
            $warnings[] = 'The branding settings name a logo file that is not on disk (' . $logo . '). Upload the logo again on Settings > Branding.';
        }
        // Accounts may have changed, so nobody stays signed in as someone else.
        foreach (glob($this->app->paths()->storage() . '/sessions/sess_*') ?: [] as $file) {
            @unlink($file);
        }
        // New pending migrations are applied by php bin/migrate.php; the data is in place.
        if ((new Migrator($db, dirname(__DIR__, 2) . '/database/migrations'))->pending() !== []) {
            $warnings[] = 'The database needs an update: run php bin/migrate.php in the web folder.';
        }
    }
}
