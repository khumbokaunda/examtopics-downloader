<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Repositories\UserRepository;
use App\Services\Access;
use App\Services\AccountCodes;

/**
 * Signing in and out, creating an account (verified by an emailed code) and resetting a
 * forgotten password (with an emailed code). The forgotten-password answer is the same
 * whether or not the address belongs to an account.
 */
final class AuthController extends Controller
{
    public function showLogin(): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/');
        }
        $next = $this->safeNext($this->request->query('next'));
        if (preg_match('#^/(enrol|invitations/)#', $next) === 1) {
            $this->session->set(self::THROUGH_INSTITUTION, true); // sent here by an enrolment code or an invitation
        }
        return $this->loginPage($next, null, '');
    }

    public function login(): Response
    {
        $username = trim((string) $this->request->input('username', ''));
        $password = (string) $this->request->input('password', '');
        $next = $this->safeNext($this->request->input('next'));
        $error = $this->auth->attempt($username, $password, $this->ip());
        if ($error !== null && $this->auth->unverifiedId() !== null) {
            // Signed up but never typed the code: send a fresh one and ask for it.
            $user = (array) $this->s->users()->find((int) $this->auth->unverifiedId());
            $this->session->set('pending_user_id', (int) $user['id']);
            $sendError = $this->s->codes()->send($user, 'verify', (string) $user['email']);
            $this->flash('info', 'Your email address is not verified yet. ' . ($sendError ?? 'We sent a new code to ' . $user['email'] . '.'));
            return $this->redirect('/verify');
        }
        if ($error !== null) {
            return $this->loginPage($next, $error, $username, 401);
        }
        if ($this->auth->awaitingCode()) {
            $this->session->set('two_step_next', $next);
            return $this->redirect('/login/two-step');
        }
        return $this->redirect($next);
    }

    public function logout(): Response
    {
        $this->auth->logout($this->ip());
        return $this->redirect('/login');
    }

    private function loginPage(string $next, ?string $error, string $username, int $status = 200): Response
    {
        $mailer = $this->s->mailer();
        return $this->render('auth/login', [
            'title' => 'Sign in', 'next' => $next, 'error' => $error, 'username' => $username,
            'noUsers' => $this->s->users()->count() === 0,
            'canRegister' => $this->s->registration()->available($mailer),
            'canReset' => $mailer->configured(),
            'restored' => $this->request->query('restored') === '1',
        ], $status);
    }

    // ---- Creating an account ---------------------------------------------------------------

    public function showRegister(): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/');
        }
        return $this->registerPage(['display_name' => '', 'username' => '', 'email' => ''], null);
    }

    public function register(): Response
    {
        if (!$this->s->registration()->available($this->s->mailer())) {
            return $this->registerPage(['display_name' => '', 'username' => '', 'email' => ''], null, 403);
        }
        [$form, $error, $status] = $this->createAccount();
        if ($error !== null) {
            return $this->registerPage($form, $error, $status);
        }
        $this->flash('success', 'We sent a six-digit code to ' . $form['email'] . '. Type it below to finish.');
        return $this->redirect('/verify');
    }

    /**
     * Registering an institution for someone without an account: the account and the institution
     * in one form. The institution is kept in this browser's session and registered only once the
     * email address is verified, so an address nobody confirms never leaves an institution behind.
     */
    public function showJoin(): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/institutions/register');
        }
        $profile = array_fill_keys(\App\Repositories\InstitutionRepository::PROFILE, '');
        return $this->joinPage(['display_name' => '', 'username' => '', 'email' => ''], $profile, [], null);
    }

    public function join(): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/institutions/register');
        }
        $req = $this->s->onboarding()->requirements();
        $hidden = $req->hiddenColumns();
        $in = [];
        foreach (\App\Repositories\InstitutionRepository::PROFILE as $f) {
            $in[$f] = in_array($f, $hidden, true) ? null : $this->request->input('inst_' . $f);
        }
        [$profile, $errors] = \App\Services\Institutions::validate($in);
        $posted = is_array($this->request->post['inst_custom'] ?? null) ? $this->request->post['inst_custom'] : [];
        [$custom, $customErrors] = $req->check((string) $profile['type'], $posted);
        foreach ($customErrors as $uuid => $msg) {
            $errors['custom.' . $uuid] = $msg;
        }
        $account = ['display_name' => trim((string) $this->request->input('display_name', '')), 'username' => trim((string) $this->request->input('username', '')),
            'email' => UserRepository::normalizeEmail((string) $this->request->input('email', ''))];
        if (!$this->s->registration()->available($this->s->mailer())) {
            return $this->joinPage($account, $profile, [], null, 403, $posted);
        }
        if ($errors !== []) {
            return $this->joinPage($account, $profile, $errors, null, 422, $posted);
        }
        [$account, $error, $status] = $this->createAccount(true); // registering an institution: an institution account
        if ($error !== null) {
            return $this->joinPage($account, $profile, [], $error, $status, $posted);
        }
        $this->session->set('pending_institution', ['user' => (int) $this->session->get('pending_user_id'), 'profile' => $profile, 'custom' => $custom]);
        $this->flash('success', 'We sent a six-digit code to ' . $account['email'] . '. Type it below: ' . $profile['name'] . ' is registered as soon as your address is confirmed.');
        return $this->redirect('/verify');
    }

    /** Session key: this browser came to sign-up from an invitation or an enrolment code. */
    public const THROUGH_INSTITUTION = 'signup_through_institution';

    /**
     * Whether the person signing up is here because of an institution: they followed an
     * invitation or an enrolment code, or an invitation (a student import sends those) is
     * waiting for their address.
     */
    private function throughInstitution(string $email): bool
    {
        if ($this->session->get(self::THROUGH_INSTITUTION) === true) {
            return true;
        }
        return $email !== '' && $this->app->db()->value('SELECT 1 FROM institution_invitations WHERE email = ? AND accepted_at IS NULL AND declined_at IS NULL
            AND revoked_at IS NULL AND expires_at > ? LIMIT 1', [$email, \App\Core\Database::now()]) !== null;
    }

    /**
     * Check the posted account fields and create the account, unverified, with a code emailed to
     * it. The new account waits in the session for its code.
     *
     * @return array{0:array{display_name:string,username:string,email:string},1:?string,2:int} the form, an error, and the status for it
     */
    private function createAccount(bool $throughInstitution = false): array
    {
        $form = [
            'display_name' => trim((string) $this->request->input('display_name', '')),
            'username' => trim((string) $this->request->input('username', '')),
            'email' => UserRepository::normalizeEmail((string) $this->request->input('email', '')),
        ];
        $password = (string) $this->request->input('password', '');
        $users = $this->s->users();
        $error = match (true) {
            $form['display_name'] === '' || mb_strlen($form['display_name']) > 128 => 'Type your name (up to 128 characters).',
            !UserRepository::validUsername($form['username']) => 'Usernames are 3 to 64 letters, digits, dots, hyphens or underscores.',
            !UserRepository::validEmail($form['email']) => 'That does not look like an email address.',
            strlen($password) < 12 => 'Choose a password of at least 12 characters.',
            !hash_equals($password, (string) $this->request->input('repeat_password', '')) => 'The passwords do not match.',
            $users->findByUsername($form['username']) !== null => 'That username is taken. Choose another.',
            $users->emailTaken($form['email']) => 'An account already uses that email address. Sign in, or reset your password from the sign-in page.',
            !$this->s->throttle()->hit('signup', $this->ip(), 5, 3600) => 'Too many accounts were created from this network in the last hour. Try again later.',
            default => null,
        };
        if ($error !== null) {
            return [$form, $error, 422];
        }
        $id = $users->create($form['username'], $password, Access::MEMBER, [
            'display_name' => $form['display_name'], 'email' => $form['email'], 'status' => 'unverified',
            'library_access' => \App\Services\Entitlements::libraryForNewAccount($throughInstitution || $this->throughInstitution($form['email'])),
        ]);
        $this->session->remove(self::THROUGH_INSTITUTION);
        $user = (array) $users->find($id);
        $sendError = $this->s->codes()->send($user, 'verify', $form['email']);
        if ($sendError !== null) {
            $users->delete($id); // nothing to verify with, so no half-made account is left
            return [$form, $sendError, 503];
        }
        $this->app->logger()->info('security', 'Account created by sign-up', ['username' => $form['username'], 'ip' => $this->ip()]);
        $this->s->audit()->record('auth.signed_up', ['email' => $form['email']], 'user', $form['username'], $user);
        $this->session->set('pending_user_id', $id);
        $this->s->policies()->accept($id, ['account_notice'], 'account', $id, $this->request->ip());
        return [$form, null, 200];
    }

    /** @param array<string,string> $account @param array<string,?string> $profile @param array<string,string> $errors @param array<string,mixed>|null $custom */
    private function joinPage(array $account, array $profile, array $errors, ?string $error, int $status = 200, ?array $custom = null): Response
    {
        $registration = $this->s->registration();
        return $this->render('auth/join', ['title' => 'Register your institution', 'wide' => true, 'account' => $account, 'profile' => $profile,
            'extras' => $this->s->onboarding()->requirements()->form(null, $custom),
            'errors' => $errors, 'error' => $error, 'available' => $registration->available($this->s->mailer()),
            'approval' => $registration->mode() === 'approval'], $status);
    }

    /** @param array{display_name:string,username:string,email:string} $form */
    private function registerPage(array $form, ?string $error, int $status = 200): Response
    {
        $registration = $this->s->registration();
        return $this->render('auth/register', [
            'title' => 'Create an account', 'form' => $form, 'error' => $error,
            'available' => $registration->available($this->s->mailer()),
            'approval' => $registration->mode() === 'approval',
        ], $status);
    }

    public function showVerify(): Response
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return $this->redirect('/login');
        }
        return $this->render('auth/verify', ['title' => 'Verify your email', 'email' => (string) $user['email'], 'error' => null]);
    }

    public function verify(): Response
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return $this->redirect('/login');
        }
        $result = $this->s->codes()->check((int) $user['id'], 'verify', (string) $this->request->input('code', ''));
        if ($result !== 'ok') {
            return $this->render('auth/verify', ['title' => 'Verify your email', 'email' => (string) $user['email'],
                'error' => AccountCodes::message($result)], 422);
        }
        $this->session->remove('pending_user_id');
        $this->s->audit()->record('auth.email_verified', ['email' => (string) $user['email']], 'user', (string) $user['username'], $user);
        $status = $this->s->registration()->verified($user);
        $institution = $this->registerJoined($user);
        if ($status === 'active') {
            $this->auth->signIn((array) $this->s->users()->find((int) $user['id']), $this->ip());
            $this->flash('success', 'Your email address is verified. Welcome, ' . Access::displayName($user) . '.');
            if ($institution !== null) {
                return $this->redirect('/institutions/' . $institution['uuid']);
            }
            return $this->redirect('/');
        }
        $this->flash('success', 'Email verified. An administrator will approve your account.');
        return $this->redirect('/login');
    }

    /**
     * The institution filled in on the join page by this account, registered now its address is
     * confirmed. @param array<string,mixed> $user @return array<string,mixed>|null
     */
    private function registerJoined(array $user): ?array
    {
        $pending = $this->session->get('pending_institution');
        $this->session->remove('pending_institution');
        if (!is_array($pending) || (int) ($pending['user'] ?? 0) !== (int) $user['id'] || !is_array($pending['profile'] ?? null)) {
            return null;
        }
        try {
            $institution = $this->s->institutions()->register($pending['profile'], (array) $this->s->users()->find((int) $user['id']));
            $this->s->onboarding()->requirements()->store((int) $institution['id'], is_array($pending['custom'] ?? null) ? $pending['custom'] : []);
            $this->flash('success', $institution['name'] . ' is registered and waits for verification by the platform. You are its owner.');
            return $institution;
        } catch (\RuntimeException $e) {
            $this->flash('warning', 'Your account is ready, but the institution could not be registered: ' . $e->getMessage());
            return null;
        }
    }

    public function resendVerify(): Response
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return $this->redirect('/login');
        }
        $error = $this->s->codes()->send($user, 'verify', (string) $user['email']);
        $this->flash($error === null ? 'success' : 'warning', $error ?? 'A new code is on its way to ' . $user['email'] . '.');
        return $this->redirect('/verify');
    }

    /** The account that signed up (or tried to sign in) in this browser and still needs its code. @return array<string,mixed>|null */
    private function pendingUser(): ?array
    {
        $id = $this->session->get('pending_user_id');
        $user = is_int($id) ? $this->s->users()->find($id) : null;
        return $user !== null && $user['status'] === 'unverified' && $user['email'] !== null ? $user : null;
    }

    // ---- A forgotten password ------------------------------------------------------------

    public function showForgot(): Response
    {
        return $this->render('auth/forgot', ['title' => 'Forgot your password', 'error' => null, 'available' => $this->s->mailer()->configured()]);
    }

    public function forgot(): Response
    {
        $email = UserRepository::normalizeEmail((string) $this->request->input('email', ''));
        if (!UserRepository::validEmail($email)) {
            return $this->render('auth/forgot', ['title' => 'Forgot your password', 'error' => 'Type the email address of your account.', 'available' => true], 422);
        }
        if (!$this->s->throttle()->hit('reset', $this->ip(), 5, 3600)) {
            return $this->render('auth/forgot', ['title' => 'Forgot your password',
                'error' => 'Too many reset requests from this network. Try again later.', 'available' => true], 429);
        }
        try {
            $user = $this->s->users()->findByEmail($email);
            if ($user !== null && $user['status'] === 'active') {
                $error = $this->s->codes()->send($user, 'reset', $email);
                $this->s->audit()->record('auth.reset_requested', $error !== null ? ['not_sent' => $error] : [], 'user', (string) $user['username'], $user);
                if ($error !== null) {
                    $this->app->logger()->warning('security', 'Reset code not sent', ['username' => $user['username'], 'reason' => $error]);
                }
            } else {
                $this->app->logger()->info('security', 'Reset requested for no active account', ['ip' => $this->ip()]);
            }
        } catch (\Throwable $e) {
            $this->app->logger()->error('security', 'Reset request failed', ['error' => $e->getMessage()]);
        }
        // The same answer whatever happened, so the page cannot tell anyone which addresses exist.
        $this->session->set('reset_email', $email);
        $this->flash('info', 'If that address belongs to an account, we emailed a six-digit code to it. It works for 30 minutes.');
        return $this->redirect('/reset');
    }

    public function showReset(): Response
    {
        $email = $this->session->get('reset_email');
        if (!is_string($email)) {
            return $this->redirect('/forgot');
        }
        return $this->render('auth/reset', ['title' => 'Set a new password', 'email' => $email, 'error' => null]);
    }

    public function reset(): Response
    {
        $email = $this->session->get('reset_email');
        if (!is_string($email)) {
            return $this->redirect('/forgot');
        }
        $password = (string) $this->request->input('password', '');
        $page = fn(string $error, int $status = 422): Response => $this->render('auth/reset', ['title' => 'Set a new password', 'email' => $email, 'error' => $error], $status);
        if (strlen($password) < 12) {
            return $page('Choose a password of at least 12 characters.');
        }
        if (!hash_equals($password, (string) $this->request->input('repeat_password', ''))) {
            return $page('The passwords do not match.');
        }
        $user = $this->s->users()->findByEmail($email);
        $result = $user !== null && $user['status'] === 'active'
            ? $this->s->codes()->check((int) $user['id'], 'reset', (string) $this->request->input('code', ''))
            : 'wrong';
        if ($result !== 'ok') {
            return $page(AccountCodes::message($result));
        }
        $this->s->users()->setPassword((int) $user['id'], $password);
        $this->s->users()->markEmailVerified((int) $user['id']); // the code proved the address
        $this->session->remove('reset_email');
        $this->app->logger()->info('security', 'Password reset with an emailed code', ['username' => $user['username'], 'ip' => $this->ip()]);
        $this->s->audit()->record('auth.password_reset', [], 'user', (string) $user['username'], $user);
        $this->flash('success', 'Your password is changed. Sign in with the new one.');
        return $this->redirect('/login');
    }

    /** Only same-application paths are accepted as a post-login destination. */
    private function safeNext(?string $next): string
    {
        if ($next === null || preg_match('#^/(?!/)[A-Za-z0-9/_.\-]*$#', $next) !== 1 || str_starts_with($next, '/login')) {
            return '/';
        }
        return $next;
    }
}
