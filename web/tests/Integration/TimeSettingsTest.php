<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Services\SystemSettings;
use Tests\Support\HttpTestCase;

final class TimeSettingsTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure('http://127.0.0.1:9');
        $this->newSession();
        $this->login();
    }

    private function system(): SystemSettings
    {
        return new SystemSettings($this->app);
    }

    public function testDefaultsComeFromTheConfiguration(): void
    {
        $s = $this->system()->effective();
        self::assertSame(14400, $s['idle_timeout']);
        self::assertSame(0, $s['absolute_timeout']);
        self::assertSame('UTC', $s['timezone']);
        $page = $this->get('/settings')->body;
        self::assertStringContainsString('Sign out after inactivity', $page);
        self::assertMatchesRegularExpression('#<option value="14400" selected>4 hours</option>#', $page);
    }

    public function testSavingTimeSettings(): void
    {
        $r = $this->post('/settings/time', ['my_timezone' => 'Africa/Blantyre', 'timezone' => 'Europe/London',
            'idle_timeout' => '1800', 'absolute_timeout' => '86400']);
        self::assertSame(303, $r->status);
        self::assertSame('/settings#time', $r->headers['Location']);
        $s = $this->system()->effective();
        self::assertSame(['idle_timeout' => 1800, 'absolute_timeout' => 86400, 'timezone' => 'Europe/London'], $s);

        // The user's own timezone wins over the system default, and leaves appearance alone.
        $this->post('/settings/appearance', ['accent' => 'rose']);
        $prefs = json_decode((string) $this->app->db()->value("SELECT preferences FROM users WHERE username = 'admin'"), true);
        self::assertSame('Africa/Blantyre', $prefs['timezone']);
        self::assertSame('rose', $prefs['accent']);

        // A job queued at 08:00 UTC is shown at 10:00 in Blantyre (CAT, UTC+2).
        $id = $this->s->jobService()->enqueueProviders();
        $this->app->db()->run("UPDATE jobs SET queued_at = '2026-10-12 08:00:00' WHERE id = ?", [$id]);
        $page = $this->get('/jobs/' . $this->s->jobs()->find($id)['uuid'])->body;
        self::assertStringContainsString('2026-10-12 10:00 CAT', $page);
    }

    public function testValuesOutsideTheChoicesAreIgnored(): void
    {
        $this->post('/settings/time', ['my_timezone' => 'Mars/Olympus', 'timezone' => '"><script>', 'idle_timeout' => '5', 'absolute_timeout' => '-1']);
        self::assertSame(['idle_timeout' => 14400, 'absolute_timeout' => 0, 'timezone' => 'UTC'], $this->system()->effective());
        $prefs = json_decode((string) $this->app->db()->value("SELECT preferences FROM users WHERE username = 'admin'"), true);
        self::assertSame('', $prefs['timezone']);
    }

    public function testClockFormatsInTheChosenZone(): void
    {
        Clock::use('Africa/Blantyre');
        self::assertSame('2026-01-01 02:30 CAT', Clock::format('2026-01-01 00:30:00'));
        Clock::use('Not/AZone');
        self::assertSame('2026-01-01 00:30 UTC', Clock::format('2026-01-01 00:30:00'));
        self::assertSame('4 hours', SystemSettings::describe(14400));
        self::assertSame('30 minutes', SystemSettings::describe(1800));
        self::assertSame('7 days', SystemSettings::describe(604800));
        self::assertSame('never', SystemSettings::describe(0));
    }
}
