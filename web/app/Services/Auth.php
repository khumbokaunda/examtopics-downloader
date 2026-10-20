<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Session;
use App\Repositories\UserRepository;

final class Auth
{
    /** @var array<string,mixed>|null|false false = not looked up yet */
    private array|null|false $user = false;
    private bool $endedByRestore = false;
    private ?int $unverified = null;

    public function __construct(
        private Session $session,
        private UserRepository $users,
        private SignInGuard $guard,
        private Logger $log,
        private Config $config,
        private int $signedOutBefore = 0,
        private ?AuditLog $audit = null,
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
            // A sign-in from before the last restore is over, even if a request that was running
            // during the restore wrote its session back (see Backup::restoredAt()).
            if (is_int($id) && $this->signedOutBefore > 0 && (int) $this->session->get('_started_at', 0) < $this->signedOutBefore) {
                $this->session->remove('user_id');
                $id = null;
                $this->endedByRestore = true;
            }
            $user = is_int($id) ? $this->users->find($id) : null;
            $this->user = $user !== null && ($user['status'] ?? 'active') === 'active' ? $user : null;
        }
        return $this->user;
    }

    /** Did this request find its sign-in ended by a restore? The sign-in form then says why. */
    public function endedByRestore(): bool
    {
        $this->user();
        return $this->endedByRestore;
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
        // A network blocked for failing too often is refused before anything is looked up.
        $wait = $this->guard->networkBlocked($ip);
        if ($wait !== null) {
            $this->log->warning('security', 'Sign-in refused: network blocked', ['ip' => $ip, 'username' => IdentifierMask::mask($username)]);
            return 'Too many failed sign-ins came from your network. Try again in ' . self::minutes($wait) . '.';
        }

        $user = $username === '' ? null : $this->users->findByLogin($username);
        // Verify against a dummy hash when the user does not exist so response timing does
        // not reveal which usernames exist.
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $ok = password_verify($password, (string) $hash) && $user !== null;

        // A name that matched an account is that account; anything else may be a password.
        $shown = $user !== null ? (string) $user['username'] : IdentifierMask::mask($username);
        // A locked account is refused whatever the password, so guessing on stops counting.
        $locked = $user !== null ? SignInGuard::lockedFor($user) : null;
        if ($locked !== null) {
            $this->guard->failed($ip, $user, $shown, SignInGuard::LOCKED);
            $this->log->warning('security', 'Sign-in refused: account locked', ['ip' => $ip, 'username' => $shown]);
            $this->audit?->record('auth.sign_in_failed', ['identifier' => $shown, 'reason' => SignInGuard::LOCKED], 'user', $shown);
            return 'This account is locked after too many wrong passwords. Try again in ' . self::minutes($locked) . ', or ask an administrator to unlock it.';
        }
        if (!$ok) {
            $reason = $user === null ? SignInGuard::NO_ACCOUNT : SignInGuard::WRONG_PASSWORD;
            $lockedNow = $this->guard->failed($ip, $user, $shown, $reason);
            $this->log->warning('security', 'Login failed', ['ip' => $ip, 'username' => $shown]);
            // Whoever typed it is not known: a visitor, with the account it named as the subject.
            $this->audit?->record('auth.sign_in_failed', ['identifier' => $shown, 'reason' => $reason],
                $user !== null ? 'user' : null, $user !== null ? (string) $user['username'] : null);
            return $lockedNow
                ? 'Incorrect username or password. After too many wrong passwords the account is now locked for ' . $this->guard->lockoutMinutes() . ' minutes.'
                : 'Incorrect username or password.';
        }
        // Only after the password is right does the answer say what state the account is in.
        $status = (string) ($user['status'] ?? 'active');
        if ($status !== 'active') {
            $this->log->info('security', 'Login refused: account ' . $status, ['ip' => $ip, 'username' => $user['username']]);
            $this->guard->failed($ip, $user, $shown, SignInGuard::REFUSED);
            $this->audit?->record('auth.sign_in_refused', ['status' => Access::STATUSES[$status] ?? $status], 'user', (string) $user['username'], $user);
            if ($status === 'unverified') {
                $this->unverified = (int) $user['id'];
            }
            return match ($status) {
                'pending' => 'Your account is waiting for an administrator to approve it. You will get an email when it is ready.',
                'unverified' => 'Verify your email address first: enter the code from the email we sent.',
                default => 'This account is disabled. Ask an administrator if you need it again.',
            };
        }
        $this->guard->succeeded($ip, $user);
        $this->signIn($user, $ip);
        $this->users->rehashIfNeeded((int) $user['id'], $password, (string) $user['password_hash']);
        return null;
    }

    /** "1 minute", "15 minutes": a wait in seconds, rounded up. */
    public static function minutes(int $seconds): string
    {
        $m = max(1, (int) ceil($seconds / 60));
        return $m . ' minute' . ($m === 1 ? '' : 's');
    }

    /** After a refused sign-in: the id of an account whose only problem is an unverified email. */
    public function unverifiedId(): ?int
    {
        return $this->unverified;
    }

    /**
     * Start a session for an account that has proved who it is (the right password, or a
     * verification code just after signing up).
     *
     * @param array<string,mixed> $user
     */
    public function signIn(array $user, string $ip): void
    {
        $this->session->regenerate();
        $this->session->set('user_id', (int) $user['id']);
        $this->session->set('_started_at', time()); // the sign-in lifetime counts from here
        $this->session->remove('_csrf'); // fresh token for the authenticated session
        $this->users->touchLogin((int) $user['id']);
        $this->user = false;
        $this->log->info('security', 'Login succeeded', ['ip' => $ip, 'username' => $user['username']]);
        $this->audit?->record('auth.signed_in', [], 'user', (string) $user['username'], $user);
    }

    public function logout(string $ip): void
    {
        $user = $this->user();
        $this->session->destroy();
        $this->user = null;
        $this->log->info('security', 'Logout', ['ip' => $ip, 'username' => $user['username'] ?? null]);
        if ($user !== null) {
            $this->audit?->record('auth.signed_out', [], 'user', (string) $user['username'], $user);
        }
    }
}
