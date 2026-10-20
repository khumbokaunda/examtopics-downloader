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
     * With $track, progress is written to status.json as it goes, for the Backups page.
     *
     * @param array{media?:bool,documents?:bool} $options
     * @return array{name:string,path:string,size:int,manifest:array<string,mixed>}
     */
    public function create(array $options, string $by, string $suffix = '', bool $track = false): array
    {
        $status = $track ? fn(array $s) => $this->writeStatus($s + ['kind' => 'backup']) : static fn(array $s) => null;
        Paths::ensureDir($this->dir());
        $started = time();
        try {
            $result = $this->build($options, $by, $suffix, $status, $started);
        } catch (\Throwable $e) {
            $status(['status' => 'failed', 'phase' => 'failed', 'message' => 'The backup failed: ' . $e->getMessage(), 'started_at' => $started, 'finished_at' => time()]);
            throw $e;
        }
        $status(['status' => 'completed', 'phase' => 'done', 'percent' => 100, 'name' => $result['name'],
            'message' => 'Backup ready: ' . $result['name'] . ' (' . self::bytes($result['size']) . ').', 'started_at' => $started, 'finished_at' => time()]);
        if ($suffix !== 'before-restore') { // the restore's own entry names its safety backup
            $this->audit('backup.created', ['name' => $result['name'], 'size' => $result['size'], 'images' => (bool) ($options['media'] ?? true),
                'documents' => (bool) ($options['documents'] ?? true), 'scheduled' => $suffix === 'scheduled'], $result['name'], $by);
        }
        return $result;
    }

    /**
     * @param array{media?:bool,documents?:bool} $options
     * @param callable(array<string,mixed>):void $status
     * @return array{name:string,path:string,size:int,manifest:array<string,mixed>}
     */
    private function build(array $options, string $by, string $suffix, callable $status, int $started): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is missing. Install it (on Debian: apt install php-zip) and restart PHP.');
        }
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
        // Progress: the database is the first 5%, listing files the next 5%, and writing the
        // archive (compressing and copying every file) the rest.
        $report = static function (string $phase, int $percent, string $message, ?int $done = null, ?int $total = null) use ($status, $name, $started): void {
            $status(['status' => 'running', 'phase' => $phase, 'percent' => $percent, 'message' => $message,
                'done' => $done, 'total' => $total, 'name' => $name, 'started_at' => $started, 'pid' => getmypid()]);
        };
        try {
            $db = $this->app->db();
            $tables = [];
            $all = array_values(array_diff($this->tables(), self::SKIP));
            foreach ($all as $i => $table) {
                $report('reading the database', (int) floor(5 * $i / max(1, count($all))), 'Reading table ' . $table, $i, count($all));
                // Rows that describe files left out of this backup are left out too, so the
                // restored system does not point at files it does not have.
                $skipRows = in_array($table, array_values(self::OPTIONAL), true) && !$areas[array_search($table, self::OPTIONAL, true)];
                $rows = $skipRows ? [] : $db->all('SELECT * FROM `' . $table . '`');
                $zip->addFromString('data/' . $table . '.json', (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                $tables[$table] = count($rows);
            }
            $files = [];
            foreach ($areas as $area => $included) {
                $report('listing files', 5, 'Listing ' . $area . ' files');
                $files[$area] = $included ? $this->addArea($zip, $area) : null;
            }
            $count = array_sum(array_map(static fn($f) => is_array($f) ? $f['count'] : 0, $files));
            $bytes = array_sum(array_map(static fn($f) => is_array($f) ? $f['bytes'] : 0, $files));
            $what = number_format($count) . ' files (' . self::bytes($bytes) . ')';
            $report('writing the archive', 10, 'Packing ' . $what);
            $last = -1;
            if (method_exists($zip, 'registerProgressCallback')) {
                // Called by the zip library while it writes; the page is updated at most once a percent.
                $zip->registerProgressCallback(0.01, static function (float $rate) use ($report, $what, &$last): void {
                    $percent = 10 + (int) floor(89 * $rate);
                    if ($percent !== $last) {
                        $last = $percent;
                        $report('writing the archive', $percent, 'Packing ' . $what . ': ' . (int) floor($rate * 100) . '% written');
                    }
                });
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
     * With $track, progress is written to status.json as it goes, for the Backups page: the
     * check and the safety backup are the first 10%, the database the next 30%, and writing
     * the files the rest.
     *
     * @return array{warnings:list<string>,safety:?string,files:int}
     */
    public function restore(string $path, string $by, bool $safetyNet = true, bool $track = false): array
    {
        $started = time();
        $name = basename($path);
        $report = $track
            ? fn(string $phase, int $percent, string $message, ?int $done = null, ?int $total = null) => $this->writeStatus(['kind' => 'restore',
                'status' => 'running', 'phase' => $phase, 'percent' => $percent, 'message' => $message, 'done' => $done, 'total' => $total,
                'name' => $name, 'started_at' => $started, 'pid' => getmypid()])
            : static fn(string $phase, int $percent, string $message, ?int $done = null, ?int $total = null) => null;
        try {
            $report('checking the archive', 0, 'Checking ' . $name);
            $check = $this->inspect($path);
            if ($check['errors'] !== []) {
                throw new RuntimeException(implode(' ', $check['errors']));
            }
            $warnings = $check['warnings'];
            $safety = null;
            if ($safetyNet) {
                $report('saving the current data', 2, 'Making a safety backup of the current data first');
                try {
                    $safety = $this->create(['media' => false, 'documents' => false], $by, 'before-restore')['name'];
                } catch (\Throwable $e) {
                    throw new RuntimeException('The safety backup of the current data failed, so nothing was restored: ' . $e->getMessage());
                }
            }
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::RDONLY);
            try {
                $this->restoreTables($zip, $warnings, $report);
                // The accounts are the backup's from here on, so every earlier sign-in ends now,
                // not only when the files are written.
                Paths::atomicWrite($this->dir() . '/restored-at', (string) time());
                $files = $this->restoreFiles($zip, $warnings, $report);
            } finally {
                $zip->close();
            }
            $this->afterRestore($warnings);
        } catch (\Throwable $e) {
            $this->audit('backup.restore_failed', ['name' => $name, 'error' => mb_substr($e->getMessage(), 0, 300)], $name, $by);
            if ($track) {
                $this->writeStatus(['kind' => 'restore', 'status' => 'failed', 'phase' => 'failed', 'name' => $name, 'started_at' => $started, 'finished_at' => time(),
                    'message' => 'The restore failed: ' . $e->getMessage()]);
            }
            throw $e;
        }
        $this->app->logger()->info('security', 'Backup restored', ['file' => $name, 'by' => $by, 'safety' => $safety, 'warnings' => count($warnings)]);
        $result = ['warnings' => $warnings, 'safety' => $safety, 'files' => $files];
        $this->recordResult($name, $result, $by);
        $this->audit('backup.restored', ['name' => $name, 'safety' => $safety, 'files' => $files, 'warnings' => count($warnings)], $name, $by);
        if ($track) {
            $this->writeStatus(['kind' => 'restore', 'status' => 'completed', 'phase' => 'done', 'percent' => 100, 'name' => $name,
                'message' => 'Restored ' . $name . ': ' . number_format($files) . ' files written.', 'started_at' => $started, 'finished_at' => time()]);
        }
        return $result;
    }

    /**
     * When the last restore replaced the accounts (0 if never). Sign-ins from before then are
     * over, even one whose request was running during the restore and wrote its session file
     * back after the restore removed it.
     */
    public function restoredAt(): int
    {
        $at = (int) @filemtime($this->dir() . '/restored-at');
        return $at > time() ? 0 : $at;
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

    /**
     * The progress of the backup being made, or the outcome of the last one, shaped like a
     * job's status so the page can show it with the same progress bar. A "running" status
     * whose process is gone (a restart, a full disk) is reported as failed.
     *
     * @return array<string,mixed>|null
     */
    public function status(): ?array
    {
        $s = json_decode((string) @file_get_contents($this->dir() . '/status.json'), true);
        if (!is_array($s) || !isset($s['status'])) {
            return null;
        }
        $updated = (int) ($s['updated_at'] ?? 0);
        $pid = (int) ($s['pid'] ?? 0);
        // posix_kill(pid, 0) only asks whether the process exists; EPERM (1) means it does, but
        // belongs to another user (a backup started as root from the command line).
        $alive = $pid > 0 && function_exists('posix_kill')
            ? (@posix_kill($pid, 0) || posix_get_last_error() === 1)
            : $updated > time() - 120;
        $kind = ($s['kind'] ?? 'backup') === 'restore' ? 'restore' : 'backup';
        if ($s['status'] === 'running' && (($pid > 0 && !$alive) || ($pid === 0 && $updated < time() - 60))) {
            $s = ['status' => 'failed', 'phase' => 'failed', 'finished_at' => $updated,
                'message' => $kind === 'restore'
                    ? 'The restore stopped before it finished (the server may have restarted). See storage/logs/backup.log. The data from before it is kept as a backup named ...-before-restore.zip.'
                    : 'The backup stopped before it finished (the server may have restarted, or the disk is full). See storage/logs/backup.log, then try again.'] + $s;
        }
        $final = $s['status'] !== 'running';
        return [
            'kind' => $kind,
            'status' => (string) $s['status'],
            'final' => $final,
            'phase' => (string) ($s['phase'] ?? ''),
            'percent' => isset($s['percent']) ? (int) $s['percent'] : null,
            'done' => isset($s['done']) ? (int) $s['done'] : null,
            'total' => isset($s['total']) ? (int) $s['total'] : null,
            'message' => (string) ($s['message'] ?? ''),
            'name' => isset($s['name']) ? (string) $s['name'] : null,
            'elapsed_seconds' => isset($s['started_at']) ? (int) ($final ? ($s['finished_at'] ?? $updated) : time()) - (int) $s['started_at'] : null,
            'finished_at' => isset($s['finished_at']) ? (int) $s['finished_at'] : null,
        ];
    }

    /**
     * Stop the backup being made and delete its unfinished file. The zip library writes to
     * temporary files next to the final name, so every leftover of that name goes.
     */
    public function cancel(): bool
    {
        $s = json_decode((string) @file_get_contents($this->dir() . '/status.json'), true);
        // A restore is not stopped half way: its files would be part old, part new.
        if (!is_array($s) || ($s['status'] ?? '') !== 'running' || ($s['kind'] ?? 'backup') === 'restore') {
            return false;
        }
        $pid = (int) ($s['pid'] ?? 0);
        if ($pid > 0 && function_exists('posix_kill')) {
            @posix_kill($pid, 15); // SIGTERM
        }
        $name = (string) ($s['name'] ?? '');
        foreach (glob($this->dir() . '/examtopics-backup-*.zip.part*') ?: [] as $left) {
            if ($name === '' || str_starts_with(basename($left), $name)) {
                @unlink($left);
            }
        }
        $this->writeStatus(['kind' => 'backup', 'status' => 'cancelled', 'phase' => 'cancelled', 'message' => 'The backup was cancelled and its unfinished file deleted.',
            'started_at' => $s['started_at'] ?? time(), 'finished_at' => time()]);
        return true;
    }

    /** Record that the page started a backup or a restore, before the background process has begun. */
    public function markStarting(string $kind = 'backup', ?string $name = null): void
    {
        $this->writeStatus(['kind' => $kind, 'status' => 'running', 'phase' => 'starting', 'percent' => 0, 'message' => 'Starting…', 'name' => $name, 'started_at' => time()]);
    }

    /** @param array<string,mixed> $status */
    public function writeStatus(array $status): void
    {
        Paths::ensureDir($this->dir());
        Paths::atomicWrite($this->dir() . '/status.json', (string) json_encode($status + ['updated_at' => time()], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Record a backup event in the activity history, credited to the named account when it
     * exists (the page passes its user to the background process as --by), else the system.
     *
     * @param array<string,mixed> $details
     */
    private function audit(string $action, array $details, string $name, string $by): void
    {
        $audit = $this->app->service(AuditLog::class, fn() => new AuditLog($this->app));
        try {
            $user = $this->app->db()->one('SELECT id, username FROM users WHERE username = ?', [$by]);
        } catch (\Throwable) {
            $user = null;
        }
        if ($user !== null) {
            $audit->record($action, $details, 'backup', $name, $user);
        } else {
            $audit->recordBySystem($action, $details + ['by' => $by], 'backup', $name);
        }
    }

    private static function bytes(int $n): string
    {
        return function_exists('fmt_bytes') ? fmt_bytes($n) : $n . ' bytes';
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

    /**
     * @param list<string> $warnings
     * @param callable(string,int,string,?int,?int):void $report
     */
    private function restoreTables(ZipArchive $zip, array &$warnings, callable $report): void
    {
        $pdo = $this->app->db()->pdo();
        $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        // Explicit ids, including a 0, go in as they are.
        $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $pdo->beginTransaction();
        try {
            $tables = array_values(array_diff($this->tables(), self::SKIP));
            foreach ($tables as $i => $table) {
                $report('restoring the database', 10 + (int) floor(30 * $i / max(1, count($tables))), 'Restoring table ' . $table, $i, count($tables));
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

    /**
     * @param list<string> $warnings
     * @param callable(string,int,string,?int,?int):void $report
     */
    private function restoreFiles(ZipArchive $zip, array &$warnings, callable $report): int
    {
        $written = 0;
        $last = -1;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            // The page is updated at most once a percent.
            $percent = 40 + (int) floor(59 * $i / max(1, $zip->numFiles));
            if ($percent !== $last) {
                $last = $percent;
                $report('writing files', $percent, 'Writing the exam data, images and documents', $i, $zip->numFiles);
            }
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
        // Accounts may have changed, so nobody stays signed in as someone else: sign-ins from
        // before the accounts were replaced already end (restoredAt()), and their session files
        // go. Sessions used since then stay: someone sent to the sign-in form part way through
        // a long restore holds the form's token there, and deleting it would refuse the form.
        $replaced = $this->restoredAt() ?: time();
        clearstatcache();
        foreach (glob($this->app->paths()->storage() . '/sessions/sess_*') ?: [] as $file) {
            if ((int) @filemtime($file) < $replaced) {
                @unlink($file);
            }
        }
        // New pending migrations are applied by php bin/migrate.php; the data is in place.
        if ((new Migrator($db, dirname(__DIR__, 2) . '/database/migrations'))->pending() !== []) {
            $warnings[] = 'The database needs an update: run php bin/migrate.php in the web folder.';
        }
    }
}
