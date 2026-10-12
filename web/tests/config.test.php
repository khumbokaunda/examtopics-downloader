<?php

// Test configuration (selected with EXAMLIB_CONFIG). Uses a dedicated database that the
// test suite truncates freely, and a throwaway storage directory.
return [
    'db' => [
        'dsn'      => getenv('EXAMLIB_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=examlib_test;charset=utf8mb4',
        'user'     => getenv('EXAMLIB_TEST_USER') ?: 'examlib',
        'password' => getenv('EXAMLIB_TEST_PASSWORD') ?: 'examlib-test-pw',
    ],
    'storage_dir' => getenv('EXAMLIB_TEST_STORAGE') ?: sys_get_temp_dir() . '/examlib-test-storage',
    'session' => ['secure' => false],
    'github_token' => 'ghp_testtokenTESTTOKENtesttoken000000',
];
