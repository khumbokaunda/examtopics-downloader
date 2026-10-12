#!/usr/bin/env php
<?php

declare(strict_types=1);

// Background worker. Launched automatically by the web app (--drain); for a permanently
// running worker under systemd use --daemon (see docs/OPERATIONS.md).

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Services\Worker;

$daemon = in_array('--daemon', $argv, true);
if (!$daemon && !in_array('--drain', $argv, true)) {
    fwrite(STDERR, "usage: php bin/worker.php --drain|--daemon\n");
    exit(64);
}
exit((new Worker(Config::load()))->run($daemon));
