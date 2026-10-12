#!/usr/bin/env php
<?php

declare(strict_types=1);

// Remove the working directories (events, tool log, rejected output) of jobs that finished
// more than N days ago. Job history rows, cached exams and documents are never touched.
//   php bin/cleanup.php --days=30 [--dry-run]

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

$days = 30;
$dry = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m) === 1) {
        $days = max(1, (int) $m[1]);
    } elseif ($arg === '--dry-run') {
        $dry = true;
    } else {
        fwrite(STDERR, "usage: php bin/cleanup.php [--days=N] [--dry-run]\n");
        exit(64);
    }
}

$app = App::boot();
$cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
$ids = array_map('intval', array_column($app->db()->all(
    "SELECT id FROM jobs WHERE status IN ('completed','failed','cancelled') AND finished_at < ?", [$cutoff]
), 'id'));

$removed = 0;
foreach ($ids as $id) {
    $dir = $app->paths()->jobDir($id);
    if (!is_dir($dir)) {
        continue;
    }
    if (!$dry) {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..' && is_file($dir . '/' . $f)) {
                unlink($dir . '/' . $f);
            }
        }
        rmdir($dir);
    }
    $removed++;
}
$app->logger()->info('app', 'Job directory cleanup', ['removed' => $removed, 'days' => $days, 'dry_run' => $dry, 'at' => Database::now()]);
echo ($dry ? 'Would remove ' : 'Removed ') . $removed . " job directories finished more than {$days} days ago.\n";
