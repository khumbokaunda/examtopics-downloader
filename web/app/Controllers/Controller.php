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

    protected function flash(string $type, string $message): void
    {
        $this->session->flash($type, $message);
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
