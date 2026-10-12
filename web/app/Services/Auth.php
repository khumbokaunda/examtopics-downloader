<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Session;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;

final class Auth
{
    /** @var array<string,mixed>|null|false false = not looked up yet */
    private array|null|false $user = false;

    public function __construct(
        private Session $session,
        private UserRepository $users,
        private LoginAttemptRepository $attempts,
        private Logger $log,
        private Config $config,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        if ($this->user === false) {
            $id = $this->session->get('user_id');
            $this->user = is_int($id) ? $this->users->find($id) : null;
        }
        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** @return string|null null on success, otherwise a message safe to display */
    public function attempt(string $username, string $password, string $ip): ?string
    {
        $max = (int) $this->config->get('security.login_max_attempts', 5);
        $window = (int) $this->config->get('security.login_window', 900);
        if ($this->attempts->recentFailures($ip, $window) >= $max) {
            $this->log->warning('security', 'Login throttled', ['ip' => $ip, 'username' => $username]);
            return 'Too many failed sign-in attempts. Try again in a few minutes.';
        }

        $user = $username === '' ? null : $this->users->findByUsername($username);
        // Verify against a dummy hash when the user does not exist so response timing does
        // not reveal which usernames exist.
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $ok = password_verify($password, (string) $hash) && $user !== null;

        $this->attempts->record($ip, $username, $ok);
        if (!$ok) {
            $this->log->warning('security', 'Login failed', ['ip' => $ip, 'username' => $username]);
            return 'Incorrect username or password.';
        }

        $this->session->regenerate();
        $this->session->set('user_id', (int) $user['id']);
        $this->session->remove('_csrf'); // fresh token for the authenticated session
        $this->users->touchLogin((int) $user['id']);
        $this->users->rehashIfNeeded((int) $user['id'], $password, (string) $user['password_hash']);
        $this->user = false;
        $this->log->info('security', 'Login succeeded', ['ip' => $ip, 'username' => $username]);
        return null;
    }

    public function logout(string $ip): void
    {
        $user = $this->user();
        $this->session->destroy();
        $this->user = null;
        $this->log->info('security', 'Logout', ['ip' => $ip, 'username' => $user['username'] ?? null]);
    }
}
