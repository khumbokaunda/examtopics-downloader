#!/usr/bin/env php
<?php

declare(strict_types=1);

// Create the user, or reset an existing user's password:
//   php bin/create-user.php <username>
// The password is read from the terminal without echo (or from stdin when piped).

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Services;

$username = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
    fwrite(STDERR, "usage: php bin/create-user.php <username>   (3-64 chars: letters, digits . _ -)\n");
    exit(64);
}

function read_secret(string $prompt): string
{
    $tty = stream_isatty(STDIN);
    if ($tty) {
        fwrite(STDOUT, $prompt);
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return rtrim((string) $line, "\r\n");
}

$password = read_secret('Password (min 12 characters): ');
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}
if (stream_isatty(STDIN) && read_secret('Repeat password: ') !== $password) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$users = (new Services(App::boot()))->users();
$existing = $users->findByUsername($username);
if ($existing !== null) {
    $users->setPassword((int) $existing['id'], $password);
    echo "Password updated for {$username}.\n";
} else {
    $users->create($username, $password);
    echo "User {$username} created.\n";
}
