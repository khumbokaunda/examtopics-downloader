<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;

/**
 * The Sign-in attempts page (security.manage): whether any attempt got in, which accounts were
 * tried and whether they are locked, which made-up names were tried, and which networks the
 * attempts came from. Accounts can be unlocked, networks unblocked, and a flagged sign-in
 * cleared ("It was them") or flagged again. Every action is recorded in the activity history.
 */
final class SignInsController extends Controller
{
    public const PERIODS = ['24h' => [86400, 'Last 24 hours'], '7d' => [604800, 'Last 7 days'], '30d' => [2592000, 'Last 30 days']];

    public function index(): Response
    {
        $period = (string) $this->request->query('period', '7d');
        $period = isset(self::PERIODS[$period]) ? $period : '7d';
        $guard = $this->s->signInGuard();
        return $this->render('security/sign_ins', [
            'title' => 'Sign-in attempts',
            'nav' => 'sign-ins',
            'period' => $period,
            'report' => $guard->report(self::PERIODS[$period][0]),
            'limit' => $guard->lockoutAttempts(),
        ]);
    }

    public function unlock(): Response
    {
        $account = $this->s->users()->findByUsername((string) $this->request->input('username', '')) ?? throw HttpException::notFound('Account');
        $this->flash('success', $this->s->signInGuard()->unlock($account)
            ? 'Unlocked ' . $account['username'] . '. They can sign in again now.'
            : $account['username'] . ' was not locked.');
        if ($this->request->input('return') === 'user') {
            return $this->redirect('/users/' . rawurlencode((string) $account['username']));
        }
        return $this->back();
    }

    public function unblock(): Response
    {
        $ip = (string) $this->request->input('ip', '');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw HttpException::notFound('Network');
        }
        $this->flash('success', $this->s->signInGuard()->unblock($ip)
            ? 'Sign-ins from ' . $ip . ' are allowed again, and its earlier failures no longer count.'
            : $ip . ' was not blocked.');
        return $this->back();
    }

    public function clearFlag(string $id): Response
    {
        $this->s->signInGuard()->clearFlag($this->attemptId($id), (array) $this->auth->user());
        $this->flash('success', 'Marked as theirs. It stays listed as cleared, and can be flagged again.');
        return $this->back();
    }

    public function restoreFlag(string $id): Response
    {
        $this->s->signInGuard()->restoreFlag($this->attemptId($id));
        $this->flash('success', 'Flagged again.');
        return $this->back();
    }

    public function clearAll(): Response
    {
        $guard = $this->s->signInGuard();
        $n = 0;
        foreach ($guard->flagged(gmdate('Y-m-d H:i:s', time() - self::PERIODS['30d'][0])) as $f) {
            if ($f['cleared_at'] === null) {
                $guard->clearFlag((int) $f['id'], (array) $this->auth->user());
                $n++;
            }
        }
        $this->flash('success', $n === 0 ? 'Nothing was flagged.' : 'Cleared ' . $n . ' flagged sign-in' . ($n === 1 ? '' : 's') . '.');
        return $this->back();
    }

    private function attemptId(string $id): int
    {
        if (!ctype_digit($id) || $this->app->db()->value('SELECT id FROM login_attempts WHERE id = ? AND succeeded = 1', [(int) $id]) === null) {
            throw HttpException::notFound('Sign-in');
        }
        return (int) $id;
    }

    protected function back(string $fallback = '/security/sign-ins'): Response
    {
        $period = (string) $this->request->input('period', '');
        return $this->redirect('/security/sign-ins', isset(self::PERIODS[$period]) ? ['period' => $period] : []);
    }
}
