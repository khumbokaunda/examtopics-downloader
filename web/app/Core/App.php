<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal service container. Services are created lazily and shared for the request
 * (or CLI process). No framework, no magic: one method per service.
 */
final class App
{
    /** @var array<string,object> */
    private array $services = [];

    public function __construct(private Config $config)
    {
        $token = (string) $config->get('github_token', '');
        if ($token !== '') {
            Redactor::addSecret($token);
        }
        $pw = (string) $config->get('db.password', '');
        if ($pw !== '') {
            Redactor::addSecret($pw);
        }
    }

    public static function boot(?Config $config = null): self
    {
        date_default_timezone_set('UTC');
        return new self($config ?? Config::load());
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function db(): Database
    {
        return $this->services['db'] ??= Database::connect($this->config);
    }

    public function paths(): Paths
    {
        if (!isset($this->services['paths'])) {
            $dir = (string) $this->config->get('storage_dir');
            foreach (['', '/cache', '/documents', '/jobs', '/logs', '/tmp'] as $sub) {
                Paths::ensureDir($dir . $sub);
            }
            $this->services['paths'] = new Paths($dir);
        }
        return $this->services['paths'];
    }

    public function logger(): Logger
    {
        return $this->services['logger'] ??= new Logger($this->paths()->logsRoot());
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable():T $factory
     * @return T
     */
    public function service(string $class, callable $factory): object
    {
        /** @var T */
        return $this->services[$class] ??= $factory();
    }

    /** Replace a service (tests). */
    public function set(string $key, object $service): void
    {
        $this->services[$key] = $service;
    }
}
