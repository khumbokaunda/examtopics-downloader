<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Repositories\UserRepository;
use App\Services\AccountCodes;
use App\Services\Appearance;
use App\Services\SystemSettings;

/** The signed-in person's own account: profile, email, password, notifications, timezone and appearance. */
final class AccountController extends Controller
{
    public function index(): Response
    {
        $user = (array) $this->auth->user();
        $brand = $this->s->branding()->get();
        return $this->render('account/index', [
            'title' => 'Account',
            'nav' => 'account',
            'user' => $user,
            'appearance' => Appearance::forUser($user, $brand),
            'appearanceOptions' => Appearance::OPTIONS,
            'customAccent' => $brand['accent'] === 'custom',
            'systemTimezone' => (new SystemSettings($this->app))->effective()['timezone'],
            'myTimezone' => (string) ((json_decode((string) ($user['preferences'] ?? ''), true) ?: [])['timezone'] ?? ''),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'emailNotifications' => (string) ((json_decode((string) ($user['preferences'] ?? ''), true) ?: [])['email_notifications'] ?? '1') !== '0',
            'mailReady' => $this->s->mailer()->configured(),
        ]);
    }

    public function profile(): Response
    {
        $user = (array) $this->auth->user();
        $name = trim((string) $this->request->input('display_name', ''));
        $email = UserRepository::normalizeEmail((string) $this->request->input('email', ''));
        $users = $this->s->users();
        if (mb_strlen($name) > 128) {
            $this->flash('danger', 'Keep your name to 128 characters.');
        } elseif ($email !== '' && !UserRepository::validEmail($email)) {
            $this->flash('danger', 'That does not look like an email address.');
        } elseif ($email !== '' && $users->emailTaken($email, (int) $user['id'])) {
            $this->flash('danger', 'Another account uses that email address.');
        } elseif ($email === '' && $user['email'] !== null && $user['role'] !== 'admin') {
            $this->flash('danger', 'Your account needs an email address.');
        } else {
            $users->update((int) $user['id'], ['display_name' => $name, 'email' => $email]);
            $this->auth->refresh();
            if ($email !== (string) ($user['email'] ?? '')) {
                $this->app->logger()->info('security', 'Email address changed', ['username' => $user['username']]);
                $this->s->codes()->forget((int) $user['id'], 'verify');
                if ($email !== '' && $this->s->mailer()->configured()) {
                    $error = $this->s->codes()->send((array) $this->auth->user(), 'verify', $email);
                    $this->flash('success', 'Profile saved. ' . ($error ?? 'We sent a code to ' . $email . ' to confirm it: type it below.'));
                    return $this->redirect('/account#profile');
                }
            }
            $this->flash('success', 'Profile saved.');
        }
        return $this->redirect('/account');
    }

    /** Send a code to confirm the account's email address. */
    public function sendCode(): Response
    {
        $user = (array) $this->auth->user();
        if ($user['email'] === null || $user['email_verified_at'] !== null) {
            return $this->redirect('/account#profile');
        }
        $error = $this->s->codes()->send($user, 'verify', (string) $user['email']);
        $this->flash($error === null ? 'success' : 'warning', $error ?? 'We sent a code to ' . $user['email'] . '.');
        return $this->redirect('/account#profile');
    }

    public function verifyEmail(): Response
    {
        $user = (array) $this->auth->user();
        $result = $this->s->codes()->check((int) $user['id'], 'verify', (string) $this->request->input('code', ''));
        if ($result === 'ok') {
            $this->s->users()->markEmailVerified((int) $user['id']);
            $this->app->logger()->info('security', 'Email address verified', ['username' => $user['username']]);
            $this->flash('success', 'Your email address is verified.');
        } else {
            $this->flash('danger', AccountCodes::message($result));
        }
        return $this->redirect('/account#profile');
    }

    public function notifications(): Response
    {
        $user = (array) $this->auth->user();
        $this->s->users()->setPreferences((int) $user['id'], ['email_notifications' => $this->request->input('email_notifications') === '1' ? '1' : '0']);
        $this->flash('success', 'Notification settings saved.');
        return $this->redirect('/account#notifications');
    }

    public function password(): Response
    {
        $user = (array) $this->auth->user();
        $current = (string) $this->request->input('current_password', '');
        $new = (string) $this->request->input('new_password', '');
        $repeat = (string) $this->request->input('repeat_password', '');
        if (!password_verify($current, (string) $user['password_hash'])) {
            $this->app->logger()->warning('security', 'Password change rejected: wrong current password', ['username' => $user['username']]);
            $this->flash('danger', 'The current password is incorrect.');
        } elseif (strlen($new) < 12) {
            $this->flash('danger', 'The new password must be at least 12 characters.');
        } elseif (!hash_equals($new, $repeat)) {
            $this->flash('danger', 'The new passwords do not match.');
        } else {
            $this->s->users()->setPassword((int) $user['id'], $new);
            $this->session->regenerate();
            $this->app->logger()->info('security', 'Password changed', ['username' => $user['username']]);
            $this->flash('success', 'Password changed.');
        }
        return $this->redirect('/account#password');
    }

    public function time(): Response
    {
        $user = (array) $this->auth->user();
        $mine = (string) $this->request->input('my_timezone', '');
        $this->s->users()->setPreferences((int) $user['id'], [
            'timezone' => $mine !== '' && SystemSettings::validTimezone($mine) ? $mine : '',
        ]);
        $this->flash('success', 'Timezone saved.');
        return $this->redirect('/account#time');
    }
}
