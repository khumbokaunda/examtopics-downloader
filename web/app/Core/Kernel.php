<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Services\Auth;
use App\Services\Services;
use Throwable;

/**
 * Request pipeline. Policy lives here rather than in controllers, so it cannot be forgotten
 * on a new endpoint:
 *  - every route requires a signed-in user unless explicitly public (sign-in, branding files);
 *  - a signed-in route needs the permission it names, and one that names none is for
 *    administrators only (403 otherwise);
 *  - every POST requires a valid CSRF token;
 *  - every response gets security headers and Cache-Control: no-store;
 *  - errors never expose stack traces to the browser.
 */
final class Kernel
{
    private Services $services;
    private Auth $auth;

    public function __construct(private App $app, private Session $session, private Router $router)
    {
        $this->services = new Services($app);
        $this->auth = new Auth($session, $this->services->users(), $this->services->signInGuard(),
            $app->logger(), $app->config(), $this->services->backup()->restoredAt(), $this->services->audit());
    }

    public function auth(): Auth
    {
        return $this->auth;
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (HttpException $e) {
            $response = $this->error($request, $e->status, $e->getMessage());
        } catch (Throwable $e) {
            $ref = bin2hex(random_bytes(4));
            $this->app->logger()->error('app', 'Unhandled exception', [
                'ref' => $ref, 'path' => $request->path, 'error' => get_class($e) . ': ' . $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);
            $response = $this->error($request, 500, 'Something went wrong. Reference: ' . $ref . ' (see storage/logs/app.log).');
        }
        return $this->secure($request, $response);
    }

    private function dispatch(Request $request): Response
    {
        // The activity history credits what happens in this request to the signed-in person.
        $this->services->audit()->forRequest(fn(): ?array => $this->auth->user(), ClientIp::of($request->server, $this->app->config()));
        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            throw HttpException::notFound();
        }
        if (!$match['public'] && !$this->auth->check()) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Authentication required.'], 401);
            }
            $next = $request->method === 'GET' ? $request->path : '/';
            // Signed out by a restore: the sign-in form says so.
            return Response::redirect($this->url('/login', ($this->auth->endedByRestore() ? ['restored' => '1'] : []) + ['next' => $next]));
        }
        if (!$match['public']) {
            $needed = $match['can'];
            if ($needed === null ? !$this->auth->isAdmin() : !$this->auth->can($needed)) {
                $this->app->logger()->warning('security', 'Access refused', [
                    'path' => $request->path, 'username' => $this->auth->user()['username'] ?? null, 'needs' => $needed ?? 'admin',
                ]);
                throw new HttpException(403, $needed === null
                    ? 'This page is for administrators.'
                    : 'Your account does not have access to this page. It needs the "' . $needed . '" permission, which an administrator can give you.');
            }
        }
        if ($request->method === 'POST') {
            $token = $request->input('_csrf') ?? $request->header('X-CSRF-Token');
            if (!$this->session->validCsrf($token)) {
                $this->app->logger()->warning('security', 'CSRF token rejected', [
                    'path' => $request->path, 'ip' => ClientIp::of($request->server, $this->app->config()),
                ]);
                // The sign-in form is the one most often left open while its session ends (a long
                // pause, a restore): show it again with a fresh token rather than an error page.
                if ($request->path === '/login' && !$request->wantsJson()) {
                    $this->session->flash('warning', 'The sign-in form had expired, so nothing was sent. Please sign in again.');
                    return Response::redirect($this->url('/login', array_filter(['next' => (string) $request->input('next', '')])));
                }
                throw new HttpException(403, 'Your session expired or the form was submitted from another site. Reload the page and try again.');
            }
        }
        [$class, $method] = $match['handler'];
        /** @var Controller $controller */
        $controller = new $class($this->app, $this->services, $this->session, $this->auth, $request);
        return $controller->$method(...$match['params']);
    }

    private function error(Request $request, int $status, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => $message], $status);
        }
        $view = new View($this->app, $this->session, $this->auth->check() ? $this->auth->user() : null);
        return Response::html($view->render('error', ['status' => $status, 'message' => $message, 'title' => 'Error']), $status);
    }

    private function secure(Request $request, Response $response): Response
    {
        $headers = [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https:; "
                . "font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cache-Control' => 'no-store, private',
        ];
        if ($request->isHttps() && (bool) $this->app->config()->get('security.hsts', true)) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        foreach ($headers as $k => $v) {
            if (!isset($response->headers[$k])) {
                $response->headers[$k] = $v;
            }
        }
        return $response;
    }

    /** @param array<string,string> $query */
    public function url(string $path, array $query = []): string
    {
        return View::buildUrl((string) $this->app->config()->get('base_path', ''), $path, $query);
    }
}
