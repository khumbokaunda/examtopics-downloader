#!/usr/bin/env php
<?php

declare(strict_types=1);

// Send the notification emails that are due, retrying failed ones on their schedule. Started
// in the background when an email is queued, and by cron every five minutes
// (deploy/examtopics.cron). Safe to run at the same time as another copy.
//   php bin/send-mail.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Services;

$result = (new Services(App::boot()))->mailQueue()->drain(100);
if (stream_isatty(STDOUT)) {
    echo "Sent {$result['sent']}, failed {$result['failed']}.\n";
}
