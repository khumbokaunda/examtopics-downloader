<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;

/**
 * Who may create their own account (Users > Sign-up): anyone, after verifying their email
 * address ("open"); anyone, but an administrator approves the account after that
 * ("approval"); or nobody ("closed"). Sign-up needs email to be set up, since the code is
 * emailed. New accounts are members.
 */
final class Registration
{
    public const MODES = [
        'open' => ['Open', 'Anyone can create an account. They verify their email address with a code, then sign in as a member.'],
        'approval' => ['Needs approval', 'Anyone can ask for an account. After they verify their email address, an administrator approves it.'],
        'closed' => ['Closed', 'Only administrators create accounts, on this page.'],
    ];

    public function __construct(private App $app, private UserRepository $users, private Notifier $notifier)
    {
    }

    public function mode(): string
    {
        try {
            $mode = (string) (new SettingsRepository($this->app->db()))->get('registration.mode', (string) $this->app->config()->get('registration', 'open'));
        } catch (\Throwable) {
            $mode = 'closed';
        }
        return isset(self::MODES[$mode]) ? $mode : 'open';
    }

    public function setMode(string $mode): void
    {
        if (isset(self::MODES[$mode])) {
            (new SettingsRepository($this->app->db()))->set('registration.mode', $mode);
        }
    }

    /** Is the sign-up page offered? */
    public function available(Mailer $mailer): bool
    {
        return $this->mode() !== 'closed' && $mailer->configured();
    }

    /**
     * A new account proved its email address: it becomes active (open sign-up) or waits for
     * approval, and the people who manage users are told.
     *
     * @param array<string,mixed> $user
     * @return string the new status
     */
    public function verified(array $user): string
    {
        $status = $this->mode() === 'open' ? 'active' : 'pending';
        $this->users->markEmailVerified((int) $user['id']);
        $this->users->update((int) $user['id'], ['status' => $status]);
        $name = Access::displayName($user) . ' (' . $user['email'] . ')';
        $link = '/users/' . rawurlencode((string) ($this->users->find((int) $user['id'])['uuid'] ?? $user['username']));
        if ($status === 'pending') {
            $this->notifier->notifyPermission('users.manage', 'account', 'Account waiting for approval: ' . Access::displayName($user),
                $name . ' signed up and verified their email address. Open the account and approve it, or disable it.',
                $link, 'account:' . (int) $user['id'], 'account-pending:' . (int) $user['id']);
        } else {
            $this->notifier->notifyPermission('users.manage', 'account', 'New account: ' . Access::displayName($user),
                $name . ' signed up and verified their email address. They can read the library and request exams.',
                $link, 'account:' . (int) $user['id'], 'account-new:' . (int) $user['id']);
        }
        $this->app->logger()->info('security', 'Account verified', ['username' => $user['username'], 'status' => $status]);
        return $status;
    }
}
