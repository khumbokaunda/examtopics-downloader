<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;

final class AppearanceTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure('http://127.0.0.1:9');
        $this->newSession();
        $this->login();
    }

    private function html(): string
    {
        $r = $this->get('/');
        self::assertSame(200, $r->status);
        preg_match('#<html[^>]*>#s', $r->body, $m);
        return $m[0] ?? '';
    }

    public function testDefaultsAreRenderedOnTheRootElement(): void
    {
        $html = $this->html();
        foreach (['data-prefs="server"', 'data-theme="light"', 'data-theme-choice="system"', 'data-accent="teal"',
                  'data-density="comfortable"', 'data-surface="classic"'] as $attr) {
            self::assertStringContainsString($attr, $html);
        }
        self::assertStringNotContainsString('data-motion', $html);
    }

    public function testTheAppearanceFormSavesEveryPreference(): void
    {
        $r = $this->post('/settings/appearance', ['theme' => 'dark', 'accent' => 'indigo', 'density' => 'compact', 'surface' => 'soft', 'motion' => 'reduced']);
        self::assertSame(303, $r->status);
        self::assertSame('/settings#appearance', $r->headers['Location']);
        $html = $this->html();
        foreach (['data-theme="dark"', 'data-theme-choice="dark"', 'data-accent="indigo"', 'data-density="compact"',
                  'data-surface="soft"', 'data-motion="reduced"'] as $attr) {
            self::assertStringContainsString($attr, $html);
        }
        // The settings page shows the saved choices as checked.
        self::assertMatchesRegularExpression('#name="accent" value="indigo" checked#', $this->get('/settings')->body);
    }

    public function testUnknownValuesAreIgnored(): void
    {
        $this->post('/settings/appearance', ['accent' => 'rose']);
        $this->post('/settings/appearance', ['accent' => '"><script>alert(1)</script>', 'theme' => 'purple', 'density' => 'huge', 'extra' => 'x']);
        $html = $this->html();
        self::assertStringContainsString('data-accent="rose"', $html, 'a bad value leaves the saved one alone');
        self::assertStringContainsString('data-theme-choice="system"', $html);
        self::assertStringNotContainsString('<script>alert', $this->get('/settings')->body);
        $stored = json_decode((string) $this->app->db()->value("SELECT preferences FROM users WHERE username = 'admin'"), true);
        self::assertSame(['theme', 'accent', 'density', 'surface', 'motion'], array_keys($stored), 'only known keys are stored');
    }

    public function testTheThemeToggleEndpointChangesOnlyTheTheme(): void
    {
        $this->post('/settings/appearance', ['accent' => 'emerald']);
        $r = $this->post('/api/appearance', ['theme' => 'dark']);
        self::assertSame(200, $r->status);
        $json = json_decode($r->body, true);
        self::assertSame('dark', $json['appearance']['theme']);
        self::assertSame('emerald', $json['appearance']['accent']);
        self::assertSame(403, $this->post('/api/appearance', ['theme' => 'light'], withCsrf: false)->status, 'the toggle needs the CSRF token');
        self::assertStringContainsString('data-theme-choice="dark"', $this->html());
    }

    public function testAMissingMigrationIsReportedInsteadOfFailingSilently(): void
    {
        $db = $this->app->db();
        $db->pdo()->exec('ALTER TABLE users DROP COLUMN preferences');
        $db->run("DELETE FROM schema_migrations WHERE version = '002_user_preferences'");
        try {
            $this->newSession();
            $this->login();
            $page = $this->get('/')->body;
            self::assertStringContainsString('The database needs an update', $page);
            self::assertStringContainsString('002_user_preferences', $page);

            $r = $this->post('/api/appearance', ['theme' => 'dark']);
            self::assertSame(503, $r->status);
            self::assertStringContainsString('php bin/migrate.php', json_decode($r->body, true)['error']);

            self::assertSame(303, $this->post('/settings/appearance', ['theme' => 'dark'])->status);
            $flash = $this->flashes();
            self::assertSame('danger', $flash[0]['type'] ?? null);
            self::assertStringContainsString('php bin/migrate.php', $flash[0]['message']);
        } finally {
            (new \App\Core\Migrator($db, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        }
        self::assertTrue($this->s->users()->hasColumn('preferences'));
        self::assertStringNotContainsString('The database needs an update', $this->get('/')->body);
    }

    public function testPreferencesArePerUser(): void
    {
        $this->post('/settings/appearance', ['theme' => 'dark']);
        $this->newSession();
        $this->login('second', 'another long password');
        self::assertStringContainsString('data-theme-choice="system"', $this->html());
    }
}
