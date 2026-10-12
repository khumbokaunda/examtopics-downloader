<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

putenv('EXAMTOPICS_CONFIG=' . __DIR__ . '/config.test.php');
date_default_timezone_set('UTC');
