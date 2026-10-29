#!/usr/bin/env php
<?php

declare(strict_types=1);

// Work out the integrity page's figures for every exam whose kept figures are missing or out of
// date (App\Services\IntegrityChecks). Started detached by the integrity page; it can also be
// run by hand or from cron. Only one runs at a time; a second one exits at once.
//   php bin/integrity.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Services;

$app = App::boot();
$done = (new Services($app))->integrity()->workOutAll();
if ($done === null) {
    echo "Another check is already running.\n";
    exit(0);
}
echo gmdate('Y-m-d H:i:s') . " Checked {$done} exam" . ($done === 1 ? '' : 's') . ".\n";
