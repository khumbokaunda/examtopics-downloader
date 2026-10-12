<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Configuration: config/config.php, overlaid by config/config.local.php (or the file
 * named by EXAMLIB_CONFIG), overlaid by EXAMLIB_* environment variables.
 */
final class Config
{
    /** @param array<string,mixed> $values */
    private function __construct(private array $values)
    {
    }

    public static function load(?string $configDir = null): self
    {
        $configDir ??= dirname(__DIR__, 2) . '/config';
        $values = require $configDir . '/config.php';

        $local = getenv('EXAMLIB_CONFIG') ?: $configDir . '/config.local.php';
        if (is_file($local)) {
            $overlay = require $local;
            if (is_array($overlay)) {
                $values = array_replace_recursive($values, $overlay);
            }
        }

        $env = [
            'EXAMLIB_DB_DSN'      => ['db', 'dsn'],
            'EXAMLIB_DB_USER'     => ['db', 'user'],
            'EXAMLIB_DB_PASSWORD' => ['db', 'password'],
            'EXAMLIB_GITHUB_TOKEN' => ['github_token'],
            'EXAMLIB_STORAGE_DIR' => ['storage_dir'],
            'EXAMLIB_TOOL_BINARY' => ['tool', 'binary'],
        ];
        foreach ($env as $name => $path) {
            $v = getenv($name);
            if ($v !== false && $v !== '') {
                $ref = &$values;
                foreach ($path as $key) {
                    $ref = &$ref[$key];
                }
                $ref = $v;
                unset($ref);
            }
        }

        return new self($values);
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /** Dot-path lookup: get('db.dsn'). */
    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
