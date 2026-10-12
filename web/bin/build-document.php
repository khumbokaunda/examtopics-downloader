#!/usr/bin/env php
<?php

declare(strict_types=1);

// Build one document that the library page started (status "building"), reporting progress
// to its row. Started detached by the web app; not meant to be run by hand.
//   php bin/build-document.php <document uuid>

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Ids;
use App\Services\Services;

$uuid = (string) ($argv[1] ?? '');
if (!Ids::isUuid($uuid)) {
    fwrite(STDERR, "usage: php bin/build-document.php <document uuid>\n");
    exit(64);
}
$app = App::boot();
(new Services($app))->documents()->build($uuid);
