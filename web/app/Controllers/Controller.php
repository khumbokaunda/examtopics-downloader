<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Ids;
use App\Core\Session;
use App\Core\View;
use App\Services\Auth;
use App\Services\Services;

abstract class Controller
{
    public function __construct(
        protected App $app,
        protected Services $s,
        protected Session $session,
        protected Auth $auth,
        protected Request $request,
    ) {
    }

    /** The signed-in person's timezone: theirs if they chose one, else the system's. */
    protected function timezone(): string
    {
        return $this->app->service(\App\Services\SystemSettings::class, fn() => new \App\Services\SystemSettings($this->app))
            ->timezoneFor($this->auth->user());
    }

    /** Today's date (Y-m-d) where the signed-in person is. */
    protected function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone())))->format('Y-m-d');
    }

    /** @param array<string,mixed> $vars */
    protected function render(string $template, array $vars = [], int $status = 200): Response
    {
        $view = new View($this->app, $this->session, $this->auth->user());
        return Response::html($view->render($template, $vars + ['nav' => '', 'title' => '']), $status);
    }

    /** @param array<string,string|int> $query */
    protected function redirect(string $path, array $query = []): Response
    {
        return Response::redirect(View::buildUrl((string) $this->app->config()->get('base_path', ''), $path, $query));
    }

    protected function back(string $fallback): Response
    {
        return $this->redirect($fallback);
    }

    /**
     * Whether the signed-in person confirmed it is them (their two-step code, or their password
     * when two-step sign-in is off) in the last security.confirm_minutes (10). Backups and
     * deleting accounts ask for it, so a session left open is not enough on its own.
     */
    protected function confirmed(): bool
    {
        $c = $this->session->get('_confirmed');
        return is_array($c) && (int) ($c['user'] ?? 0) === (int) ($this->auth->user()['id'] ?? -1) && (int) ($c['until'] ?? 0) > time();
    }

    /** Seconds the confirmation has left (0 when there is none). */
    protected function confirmedFor(): int
    {
        $c = $this->session->get('_confirmed');
        return $this->confirmed() ? max(0, (int) $c['until'] - time()) : 0;
    }

    /** Before a sensitive action: null when confirmed, else the way to the "Confirm it's you" page. */
    protected function needsConfirmation(string $back): ?Response
    {
        if ($this->confirmed()) {
            return null;
        }
        if ($this->request->wantsJson()) {
            return Response::json(['error' => 'Confirm it is you first: reload the page and use the Confirm button.'], 403);
        }
        $this->flash('info', 'Confirm it is you first, then do it again.');
        return $this->redirect('/confirm', ['next' => $back]);
    }

    protected function flash(string $type, string $message): void
    {
        $this->session->flash($type, $message);
    }

    /**
     * The answer to a form that starts a job. The page's script asks for JSON and shows the job
     * in place of the button, so several can be started without leaving the page; a plain form
     * goes back to $back with the message. $job is the job started, or the one already holding
     * its place when $ok is false.
     *
     * @param array<string,mixed>|null $job @param array<string,string> $query @param array<string,mixed> $extra
     */
    protected function jobStarted(?array $job, bool $ok, string $message, string $back, array $query = [], array $extra = []): Response
    {
        if ($this->request->wantsJson()) {
            $base = (string) $this->app->config()->get('base_path', '');
            $data = ['ok' => $ok, 'message' => $message, 'tone' => $ok ? 'success' : 'warning'] + $extra;
            if ($job !== null) {
                $data['job'] = JobController::present($job);
                $data['status_url'] = View::buildUrl($base, '/api/jobs/' . $job['uuid']);
                $data['job_url'] = $this->auth->can('exams.collect') ? View::buildUrl($base, '/jobs/' . $job['uuid']) : null;
            }
            return Response::json($data, $ok ? 200 : ($job !== null ? 409 : 422));
        }
        $this->flash($ok ? 'success' : (string) ($extra['tone'] ?? 'warning'), $message);
        return $this->redirect($back, $query);
    }

    /**
     * The page a form says to return to, when it is a page of this site under one of $prefixes;
     * otherwise $fallback. Never another site ("//host", "https://").
     */
    protected function returnTo(string $fallback, string ...$prefixes): string
    {
        $back = (string) $this->request->input('back', '');
        if (preg_match('#^/(?![/\\\\])[^\s]*$#', $back) !== 1) {
            return $fallback;
        }
        foreach ($prefixes as $prefix) {
            if ($back === $prefix || str_starts_with($back, $prefix . '/') || str_starts_with($back, $prefix . '?')) {
                return $back;
            }
        }
        return $fallback;
    }

    /**
     * An account from its address: the public id in /users/<id> and /progress/people/<id>, or,
     * for links made before accounts had ids, its username.
     *
     * @return array<string,mixed>
     */
    protected function findAccount(string $ref): array
    {
        $users = $this->s->users();
        $account = Ids::isUuid($ref) ? $users->findByUuid($ref)
            : (\App\Repositories\UserRepository::validUsername($ref) ? $users->findByUsername($ref) : null);
        return $account ?? throw HttpException::notFound('Account');
    }

    /** Where an account's page is: by its public id, never its username. @param array<string,mixed> $account */
    protected static function accountPath(array $account, string $prefix = '/users/'): string
    {
        return $prefix . rawurlencode((string) ($account['uuid'] ?? $account['username']));
    }

    /** @return array<string,mixed> */
    protected function findProvider(string $slug): array
    {
        if (!Ids::isProvider($slug)) {
            throw HttpException::notFound('Provider');
        }
        return $this->s->providers()->findBySlug($slug) ?? throw HttpException::notFound('Provider');
    }

    /**
     * @param array<string,mixed> $provider
     * @return array<string,mixed>
     */
    protected function findExam(array $provider, string $code): array
    {
        if (!Ids::isExam($code)) {
            throw HttpException::notFound('Exam');
        }
        return $this->s->exams()->find((int) $provider['id'], $code) ?? throw HttpException::notFound('Exam');
    }

    /**
     * A cache version of an exam: the requested one, or the best available (latest complete,
     * else latest partial).
     *
     * @param array<string,mixed> $exam
     * @return array<string,mixed>
     */
    protected function findVersion(array $exam, ?string $version): array
    {
        if ($version !== null && $version !== '') {
            if (!Ids::isVersion($version)) {
                throw HttpException::notFound('Version');
            }
            return $this->s->versions()->findByVersion((int) $exam['id'], $version) ?? throw HttpException::notFound('Version');
        }
        return $this->s->versions()->bestForExam((int) $exam['id']) ?? throw HttpException::notFound('Cached data for this exam');
    }

    protected function ip(): string
    {
        return \App\Core\ClientIp::of($this->request->server, $this->app->config());
    }
}
