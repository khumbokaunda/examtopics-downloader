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
        return $this->app->service(\App\Services\SystemSettings::class, fn() => new \App\Services\SystemSettings($this->app))->timezoneFor($this->user);
    }

    /** The installation's name, logo and colour. */
    public function branding(): \App\Services\Branding
    {
        return $this->app->service(\App\Services\Branding::class, fn() => new \App\Services\Branding($this->app));
    }

    /** Unread notifications of the signed-in person, for the bell. */
    public function unreadNotifications(): int
    {
        if ($this->user === null) {
            return 0;
        }
        try {
            return (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [(int) $this->user['id']]);
        } catch (\Throwable) {
            return 0; // not migrated yet
        }
    }

    /** The institutions the signed-in person belongs to, by name, for switching between them. @return list<array{uuid:string,name:string,short_name:?string}> */
    public function myInstitutions(): array
    {
        if ($this->user === null) {
            return [];
        }
        try {
            return $this->app->db()->all("SELECT i.uuid, i.name, i.short_name FROM institution_memberships m JOIN institutions i ON i.id = m.institution_id
                WHERE m.user_id = ? AND m.status = 'active' AND i.deleted_at IS NULL ORDER BY i.name", [(int) $this->user['id']]);
        } catch (\Throwable) {
            return []; // not migrated yet
        }
    }

    /** Whether the signed-in person belongs to any institution, for the sidebar. */
    public function inInstitution(): bool
    {
        if ($this->user === null) {
            return false;
        }
        try {
            return (int) $this->app->db()->value("SELECT COUNT(*) FROM institution_memberships WHERE user_id = ? AND status = 'active'", [(int) $this->user['id']]) > 0;
        } catch (\Throwable) {
            return false; // not migrated yet
        }
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        return $this->user;
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
