#!/usr/bin/env php
<?php

declare(strict_types=1);

// Backups from the command line: for large archives, for cron, and for restoring onto a new
// server before anyone can sign in.
//   php bin/backup.php create [--no-media] [--no-documents] [--scheduled] [--keep=N]
//   php bin/backup.php list
//   php bin/backup.php restore <file.zip> [--yes] [--track]
// Archives are written to storage/backups. restore takes any path (a file copied from the
// old server, for example); it asks for confirmation unless --yes is given. --track writes the
// restore's progress for the Backups page, which starts restores this way in the background.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Migrator;
use App\Services\Backup;
use App\Services\Services;

$args = array_slice($argv, 1);
$command = (string) ($args[0] ?? '');
$flags = array_filter($args, static fn($a) => str_starts_with($a, '--'));
$has = static fn(string $f): bool => in_array($f, $flags, true);

$by = (string) (getenv('USER') ?: 'cli');
foreach ($flags as $f) {
    if (preg_match('/^--by=([A-Za-z0-9._@-]{1,64})$/', $f, $m) === 1) {
        $by = $m[1];
    }
}

$app = App::boot();
$backup = (new Services($app))->backup();
$lock = fopen($backup->dir() . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if ($command === 'restore' && $has('--track')) {
        $backup->writeStatus(['kind' => 'restore', 'status' => 'failed', 'phase' => 'failed', 'started_at' => time(), 'finished_at' => time(),
            'message' => 'Another backup or restore was running, so nothing was restored. Try again when it has finished.']);
    }
    fwrite(STDERR, "Another backup or restore is running. Try again when it has finished.\n");
    exit(1);
}

try {
    switch ($command) {
        case 'create':
            $suffix = $has('--scheduled') ? 'scheduled' : '';
            $b = $backup->create(['media' => !$has('--no-media'), 'documents' => !$has('--no-documents')], $by, $suffix, true);
            echo 'Created ' . $b['path'] . ' (' . fmt_bytes($b['size']) . ")\n";
            foreach ($flags as $f) {
                if (preg_match('/^--keep=(\d+)$/', $f, $m) === 1) {
                    $removed = $backup->prune((int) $m[1]);
                    echo "Kept the newest {$m[1]} scheduled backups; removed {$removed}.\n";
                }
            }
            break;

        case 'list':
            foreach ($backup->list() as $b) {
                echo str_pad($b['name'], 52) . str_pad(fmt_bytes($b['size']), 12) . ($b['manifest']['exported_by'] ?? '') . "\n";
            }
            break;

        case 'restore':
            $file = (string) ($args[1] ?? '');
            if ($file === '' || str_starts_with($file, '--') || !is_file($file)) {
                fwrite(STDERR, "usage: php bin/backup.php restore <file.zip> [--yes] [--track]\n");
                exit(64);
            }
            // A new server: create the tables first, so there is something to restore into.
            (new Migrator($app->db(), dirname(__DIR__) . '/database/migrations'))->migrate();
            $check = $backup->inspect($file);
            if ($check['errors'] !== []) {
                if ($has('--track')) {
                    $backup->writeStatus(['kind' => 'restore', 'status' => 'failed', 'phase' => 'failed', 'started_at' => time(), 'finished_at' => time(),
                        'message' => 'The archive cannot be restored, so nothing changed: ' . implode(' ', $check['errors'])]);
                }
                fwrite(STDERR, "Cannot restore this archive:\n- " . implode("\n- ", $check['errors']) . "\n");
                exit(1);
            }
            $m = $check['manifest'];
            echo 'Backup made ' . ($m['exported_at'] ?? '?') . ' by ' . ($m['exported_by'] ?? '?') . ': '
                . array_sum((array) ($m['tables'] ?? [])) . " rows.\n";
            foreach ($check['warnings'] as $w) {
                echo "Note: {$w}\n";
            }
            if (!$has('--yes')) {
                echo 'This replaces every account, setting and exam record on this server. Type RESTORE to continue: ';
                if (trim((string) fgets(STDIN)) !== 'RESTORE') {
                    echo "Nothing was changed.\n";
                    exit(1);
                }
            }
            $result = $backup->restore($file, $by, true, $has('--track'));
            echo 'Restored. ' . $result['files'] . " files written.\n";
            if ($result['safety'] !== null) {
                echo 'The data from before is kept in storage/backups/' . $result['safety'] . "\n";
            }
            foreach ($result['warnings'] as $w) {
                echo "Note: {$w}\n";
            }
            break;

        default:
            fwrite(STDERR, "usage: php bin/backup.php create [--no-media] [--no-documents] [--scheduled] [--keep=N]\n"
                . "       php bin/backup.php list\n"
                . "       php bin/backup.php restore <file.zip> [--yes] [--track]\n");
            exit(64);
    }
} catch (Throwable $e) {
    if ($command === 'restore' && $has('--track') && ($backup->status()['status'] ?? '') === 'running') {
        $backup->writeStatus(['kind' => 'restore', 'status' => 'failed', 'phase' => 'failed', 'started_at' => time(), 'finished_at' => time(),
            'message' => 'The restore failed: ' . $e->getMessage()]);
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
}
