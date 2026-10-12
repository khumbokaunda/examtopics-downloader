#!/usr/bin/env php
<?php

declare(strict_types=1);

// Rebuild cache-version metadata from the manifests in storage/cache after database loss.
// Run bin/migrate.php first. Existing rows are kept.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Services;

$r = (new Services(App::boot()))->cache()->rebuildIndex();
echo "Indexed {$r['indexed']} versions; {$r['existing']} already present.\n";
foreach ($r['skipped'] as $s) {
    echo "Skipped: {$s}\n";
}
exit($r['skipped'] === [] ? 0 : 1);
