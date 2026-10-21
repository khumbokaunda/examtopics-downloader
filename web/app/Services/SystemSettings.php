<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use DateTimeZone;

/**
 * Settings an administrator changes on the Settings page: the default timezone and how long a
 * sign-in lasts. config.php / config.local.php give the defaults; values saved on the page
 * (the settings table) take precedence. Read once per request.
 */
final class SystemSettings
{
    /** Sign out after this many seconds without activity. */
    public const IDLE_CHOICES = [900, 1800, 3600, 7200, 14400, 28800, 43200, 86400, 259200, 604800];
    /** Sign out this many seconds after signing in, whatever the activity (0 = never). */
    public const ABSOLUTE_CHOICES = [0, 28800, 86400, 259200, 604800, 2592000];

    /** @var array{idle_timeout:int,absolute_timeout:int,timezone:string}|null */
    private ?array $cache = null;

    public function __construct(private App $app)
    {
    }

    /** @return array{idle_timeout:int,absolute_timeout:int,timezone:string} */
    public function effective(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $config = $this->app->config();
        $values = [
            'idle_timeout' => (int) $config->get('session.idle_timeout', 14400),
            'absolute_timeout' => (int) $config->get('session.absolute_timeout', 0),
            'timezone' => (string) $config->get('timezone', 'UTC'),
        ];
        try {
            $stored = (new \App\Repositories\SettingsRepository($this->app->db()));
            $idle = $stored->get('session.idle_timeout');
            $absolute = $stored->get('session.absolute_timeout');
            $tz = $stored->get('timezone');
            if ($idle !== null && ctype_digit($idle) && (int) $idle >= 300) {
                $values['idle_timeout'] = (int) $idle;
            }
            if ($absolute !== null && ctype_digit($absolute)) {
                $values['absolute_timeout'] = (int) $absolute;
            }
            if ($tz !== null && self::validTimezone($tz)) {
                $values['timezone'] = $tz;
            }
        } catch (\Throwable) {
            // No database yet (or the settings table is unreachable): the configured values stand.
        }
        if (!self::validTimezone($values['timezone'])) {
            $values['timezone'] = 'UTC';
        }
        return $this->cache = $values;
    }

    /**
     * Save the values the page posts; anything outside the offered choices is ignored.
     *
     * @param array<string,mixed> $input
     */
    public function save(array $input): void
    {
        $stored = new \App\Repositories\SettingsRepository($this->app->db());
        $idle = (int) ($input['idle_timeout'] ?? -1);
        if (in_array($idle, self::IDLE_CHOICES, true)) {
            $stored->set('session.idle_timeout', (string) $idle);
        }
        $absolute = (int) ($input['absolute_timeout'] ?? -1);
        if (in_array($absolute, self::ABSOLUTE_CHOICES, true)) {
            $stored->set('session.absolute_timeout', (string) $absolute);
        }
        $tz = (string) ($input['timezone'] ?? '');
        if (self::validTimezone($tz)) {
            $stored->set('timezone', $tz);
        }
        $this->cache = null;
    }

    /** The timezone a person's times and days are shown in: theirs if they chose one, else the system's. */
    public function timezoneFor(?array $user): string
    {
        $prefs = json_decode((string) ($user['preferences'] ?? ''), true);
        $mine = is_array($prefs) ? (string) ($prefs['timezone'] ?? '') : '';
        return $mine !== '' && self::validTimezone($mine) ? $mine : $this->effective()['timezone'];
    }

    public static function validTimezone(string $tz): bool
    {
        return $tz === 'UTC' || in_array($tz, DateTimeZone::listIdentifiers(), true);
    }

    /** "4 hours", "30 minutes", "7 days". */
    public static function describe(int $seconds): string
    {
        if ($seconds === 0) {
            return 'never';
        }
        foreach ([86400 => 'day', 3600 => 'hour', 60 => 'minute'] as $unit => $name) {
            if ($seconds % $unit === 0) {
                $n = intdiv($seconds, $unit);
                return $n . ' ' . $name . ($n === 1 ? '' : 's');
            }
        }
        return $seconds . ' seconds';
    }
}
