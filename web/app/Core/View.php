<?php

declare(strict_types=1);

namespace App\Core;

/**
 * PHP templates in app/Views. Templates must escape every value with e(); the helpers below
 * are the only way they produce URLs, CSRF fields, dates and badges.
 */
final class View
{
    private static ?self $current = null;

    /** @param array<string,mixed>|null $user */
    public function __construct(private App $app, private Session $session, private ?array $user)
    {
    }

    public static function current(): self
    {
        return self::$current ?? throw new \LogicException('No view is rendering');
    }

    /** @param array<string,mixed> $vars */
    public function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $previous = self::$current;
        self::$current = $this;
        Clock::use($this->timezone());
        try {
            $content = $this->include($template, $vars);
            if ($layout === null) {
                return $content;
            }
            return $this->include($layout, $vars + [
                'content' => $content,
                'user' => $this->user,
                'flashes' => $this->session->takeFlashes(),
            ]);
        } finally {
            self::$current = $previous;
        }
    }

    /** Render a partial inside a template. @param array<string,mixed> $vars */
    public function partial(string $template, array $vars = []): string
    {
        return $this->include($template, $vars);
    }

    /** @param array<string,mixed> $vars */
    private function include(string $template, array $vars): string
    {
        if (preg_match('#^[a-z0-9_/]+$#', $template) !== 1) {
            throw new \InvalidArgumentException('Bad template name');
        }
        $file = dirname(__DIR__) . '/Views/' . $template . '.php';
        $render = static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };
        return $render($file, $vars);
    }

    /**
     * Migrations the database is missing, so the layout can say so: new code against an old
     * schema fails in confusing ways (a setting that does not save, for example).
     *
     * @return list<string>
     */
    public function pendingMigrations(): array
    {
        try {
            return (new Migrator($this->app->db(), dirname(__DIR__, 2) . '/database/migrations'))->pending();
        } catch (\Throwable) {
            return [];
        }
    }

    /** The reader's timezone: their own choice, else the system default. */
    public function timezone(): string
    {
        $prefs = json_decode((string) ($this->user['preferences'] ?? ''), true);
        $mine = is_array($prefs) ? (string) ($prefs['timezone'] ?? '') : '';
        return $mine !== '' && \App\Services\SystemSettings::validTimezone($mine)
            ? $mine : $this->app->service(\App\Services\SystemSettings::class, fn() => new \App\Services\SystemSettings($this->app))->effective()['timezone'];
    }

    public function csrf(): string
    {
        return $this->session->csrfToken();
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->app->config()->get($key, $default);
    }

    /** @param array<string,string|int> $query */
    public function url(string $path, array $query = []): string
    {
        return self::buildUrl((string) $this->app->config()->get('base_path', ''), $path, $query);
    }

    /** @param array<string,string|int> $query */
    public static function buildUrl(string $base, string $path, array $query = []): string
    {
        $query = array_filter($query, static fn($v) => $v !== '' && $v !== null);
        return rtrim($base, '/') . $path . ($query ? '?' . http_build_query($query) : '');
    }
}
