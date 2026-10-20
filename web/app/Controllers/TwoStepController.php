<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Access;
use App\Services\SignInGuard;
use App\Services\TwoStep;

/**
 * Two-step sign-in: the code page after a right password, setting it up and turning it off on
 * the account page, recovery codes, an administrator turning it off for someone who lost their
 * phone and their codes, and "Confirm it's you" before backups and deleting accounts.
 *
 * Wrong codes are limited twice: five per sign-in (then the password again), and ten per
 * account in fifteen minutes. Reaching the second means someone has the password, so the
 * owner and the reviewers are told.
 */
final class TwoStepController extends Controller
{
    private const MAX_PER_SIGN_IN = 5;
    private const MAX_PER_ACCOUNT = 10;
    private const WINDOW = 900;

    // ---- The second step of signing in -----------------------------------------------------

    public function show(): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/');
        }
        if ($this->auth->pendingSecondStep() === null) {
            return $this->redirect('/login');
        }
        return $this->codePage(null);
    }

    public function verify(): Response
    {
        $user = $this->auth->pendingSecondStep();
        if ($user === null) {
            $this->auth->cancelSecondStep();
            $this->flash('warning', 'That sign-in took too long or was cancelled. Type your password again.');
            return $this->redirect('/login');
        }
        $id = (int) $user['id'];
        $throttle = $this->s->throttle();
        if ($throttle->count('two_step', (string) $id, self::WINDOW) >= self::MAX_PER_ACCOUNT) {
            $this->auth->cancelSecondStep();
            return $this->codePage('Too many wrong codes for this account. Try again in 15 minutes.', 429, true);
        }
        $result = $this->s->twoStep()->check($id, (string) $this->request->input('code', ''));
        if ($result === 'app' || $result === 'recovery') {
            $next = (string) ($this->session->get('two_step_next') ?: '/');
            $this->session->remove('two_step_next');
            $this->auth->finishSecondStep($user, $this->ip());
            if ($result === 'recovery') {
                $left = $this->s->twoStep()->codesLeft($id);
                $this->s->audit()->record('auth.recovery_code_used', ['left' => $left], 'user', (string) $user['username'], $user);
                $this->s->notifier()->notify($user, 'security', 'A recovery code was used to sign in',
                    'Someone signed in to your account with one of your recovery codes. ' . $left . ' are left. If it was not you, change your password and make new recovery codes now.',
                    '/account#two-step', null, 'recovery-used:' . $id . ':' . time());
                $this->flash('warning', 'You signed in with a recovery code; ' . $left . ' left. If your phone is lost, turn two-step sign-in off and on again with a new one, or make new codes, on your account page.');
            }
            return $this->redirect($next);
        }

        $throttle->hit('two_step', (string) $id, PHP_INT_MAX, self::WINDOW);
        $this->s->signInGuard()->failed($this->ip(), $user, (string) $user['username'], SignInGuard::WRONG_CODE);
        $this->s->audit()->record('auth.two_step_failed', [], 'user', (string) $user['username']);
        if ($throttle->count('two_step', (string) $id, self::WINDOW) === self::MAX_PER_ACCOUNT) {
            $this->warnPasswordKnown($user);
        }
        if ($this->auth->secondStepFailed() >= self::MAX_PER_SIGN_IN) {
            $this->auth->cancelSecondStep();
            $this->flash('warning', 'Five wrong codes. Type your password again, then the newest code from your app.');
            return $this->redirect('/login');
        }
        return $this->codePage($result === 'used'
            ? 'That code was already used. Wait for the next one in your app.'
            : 'That code is not right. Type the six digits your app shows now, or one of your recovery codes.', 422);
    }

    public function cancel(): Response
    {
        $this->auth->cancelSecondStep();
        return $this->redirect('/login');
    }

    private function codePage(?string $error, int $status = 200, bool $restart = false): Response
    {
        return $this->render('auth/two_step', ['title' => 'Two-step sign-in', 'error' => $error, 'restart' => $restart], $status);
    }

    /** @param array<string,mixed> $user */
    private function warnPasswordKnown(array $user): void
    {
        $this->app->logger()->warning('security', 'Many wrong two-step codes after a right password', ['username' => $user['username'], 'ip' => $this->ip()]);
        $this->s->notifier()->notify($user, 'security', 'Someone may know your password',
            'The right password for your account was typed, then ' . self::MAX_PER_ACCOUNT . ' wrong two-step codes, from ' . $this->ip()
            . '. Your account is safe while they lack your phone, but change your password now.', '/account#password', null, 'password-known:' . $user['id'] . ':' . time());
        $this->s->notifier()->notifyPermission('security.manage', 'security', 'Possible stolen password: ' . Access::displayName($user),
            'The right password for ' . $user['username'] . ' was typed, then ' . self::MAX_PER_ACCOUNT . ' wrong two-step codes, from ' . $this->ip() . '.',
            '/security/sign-ins', null, 'password-known-review:' . $user['id'] . ':' . time(), (int) $user['id']);
    }

    // ---- Setting it up on the account page -------------------------------------------------

    public function begin(): Response
    {
        $user = (array) $this->auth->user();
        if (!$this->s->twoStep()->enabled((int) $user['id'])) {
            $this->s->twoStep()->begin((int) $user['id']);
        }
        return $this->redirect('/account#two-step');
    }

    public function confirmSetup(): Response
    {
        $user = (array) $this->auth->user();
        $codes = $this->s->twoStep()->confirm((int) $user['id'], (string) $this->request->input('code', ''));
        if ($codes === null) {
            $this->flash('danger', 'That code is not right. Type the six digits your app shows for this account now.');
            return $this->redirect('/account#two-step');
        }
        $this->s->audit()->record('security.two_step_enabled', [], 'user', (string) $user['username']);
        $this->s->notifier()->notify($user, 'security', 'Two-step sign-in is on',
            'Two-step sign-in was turned on for your account. Signing in now needs the code from your authenticator app as well as your password.',
            '/account#two-step', null, 'two-step-on:' . $user['id'] . ':' . time());
        $this->session->set('recovery_codes', $codes);
        return $this->redirect('/account/two-step/codes');
    }

    public function cancelSetup(): Response
    {
        $this->s->twoStep()->cancel((int) $this->auth->user()['id']);
        return $this->redirect('/account#two-step');
    }

    /** The recovery codes, shown once, after turning it on or making new ones. */
    public function codes(): Response
    {
        $codes = $this->session->get('recovery_codes');
        if (!is_array($codes) || $codes === []) {
            return $this->redirect('/account#two-step');
        }
        return $this->render('account/recovery_codes', ['title' => 'Recovery codes', 'nav' => 'account', 'codes' => $codes]);
    }

    public function codesText(): Response
    {
        $codes = $this->session->get('recovery_codes');
        if (!is_array($codes) || $codes === []) {
            throw HttpException::notFound('Recovery codes');
        }
        $user = (array) $this->auth->user();
        $name = $this->s->branding()->get()['name'];
        $text = 'Recovery codes for ' . $user['username'] . ' at ' . $name . "\r\n"
            . 'Made ' . gmdate('Y-m-d H:i') . " UTC. Each works once, instead of a code from your app.\r\n\r\n"
            . implode("\r\n", $codes) . "\r\n";
        return new Response($text, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="recovery-codes-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $user['username']) . '.txt"',
        ]);
    }

    public function codesDone(): Response
    {
        $this->session->remove('recovery_codes');
        $this->flash('success', 'Two-step sign-in is on. Keep the recovery codes somewhere safe, away from your phone.');
        return $this->redirect('/account#two-step');
    }

    public function newCodes(): Response
    {
        $user = (array) $this->auth->user();
        if ($this->s->twoStep()->check((int) $user['id'], (string) $this->request->input('code', '')) !== 'app') {
            $this->flash('danger', 'That code is not right. Type the six digits your app shows now.');
            return $this->redirect('/account#two-step');
        }
        $codes = $this->s->twoStep()->newCodes((int) $user['id']);
        $this->s->audit()->record('security.recovery_codes_made', [], 'user', (string) $user['username']);
        $this->session->set('recovery_codes', $codes);
        return $this->redirect('/account/two-step/codes');
    }

    public function off(): Response
    {
        $user = (array) $this->auth->user();
        if (!in_array($this->s->twoStep()->check((int) $user['id'], (string) $this->request->input('code', '')), ['app', 'recovery'], true)) {
            $this->flash('danger', 'That code is not right. Type the six digits your app shows now, or a recovery code.');
            return $this->redirect('/account#two-step');
        }
        $this->turnedOff($user, 'self');
        $this->flash('success', 'Two-step sign-in is off. Signing in needs only your password now.');
        return $this->redirect('/account#two-step');
    }

    /** An administrator turns it off for someone who lost their phone and their recovery codes. */
    public function offFor(string $username): Response
    {
        $account = $this->s->users()->findByUsername($username) ?? throw HttpException::notFound('Account');
        if ($r = $this->needsConfirmation('/users/' . rawurlencode($username))) {
            return $r;
        }
        if ((int) $account['id'] === (int) $this->auth->user()['id']) {
            throw new HttpException(403, 'Turn off your own two-step sign-in on your account page.');
        }
        if ($this->s->twoStep()->enabled((int) $account['id'])) {
            $this->turnedOff($account, 'administrator');
            $this->flash('success', 'Two-step sign-in is off for ' . $account['username'] . '. They have been told by email, and can turn it on again from their account page.');
        }
        return $this->redirect('/users/' . rawurlencode($username));
    }

    /** @param array<string,mixed> $account */
    private function turnedOff(array $account, string $by): void
    {
        $this->s->twoStep()->disable((int) $account['id']);
        $this->s->audit()->record('security.two_step_disabled', ['username' => (string) $account['username'], 'by' => $by], 'user', (string) $account['username']);
        $this->s->notifier()->notify($account, 'security', 'Two-step sign-in is off',
            ($by === 'self' ? 'You turned off' : 'An administrator turned off') . ' two-step sign-in for your account. Signing in needs only your password now. '
            . 'If you did not expect this, change your password and turn it on again.', '/account#two-step', null, 'two-step-off:' . $account['id'] . ':' . time());
    }

    // ---- Confirm it's you ------------------------------------------------------------------

    public function confirmPage(): Response
    {
        return $this->render('auth/confirm', [
            'title' => 'Confirm it is you',
            'nav' => '',
            'next' => self::localPath($this->request->query('next')),
            'withCode' => $this->s->twoStep()->enabled((int) $this->auth->user()['id']),
            'error' => null,
            'minutes' => $this->confirmMinutes(),
        ]);
    }

    public function confirm(): Response
    {
        $user = (array) $this->auth->user();
        $id = (int) $user['id'];
        $next = self::localPath($this->request->input('next'));
        $withCode = $this->s->twoStep()->enabled($id);
        $throttle = $this->s->throttle();
        $page = fn(string $error, int $status) => $this->render('auth/confirm',
            ['title' => 'Confirm it is you', 'nav' => '', 'next' => $next, 'withCode' => $withCode, 'error' => $error, 'minutes' => $this->confirmMinutes()], $status);
        if ($throttle->count('confirm', (string) $id, self::WINDOW) >= self::MAX_PER_ACCOUNT) {
            return $page('Too many wrong tries. Wait 15 minutes, then try again.', 429);
        }
        $ok = $withCode
            ? in_array($this->s->twoStep()->check($id, (string) $this->request->input('code', '')), ['app', 'recovery'], true)
            : password_verify((string) $this->request->input('password', ''), (string) $user['password_hash']);
        if (!$ok) {
            $throttle->hit('confirm', (string) $id, PHP_INT_MAX, self::WINDOW);
            $this->s->audit()->record('security.confirm_failed', ['for' => $next]);
            return $page($withCode ? 'That code is not right, or was already used. Type the newest code from your app.' : 'That password is not right.', 422);
        }
        $this->session->set('_confirmed', ['user' => $id, 'until' => time() + $this->confirmMinutes() * 60]);
        $this->s->audit()->record('security.confirmed', ['for' => $next, 'with' => $withCode ? 'two-step code' : 'password']);
        return $this->redirect($next);
    }

    private function confirmMinutes(): int
    {
        return max(1, (int) $this->app->config()->get('security.confirm_minutes', 10));
    }

    /** A path inside this site to go back to, or the dashboard. */
    private static function localPath(?string $path): string
    {
        return $path !== null && preg_match('#^/(?!/)[A-Za-z0-9/_.\-]*$#', $path) === 1 ? $path : '/';
    }
}
