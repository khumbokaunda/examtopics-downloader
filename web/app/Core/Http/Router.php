<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Route table. Patterns use {name} placeholders that match one path segment; every route
 * requires authentication unless registered with public: true. POST routes always require
 * a valid CSRF token (enforced by the Kernel, not by individual controllers).
 */
final class Router
{
    /** @var list<array{method:string,regex:string,names:list<string>,handler:array{class-string,string},public:bool}> */
    private array $routes = [];

    /** @param array{class-string,string} $handler */
    public function get(string $pattern, array $handler, bool $public = false): void
    {
        $this->add('GET', $pattern, $handler, $public);
    }

    /** @param array{class-string,string} $handler */
    public function post(string $pattern, array $handler, bool $public = false): void
    {
        $this->add('POST', $pattern, $handler, $public);
    }

    /** @param array{class-string,string} $handler */
    private function add(string $method, string $pattern, array $handler, bool $public): void
    {
        $names = [];
        $regex = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use (&$names): string {
            $names[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'names' => $names,
            'handler' => $handler,
            'public' => $public,
        ];
    }

    /**
     * @return array{handler:array{class-string,string},params:array<string,string>,public:bool}|null
     *         null when nothing matches; ['handler'=>[],...] is never returned.
     */
    public function match(string $method, string $path): ?array
    {
        $methodMismatch = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                $methodMismatch = true;
                continue;
            }
            $params = [];
            foreach ($route['names'] as $i => $name) {
                $params[$name] = rawurldecode($m[$i + 1]);
            }
            return ['handler' => $route['handler'], 'params' => $params, 'public' => $route['public']];
        }
        if ($methodMismatch) {
            throw new HttpException(405, 'Method not allowed.');
        }
        return null;
    }
}
