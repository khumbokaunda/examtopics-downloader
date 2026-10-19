#!/usr/bin/env php
<?php

declare(strict_types=1);

// Remove the working directories (events, tool log, rejected output) of jobs that finished
// more than N days ago. Job history rows, cached exams and documents are never touched.
// Also removes expired sign-up codes and rate-limit records, and sign-ups that never verified
// their email address within a week, and backup uploads left unfinished for a week.
// With --media, also delete saved images that no cached version refers to any more (after
// versions were deleted); this reads every cached dataset, so it is not the default.
//   php bin/cleanup.php --days=30 [--media] [--dry-run]

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

$days = 30;
$dry = false;
$media = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m) === 1) {
        $days = max(1, (int) $m[1]);
    } elseif ($arg === '--dry-run') {
        $dry = true;
    } elseif ($arg === '--media') {
        $media = true;
    } else {
        fwrite(STDERR, "usage: php bin/cleanup.php [--days=N] [--media] [--dry-run]\n");
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
        // Files, plus the image download folder a stopped worker may have left behind.
        foreach ([$dir . '/images', $dir] as $d) {
            foreach (is_dir($d) && !is_link($d) ? scandir($d) ?: [] : [] as $f) {
                if ($f !== '.' && $f !== '..' && (is_file($d . '/' . $f) || is_link($d . '/' . $f))) {
                    unlink($d . '/' . $f);
                }
            }
        }
        @rmdir($dir . '/images');
        rmdir($dir);
    }
    $removed++;
}
$app->logger()->info('app', 'Job directory cleanup', ['removed' => $removed, 'days' => $days, 'dry_run' => $dry, 'at' => Database::now()]);
echo ($dry ? 'Would remove ' : 'Removed ') . $removed . " job directories finished more than {$days} days ago.\n";

// Expired sign-up leftovers: rate-limit records and codes older than a day, and accounts
// that signed up but never verified their email address within a week.
if (!$dry) {
    $services = new \App\Services\Services($app);
    $services->throttle()->prune();
    $services->codes()->prune();
    $uploads = $services->backupUploads()->prune(7);
    if ($uploads > 0) {
        echo "Removed {$uploads} backup uploads nobody had added to for a week.\n";
    }
    $stale = $app->db()->run("DELETE FROM users WHERE status = 'unverified' AND created_at < ?", [gmdate('Y-m-d H:i:s', time() - 7 * 86400)])->rowCount();
    if ($stale > 0) {
        $app->logger()->info('security', 'Unverified sign-ups removed', ['count' => $stale]);
        echo "Removed {$stale} sign-ups that never verified their email address.\n";
    }
}

if ($media) {
    $services = new \App\Services\Services($app);
    $urls = [];
    foreach ($app->db()->all('SELECT id FROM cache_versions') as $row) {
        $version = $services->versions()->find((int) $row['id']);
        try {
            $urls = array_merge($urls, \App\Services\MediaStore::urlsOf($services->cache()->load((array) $version)));
        } catch (RuntimeException $e) {
            // An unreadable dataset might still refer to images: keep everything.
            fwrite(STDERR, 'Not pruning images: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }
    $pruned = $services->media()->prune(array_values(array_unique($urls)), $dry);
    $app->logger()->info('app', 'Image cleanup', $pruned + ['dry_run' => $dry]);
    echo ($dry ? 'Would remove ' : 'Removed ') . $pruned['files'] . ' unused images (' . fmt_bytes($pruned['bytes']) . ")\n";
}
