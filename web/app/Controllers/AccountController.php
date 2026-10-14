<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Repositories\UserRepository;
use App\Services\Appearance;
use App\Services\SystemSettings;

/** The signed-in person's own account: profile, password, timezone and appearance. */
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
            if ($email !== (string) ($user['email'] ?? '')) {
                $this->app->logger()->info('security', 'Email address changed', ['username' => $user['username']]);
            }
            $this->auth->refresh();
            $this->flash('success', 'Profile saved.');
        }
        return $this->redirect('/account');
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
