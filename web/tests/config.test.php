<?php

// Test configuration (selected with EXAMTOPICS_CONFIG). Uses a dedicated database that the
// test suite truncates freely, and a throwaway storage directory.
return [
    'db' => [
        'dsn'      => getenv('EXAMTOPICS_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=examtopics_test;charset=utf8mb4',
        'user'     => getenv('EXAMTOPICS_TEST_USER') ?: 'examtopics',
        'password' => getenv('EXAMTOPICS_TEST_PASSWORD') ?: 'examtopics-test-pw',
    ],
    'storage_dir' => getenv('EXAMTOPICS_TEST_STORAGE') ?: sys_get_temp_dir() . '/examtopics-test-storage',
    'session' => ['secure' => false],
    'github_token' => 'ghp_testtokenTESTTOKENtesttoken000000',
];
