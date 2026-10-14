<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Route table. Patterns use {name} placeholders that match one path segment; every route
 * requires authentication unless registered with public: true. A signed-in route names the
 * permission it needs with can: (Access::SIGNED_IN for pages every account has); a route
 * that names none is for administrators only, so a forgotten gate fails closed. POST routes
 * always require a valid CSRF token (enforced by the Kernel, not by individual controllers).
 */
final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,names:list<string>,handler:array{class-string,string},public:bool,can:?string}> */
    private array $routes = [];

    /** @param array{class-string,string} $handler */
    public function get(string $pattern, array $handler, bool $public = false, ?string $can = null): void
    {
        $this->add('GET', $pattern, $handler, $public, $can);
    }

    /** @param array{class-string,string} $handler */
    public function post(string $pattern, array $handler, bool $public = false, ?string $can = null): void
    {
        $this->add('POST', $pattern, $handler, $public, $can);
    }

    /** @param array{class-string,string} $handler */
    private function add(string $method, string $pattern, array $handler, bool $public, ?string $can): void
    {
        $names = [];
        $regex = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use (&$names): string {
            $names[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'names' => $names,
            'handler' => $handler,
            'public' => $public,
            'can' => $can,
        ];
    }

    /** @return list<array{method:string,pattern:string,public:bool,can:?string}> for tests and audits */
    public function routes(): array
    {
        return array_map(static fn(array $r): array => [
            'method' => $r['method'], 'pattern' => $r['pattern'], 'public' => $r['public'], 'can' => $r['can'],
        ], $this->routes);
    }

    /**
     * @return array{handler:array{class-string,string},params:array<string,string>,public:bool,can:?string}|null
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
            return ['handler' => $route['handler'], 'params' => $params, 'public' => $route['public'], 'can' => $route['can']];
        }
        if ($methodMismatch) {
            throw new HttpException(405, 'Method not allowed.');
        }
        return null;
    }
}
