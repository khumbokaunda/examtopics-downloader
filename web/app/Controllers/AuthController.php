<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;

final class AuthController extends Controller
{
    public function showLogin(): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/');
        }
        return $this->render('auth/login', [
            'title' => 'Sign in',
            'next' => $this->safeNext($this->request->query('next')),
            'error' => null,
            'username' => '',
            'noUsers' => $this->s->users()->count() === 0,
        ]);
    }

    public function login(): Response
    {
        $username = trim((string) $this->request->input('username', ''));
        $password = (string) $this->request->input('password', '');
        $next = $this->safeNext($this->request->input('next'));
        $error = $this->auth->attempt($username, $password, $this->ip());
        if ($error !== null) {
            return $this->render('auth/login', [
                'title' => 'Sign in', 'next' => $next, 'error' => $error, 'username' => $username,
                'noUsers' => $this->s->users()->count() === 0,
            ], 401);
        }
        return $this->redirect($next);
    }

    public function logout(): Response
    {
        $this->auth->logout($this->ip());
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
