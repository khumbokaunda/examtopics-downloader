#!/usr/bin/env php
<?php

declare(strict_types=1);

// Apply pending database migrations: php bin/migrate.php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Migrator;

$app = App::boot();
$applied = (new Migrator($app->db(), dirname(__DIR__) . '/database/migrations'))->migrate();
echo $applied === [] ? "Database is up to date.\n" : 'Applied: ' . implode(', ', $applied) . "\n";
