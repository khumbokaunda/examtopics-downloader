#!/usr/bin/env php
<?php

declare(strict_types=1);

// Check that the activity history was not changed after it was written: every entry must
// carry the hash of the one before it and match its own hash.
//   php bin/verify-audit.php [--json]
// Exit 0 when intact, 1 when not. The last hash it prints can be kept off the server: if it
// is still found in the chain later, nothing up to that point has been rewritten.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Services;

$app = App::boot();
$check = (new Services($app))->audit()->verify();
if (in_array('--json', array_slice($argv, 1), true)) {
    echo json_encode($check, JSON_UNESCAPED_SLASHES) . "\n";
} elseif ($check['ok']) {
    echo 'Intact: ' . $check['checked'] . " entries checked.\nLast hash: " . $check['last_hash'] . "\n";
} else {
    echo 'Changed: entry #' . $check['broken_at'] . ' does not match, after ' . $check['checked'] . " intact entries.\n";
}
exit($check['ok'] ? 0 : 1);
