#!/usr/bin/env php
<?php

declare(strict_types=1);

// Lift sign-in protection from the command line, for when the only administrator is locked
// out or their own network is blocked.
//   php bin/unlock.php list                 locked accounts and blocked networks
//   php bin/unlock.php account <username>   unlock an account
//   php bin/unlock.php network <address>    allow sign-ins from an address again
//   php bin/unlock.php two-step <username>  turn off two-step sign-in (a lost phone and codes)

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;
use App\Services\Services;

$app = App::boot();
$s = new Services($app);
$guard = $s->signInGuard();
[$command, $target] = [(string) ($argv[1] ?? ''), (string) ($argv[2] ?? '')];

switch ($command) {
    case 'list':
        $now = Database::now();
        $accounts = $app->db()->all('SELECT username, locked_until FROM users WHERE locked_until > ? ORDER BY username', [$now]);
        $networks = $app->db()->all('SELECT ip, blocked_until, failures FROM blocked_networks WHERE blocked_until > ? ORDER BY ip', [$now]);
        echo $accounts === [] ? "No account is locked.\n" : '';
        foreach ($accounts as $a) {
            echo 'Locked:  ' . str_pad((string) $a['username'], 30) . ' until ' . $a['locked_until'] . " UTC\n";
        }
        echo $networks === [] ? "No network is blocked.\n" : '';
        foreach ($networks as $n) {
            echo 'Blocked: ' . str_pad((string) $n['ip'], 30) . ' until ' . $n['blocked_until'] . ' UTC (' . $n['failures'] . " failures)\n";
        }
        exit(0);

    case 'account':
        $user = $s->users()->findByUsername($target);
        if ($user === null) {
            fwrite(STDERR, "No account named \"{$target}\".\n");
            exit(1);
        }
        echo $guard->unlock($user) ? "Unlocked {$target}.\n" : "{$target} was not locked.\n";
        exit(0);

    case 'network':
        if (filter_var($target, FILTER_VALIDATE_IP) === false) {
            fwrite(STDERR, "That is not an IP address.\n");
            exit(64);
        }
        echo $guard->unblock($target) ? "Sign-ins from {$target} are allowed again.\n" : "{$target} was not blocked; its failures no longer count.\n";
        exit(0);

    case 'two-step':
        $user = $s->users()->findByUsername($target);
        if ($user === null) {
            fwrite(STDERR, "No account named \"{$target}\".\n");
            exit(1);
        }
        if ($s->twoStep()->disable((int) $user['id'])) {
            $s->audit()->recordBySystem('security.two_step_disabled', ['username' => $target, 'by' => 'command line'], 'user', $target);
            echo "Two-step sign-in is off for {$target}. They sign in with their password, and can turn it on again.\n";
        } else {
            echo "{$target} did not have two-step sign-in on.\n";
        }
        exit(0);

    default:
        fwrite(STDERR, "usage: php bin/unlock.php list | account <username> | network <address> | two-step <username>\n");
        exit(64);
}
