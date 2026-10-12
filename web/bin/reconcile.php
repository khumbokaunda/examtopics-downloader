#!/usr/bin/env php
<?php

declare(strict_types=1);

// Recovery for stale jobs (docs/OPERATIONS.md): if no worker is running, jobs still marked
// "running" are marked failed (stopping any orphaned tool process) and a worker is started
// for queued jobs. Safe to run at any time, e.g. from cron every few minutes.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Services;

$state = (new Services(App::boot()))->supervisor()->ensure();
echo match ($state) {
    'running' => "A worker is running; nothing to reconcile.\n",
    'started' => "Reconciled; started a worker for queued jobs.\n",
    'idle' => "Reconciled; no queued jobs.\n",
    default => "Reconciled, but the worker could not be started (see storage/logs/app.log).\n",
};
exit($state === 'start-failed' ? 1 : 0);
