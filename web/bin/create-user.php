#!/usr/bin/env php
<?php

declare(strict_types=1);

// Create an administrator, or reset an existing account's password and re-enable it:
//   php bin/create-user.php <username> [--admin]
// --admin also makes an existing account an administrator (to recover from a lock-out).
// The password is read from the terminal without echo (or from stdin when piped).

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\Services;

$args = array_slice($argv, 1);
$makeAdmin = in_array('--admin', $args, true);
$username = (string) (array_values(array_filter($args, static fn($a) => $a !== '--admin'))[0] ?? '');
if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
    fwrite(STDERR, "usage: php bin/create-user.php <username> [--admin]   (3-64 chars: letters, digits . _ -)\n");
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
    $users->update((int) $existing['id'], ['status' => 'active'] + ($makeAdmin ? ['role' => 'admin'] : []));
    echo "Password updated for {$username}" . ($existing['status'] !== 'active' ? ', and the account is active again' : '')
        . ($makeAdmin && $existing['role'] !== 'admin' ? ', now an administrator' : '') . ".\n";
} else {
    $users->create($username, $password, 'admin');
    echo "Administrator {$username} created.\n";
}
