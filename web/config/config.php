<?php

declare(strict_types=1);

// Default configuration. Do not put secrets here: copy config.local.php.example to
// config.local.php (not committed) or use the EXAMTOPICS_* environment variables.

$webRoot = dirname(__DIR__);

return [
    'app_name' => 'Examtopics',

    'db' => [
        'dsn'      => 'mysql:host=127.0.0.1;port=3306;dbname=examtopics;charset=utf8mb4',
        'user'     => 'examtopics',
        'password' => '',
    ],

    // Everything here is private: cache, documents, job files, logs.
    // It must NOT be inside the web server's document root (web/public).
    'storage_dir' => $webRoot . '/storage',

    'tool' => [
        // Fixed path to the compiled Go tool. The web app never accepts an executable path
        // from a request. Build with: go build -o bin/examtopics-downloader ./cmd
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
        'name'         => 'examtopics_session',
        'secure'       => true,        // set false only for local http testing
        'idle_timeout' => 4 * 3600,  // sign out after this many seconds without activity
        'absolute_timeout' => 0,      // sign out this long after signing in (0 = never)
        // Settings > Time and sign-in overrides both, and the timezone below.
    ],
    // Default timezone for showing times (they are stored in UTC). Each user can choose their
    // own under Settings > Time and sign-in. Any PHP timezone name, such as Africa/Blantyre.
    'timezone' => 'UTC',

    'library' => [
        // In the library's table view, collectors see "older than N days" beside an exam
        // collected longer ago than this, as a hint to collect a fresh version.
        'stale_days' => 90,
    ],

    'security' => [
        // An account locks for lockout_minutes after lockout_attempts wrong passwords in a row.
        'lockout_attempts'      => 5,
        'lockout_minutes'       => 15,
        // A network (one address) that fails network_max_failures times within network_window
        // seconds, across any names, is refused for network_block_minutes. Private, shared
        // (3+ accounts signed in from it in 30 days) and Cloudflare addresses are never blocked.
        'network_max_failures'  => 20,
        'network_window'        => 900,
        'network_block_minutes' => 30,
        // Proxies in front of this server whose X-Forwarded-For is believed: addresses or CIDR
        // ranges, e.g. ['127.0.0.1', '10.0.0.0/8']. Without them every visitor looks like the proxy.
        'trusted_proxies'       => [],
        // Behind Cloudflare, take the visitor's address from CF-Connecting-IP. Only believed on
        // requests that arrive from Cloudflare's published ranges (or a listed proxy).
        'trust_cloudflare'      => true,
        // Older switch: believe X-Forwarded-For from the one proxy directly in front, whatever
        // its address. Prefer trusted_proxies.
        'trust_forwarded_for'   => false,
        // Backups and deleting accounts ask the person to confirm it is them (their two-step
        // code, or their password when it is off); a confirmation lasts this many minutes.
        'confirm_minutes'       => 10,
        'hsts'                  => true,
    ],

    'media' => [
        // After each collection, the tool downloads the question and answer images (only
        // from ExamTopics, paced like page requests) and they are kept in storage/media.
        // The reader and PDFs then use the saved copies.
        'download'  => true,
        'max_bytes' => 5 * 1024 * 1024,   // per image, the tool's own limit; also for pictures added in the reader
    ],

    'integrity' => [
        // The integrity page keeps each exam's figures until the exam changes, and works them
        // out in a background process (bin/integrity.php) so the page never waits on reading
        // every exam. False works them out in the request instead.
        'background' => true,
    ],

    'documents' => [
        // PDFs embed the saved images up to this total; the rest are listed by address.
        'image_total_bytes' => 100 * 1024 * 1024,
        // PDFs are built by a background process (bin/build-document.php), so the page can
        // show progress and no web request waits on a large exam.
        'background'        => true,
        'pdf_time_limit'    => 1800,   // seconds per PDF build
        'pdf_memory_limit'  => '2048M',  // for the background PDF process only
        'pdf_part_questions' => 500,     // larger exams get their PDF in parts of at most this many questions
    ],

    // Email (sign-up codes, password resets, notifications). Usually set on Settings > Email,
    // which takes precedence; these are the defaults. app_url is the address people open,
    // used for links in emails, e.g. https://examtopics.example.com.
    'app_url' => '',
    'mail' => [
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',      // tls (STARTTLS) | ssl | none
        'username' => '',
        'password' => '',
        'from_address' => '',
        'from_name' => '',
        'transport' => 'smtp',      // "file" writes messages to storage/mail instead of sending
        'background' => true,       // send queued notification emails from a background process
    ],

    // Backups made on the Backups page are packed by a background process (bin/backup.php).
    'backup' => ['background' => true],

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
