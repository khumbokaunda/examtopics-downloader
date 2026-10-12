<?php

declare(strict_types=1);

// Default configuration. Do not put secrets here: copy config.local.php.example to
// config.local.php (not committed) or use the EXAMLIB_* environment variables.

$webRoot = dirname(__DIR__);

return [
    'app_name' => 'Exam Library',

    'db' => [
        'dsn'      => 'mysql:host=127.0.0.1;port=3306;dbname=examlib;charset=utf8mb4',
        'user'     => 'examlib',
        'password' => '',
    ],

    // Everything here is private: cache, documents, job files, logs.
    // It must NOT be inside the web server's document root (web/public).
    'storage_dir' => $webRoot . '/storage',

    'tool' => [
        // Fixed path to the compiled Go tool. The web app never accepts an executable path
        // from a request. Build with: go build -o bin/examtopics-downloader ./cmd/main.go
        'binary'       => dirname($webRoot) . '/bin/examtopics-downloader',
        'default_rps'  => 1.0,
        'allowed_rps'  => [0.25, 0.5, 1.0],
        'max_runtime'  => 6 * 3600,   // seconds before a collection is terminated
        'kill_grace'   => 10,         // seconds between SIGTERM and SIGKILL
    ],

    // PHP CLI used to launch the background worker. Under php-fpm PHP_BINARY is the
    // fpm binary, so this must point at the CLI explicitly.
    'php_cli' => PHP_BINDIR . '/php',

    // GitHub token for the optional third-party cached dataset (raises the API limit).
    // Passed to the tool via its environment, never on the command line.
    'github_token' => '',

    'session' => [
        'name'         => 'examlib_session',
        'secure'       => true,        // set false only for local http testing
        'idle_timeout' => 4 * 3600,
    ],

    'security' => [
        'login_max_attempts' => 5,     // failed attempts per IP within the window
        'login_window'       => 900,   // seconds
        // Only enable if the app sits behind a reverse proxy you control.
        'trust_forwarded_for' => false,
        'hsts'                => true,
    ],

    'documents' => [
        // Fetch question images into PDFs. Bounded and host-restricted. Off by default.
        'pdf_images'        => false,
        'image_hosts'       => ['examtopics.com', 'www.examtopics.com', 'img.examtopics.com'],
        'image_max_bytes'   => 2 * 1024 * 1024,
        'image_max_count'   => 300,
        'image_total_bytes' => 50 * 1024 * 1024,
        'image_timeout'     => 10,
        'pdf_time_limit'    => 600,
        'pdf_memory_limit'  => '1024M',
    ],

    'worker' => [
        // Start a detached worker automatically when jobs are queued. Set false if you run
        // bin/worker.php --daemon under systemd instead (docs/OPERATIONS.md).
        'autostart' => true,
    ],

    // util-linux setsid, used to detach the worker from the web server.
    'setsid' => '/usr/bin/setsid',

    'viewer' => [
        'per_page' => 10,
    ],

    'log' => [
        'level' => 'info',
    ],
];
