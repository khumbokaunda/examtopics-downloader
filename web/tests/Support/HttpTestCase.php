<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Kernel;
use App\Core\Session;

/**
 * In-process HTTP tests: requests go through the real Router and Kernel (auth, CSRF,
 * headers, error handling) with an in-memory session.
 */
abstract class HttpTestCase extends WorkflowTestCase
{
    protected Session $session;
    protected string $ip = '203.0.113.7';

    protected function newSession(): void
    {
        $this->session = new Session();
    }

    protected function kernel(): Kernel
    {
        $router = new Router();
        (require dirname(__DIR__, 2) . '/routes/web.php')($router);
        return new Kernel($this->app, $this->session, $router);
    }

    /** @param array<string,string> $query */
    protected function get(string $path, array $query = []): Response
    {
        return $this->kernel()->handle(new Request('GET', $path, $query, [], ['REMOTE_ADDR' => $this->ip]));
    }

    /** @param array<string,string> $data */
    protected function post(string $path, array $data = [], bool $withCsrf = true): Response
    {
        if ($withCsrf) {
            $data['_csrf'] = $this->session->csrfToken();
        }
        return $this->kernel()->handle(new Request('POST', $path, [], $data, ['REMOTE_ADDR' => $this->ip]));
    }

    /**
     * A POST as the page's script sends it (asking for JSON), decoded.
     *
     * @param array<string,string> $data
     * @return array{status:int,json:array<string,mixed>}
     */
    protected function postJson(string $path, array $data = []): array
    {
        $data['_csrf'] = $this->session->csrfToken();
        $r = $this->kernel()->handle(new Request('POST', $path, [], $data, ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT' => 'application/json']));
        return ['status' => $r->status, 'json' => (array) json_decode($r->body, true)];
    }

    /**
     * A POST with uploaded files.
     *
     * @param array<string,string> $data @param array<string,array{name:string,tmp_name:string,size:int,error:int}> $files
     */
    protected function postFiles(string $path, array $data, array $files): Response
    {
        $data['_csrf'] = $this->session->csrfToken();
        return $this->kernel()->handle(new Request('POST', $path, [], $data, ['REMOTE_ADDR' => $this->ip], $files));
    }

    /** Sign in, creating the account first (an administrator unless $role says otherwise). */
    protected function login(string $user = 'admin', string $password = 'correct horse battery', string $role = 'admin'): void
    {
        if ($this->s->users()->findByUsername($user) === null) {
            $this->s->users()->create($user, $password, $role);
        }
        $r = $this->post('/login', ['username' => $user, 'password' => $password]);
        self::assertSame(303, $r->status, 'login should redirect');
    }

    /** @return list<array{type:string,message:string}> */
    protected function flashes(): array
    {
        return $this->session->takeFlashes();
    }
}
