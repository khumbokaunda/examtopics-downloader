<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Repositories\UserRepository;
use App\Services\Access;
use App\Services\Registration;

/**
 * Users: accounts, their role, extra permissions and status. Two guards keep the system
 * reachable: nobody changes their own role or status here (that is what locks people out),
 * and the last active administrator cannot be demoted, disabled or deleted. Every change is
 * written to the security log.
 */
final class UserController extends Controller
{
    public function index(): Response
    {
        return $this->render('users/index', [
            'title' => 'Users',
            'nav' => 'users',
            'users' => $this->s->users()->all(),
            'me' => (int) $this->auth->user()['id'],
            'mode' => $this->s->registration()->mode(),
            'mailReady' => $this->s->mailer()->configured(),
        ]);
    }

    /** Users > Sign-up: who may create their own account. */
    public function registration(): Response
    {
        $mode = (string) $this->request->input('mode', '');
        if (isset(Registration::MODES[$mode])) {
            $this->s->registration()->setMode($mode);
            $this->audit('Sign-up setting changed', (string) $this->auth->user()['username'], ['mode' => $mode]);
            $this->flash('success', 'Sign-up is now: ' . Registration::MODES[$mode][0] . '.');
        }
        return $this->redirect('/users');
    }

    /** Approve an account waiting for approval. */
    public function approve(string $username): Response
    {
        $account = $this->find($username);
        if ($account['status'] === 'pending') {
            $this->s->users()->update((int) $account['id'], ['status' => 'active']);
            $this->approved($account);
            $this->flash('success', 'Approved "' . $account['username'] . '". They have been told by email.');
        }
        return $this->redirect('/users');
    }

    /** @param array<string,mixed> $account an account that just became active after waiting */
    private function approved(array $account): void
    {
        $this->audit('User approved', $account['username'], []);
        $this->s->notifier()->resolve('account:' . (int) $account['id']);
        if ($account['email'] !== null) {
            $mailer = $this->s->mailer();
            $link = $mailer->link('/login');
            $mailer->send((string) $account['email'], 'Your account is ready',
                '<p>Your account on ' . e($this->s->branding()->get()['name']) . ' has been approved. You can sign in now'
                . ($link !== null ? ': <a href="' . e($link) . '">' . e($link) . '</a>' : '') . '.</p>', null, Access::displayName($account));
        }
    }

    public function create(): Response
    {
        return $this->render('users/edit', [
            'title' => 'New user',
            'nav' => 'users',
            'account' => null,
            'form' => ['username' => '', 'display_name' => '', 'email' => '', 'role' => Access::MEMBER, 'status' => 'active', 'permissions' => []],
            'error' => null,
            'self' => false,
        ]);
    }

    public function store(): Response
    {
        $form = $this->form();
        $form['username'] = trim((string) $this->request->input('username', ''));
        $password = (string) $this->request->input('password', '');
        $users = $this->s->users();
        $error = match (true) {
            !UserRepository::validUsername($form['username']) => 'Usernames are 3 to 64 letters, digits, dots, hyphens or underscores.',
            $users->findByUsername($form['username']) !== null => 'That username is taken.',
            default => $this->checkFields($form, 0),
        } ?? (strlen($password) < 12 ? 'The password must be at least 12 characters.' : null);
        if ($error !== null) {
            return $this->render('users/edit', [
                'title' => 'New user', 'nav' => 'users', 'account' => null, 'form' => $form, 'error' => $error, 'self' => false,
            ], 422);
        }
        // An address typed by an administrator counts as verified.
        $users->create($form['username'], $password, $form['role'], [
            'display_name' => $form['display_name'], 'email' => $form['email'], 'status' => $form['status'],
            'permissions' => $form['permissions'], 'email_verified' => true,
        ]);
        $this->audit('User created', $form['username'], ['role' => $form['role'], 'status' => $form['status'], 'extra' => $form['permissions']]);
        $this->flash('success', 'Account "' . $form['username'] . '" created. Share the password with them privately; they can change it on their account page.');
        return $this->redirect('/users');
    }

    public function edit(string $username): Response
    {
        $account = $this->find($username);
        return $this->render('users/edit', [
            'title' => Access::displayName($account),
            'nav' => 'users',
            'account' => $account,
            'form' => [
                'username' => $account['username'], 'display_name' => (string) ($account['display_name'] ?? ''),
                'email' => (string) ($account['email'] ?? ''), 'role' => $account['role'], 'status' => $account['status'],
                'permissions' => Access::extras($account),
            ],
            'error' => null,
            'self' => (int) $account['id'] === (int) $this->auth->user()['id'],
        ]);
    }

    public function update(string $username): Response
    {
        $account = $this->find($username);
        $self = (int) $account['id'] === (int) $this->auth->user()['id'];
        $form = $this->form();
        $form['username'] = $account['username'];
        if ($self) {
            // Your own role and status are not changed from here.
            $form['role'] = $account['role'];
            $form['status'] = $account['status'];
            $form['permissions'] = Access::cleanExtras($account['role'], Access::extras($account));
        }
        $password = (string) $this->request->input('password', '');
        $error = $this->checkFields($form, (int) $account['id'])
            ?? ($password !== '' && strlen($password) < 12 ? 'A new password must be at least 12 characters.' : null)
            ?? $this->lastAdminGuard($account, $form['role'], $form['status']);
        if ($error !== null) {
            return $this->render('users/edit', [
                'title' => Access::displayName($account), 'nav' => 'users', 'account' => $account, 'form' => $form,
                'error' => $error, 'self' => $self,
            ], 422);
        }
        $users = $this->s->users();
        $users->update((int) $account['id'], [
            'display_name' => $form['display_name'], 'email' => $form['email'], 'role' => $form['role'],
            'status' => $form['status'], 'permissions' => $form['permissions'],
        ]);
        if ($password !== '') {
            $users->setPassword((int) $account['id'], $password);
        }
        if ($form['email'] !== '' && $form['email'] !== (string) ($account['email'] ?? '')) {
            $users->markEmailVerified((int) $account['id']); // typed by an administrator
            $this->s->codes()->forget((int) $account['id'], 'verify');
        }
        if ($account['status'] === 'pending' && $form['status'] === 'active') {
            $this->approved($account);
        }
        $changes = [];
        foreach (['role', 'status'] as $k) {
            if ($account[$k] !== $form[$k]) {
                $changes[$k] = $account[$k] . ' -> ' . $form[$k];
            }
        }
        if (Access::extras($account) !== $form['permissions']) {
            $changes['extra'] = $form['permissions'];
        }
        if ($password !== '') {
            $changes['password'] = 'set by an administrator';
        }
        $this->audit('User updated', $account['username'], $changes);
        $this->auth->refresh();
        $this->flash('success', 'Saved "' . $account['username'] . '".');
        return $this->redirect('/users');
    }

    public function delete(string $username): Response
    {
        $account = $this->find($username);
        if ((int) $account['id'] === (int) $this->auth->user()['id']) {
            $this->flash('danger', 'You cannot delete your own account.');
            return $this->redirect('/users/' . rawurlencode($username));
        }
        $guard = $this->lastAdminGuard($account, 'member', 'disabled');
        if ($guard !== null) {
            $this->flash('danger', $guard);
            return $this->redirect('/users/' . rawurlencode($username));
        }
        $this->s->users()->delete((int) $account['id']);
        $this->audit('User deleted', $account['username'], ['role' => $account['role']]);
        $this->flash('success', 'Account "' . $account['username'] . '" deleted.');
        return $this->redirect('/users');
    }

    /** @return array<string,mixed> */
    private function find(string $username): array
    {
        if (!UserRepository::validUsername($username)) {
            throw HttpException::notFound('User');
        }
        return $this->s->users()->findByUsername($username) ?? throw HttpException::notFound('User');
    }

    /**
     * The posted fields, reduced to allowed values: the role from the one assignable list,
     * the status from the known states, extra permissions from the catalogue.
     *
     * @return array{username:string,display_name:string,email:string,role:string,status:string,permissions:list<string>}
     */
    private function form(): array
    {
        $role = (string) $this->request->input('role', Access::MEMBER);
        $role = isset(Access::assignableRoles()[$role]) ? $role : Access::MEMBER;
        $status = (string) $this->request->input('status', 'active');
        $posted = $this->request->post['permissions'] ?? [];
        return [
            'username' => '',
            'display_name' => trim((string) $this->request->input('display_name', '')),
            'email' => UserRepository::normalizeEmail((string) $this->request->input('email', '')),
            'role' => $role,
            'status' => isset(Access::STATUSES[$status]) ? $status : 'active',
            'permissions' => Access::cleanExtras($role, is_array($posted) ? $posted : []),
        ];
    }

    /** @param array{display_name:string,email:string} $form */
    private function checkFields(array $form, int $id): ?string
    {
        if (mb_strlen($form['display_name']) > 128) {
            return 'Keep the name to 128 characters.';
        }
        if ($form['email'] !== '' && !UserRepository::validEmail($form['email'])) {
            return 'That does not look like an email address.';
        }
        if ($form['email'] !== '' && $this->s->users()->emailTaken($form['email'], $id)) {
            return 'Another account uses that email address.';
        }
        return null;
    }

    /** @param array<string,mixed> $account */
    private function lastAdminGuard(array $account, string $role, string $status): ?string
    {
        $wasActiveAdmin = $account['role'] === Access::ADMIN && $account['status'] === 'active';
        $staysActiveAdmin = $role === Access::ADMIN && $status === 'active';
        if ($wasActiveAdmin && !$staysActiveAdmin && $this->s->users()->activeAdminCount() <= 1) {
            return 'This is the only active administrator. Make another account an administrator first.';
        }
        return null;
    }

    /** @param array<string,mixed> $details */
    private function audit(string $event, string $username, array $details): void
    {
        $this->app->logger()->info('security', $event, ['by' => $this->auth->user()['username'] ?? null, 'username' => $username] + $details);
    }
}
