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

    /**
     * The signed-in person. An account that is disabled (or otherwise not active) while signed
     * in stops working on its next request.
     *
     * @return array<string,mixed>|null
     */
    public function user(): ?array
    {
        if ($this->user === false) {
            $id = $this->session->get('user_id');
            $user = is_int($id) ? $this->users->find($id) : null;
            $this->user = $user !== null && ($user['status'] ?? 'active') === 'active' ? $user : null;
        }
        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function can(string $permission): bool
    {
        return Access::can($this->user(), $permission);
    }

    public function isAdmin(): bool
    {
        return Access::isAdmin($this->user());
    }

    /** Forget the cached row, after the signed-in person's own account was changed. */
    public function refresh(): void
    {
        $this->user = false;
    }

    /**
     * Sign in with a username or an email address.
     *
     * @return string|null null on success, otherwise a message safe to display
     */
    public function attempt(string $username, string $password, string $ip): ?string
    {
        $max = (int) $this->config->get('security.login_max_attempts', 5);
        $window = (int) $this->config->get('security.login_window', 900);
        if ($this->attempts->recentFailures($ip, $window) >= $max) {
            $this->log->warning('security', 'Login throttled', ['ip' => $ip, 'username' => $username]);
            return 'Too many failed sign-in attempts. Try again in a few minutes.';
        }

        $user = $username === '' ? null : $this->users->findByLogin($username);
        // Verify against a dummy hash when the user does not exist so response timing does
        // not reveal which usernames exist.
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $ok = password_verify($password, (string) $hash) && $user !== null;

        $this->attempts->record($ip, $username, $ok);
        if (!$ok) {
            $this->log->warning('security', 'Login failed', ['ip' => $ip, 'username' => $username]);
            return 'Incorrect username or password.';
        }
        // Only after the password is right does the answer say what state the account is in.
        $status = (string) ($user['status'] ?? 'active');
        if ($status !== 'active') {
            $this->log->info('security', 'Login refused: account ' . $status, ['ip' => $ip, 'username' => $user['username']]);
            return match ($status) {
                'pending' => 'Your account is waiting for an administrator to approve it.',
                'unverified' => 'Verify your email address first: enter the code from the email we sent.',
                default => 'This account is disabled. Ask an administrator if you need it again.',
            };
        }

        $this->session->regenerate();
        $this->session->set('user_id', (int) $user['id']);
        $this->session->set('_started_at', time()); // the sign-in lifetime counts from here
        $this->session->remove('_csrf'); // fresh token for the authenticated session
        $this->users->touchLogin((int) $user['id']);
        $this->users->rehashIfNeeded((int) $user['id'], $password, (string) $user['password_hash']);
        $this->user = false;
        $this->log->info('security', 'Login succeeded', ['ip' => $ip, 'username' => $user['username']]);
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
