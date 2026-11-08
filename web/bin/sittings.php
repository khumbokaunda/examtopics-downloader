#!/usr/bin/env php
<?php

declare(strict_types=1);

// Hand in every assessment sitting whose time is up (App\Services\Sittings::expireOverdue), so a
// candidate who closed the page or lost their connection still gets a result. A sitting is also
// handed in whenever it is next opened, and when its assessment's results are opened, so this is
// a safety net: run it every minute or few from cron.
//   php bin/sittings.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Services;

$app = App::boot();
$n = (new Services($app))->sittings()->expireOverdue();
if ($n > 0) {
    $app->logger()->info('app', 'Overdue sittings handed in', ['count' => $n]);
}
echo "Handed in {$n} overdue sittings.\n";
