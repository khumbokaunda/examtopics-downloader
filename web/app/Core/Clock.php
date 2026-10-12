<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Times are stored in UTC and shown in the reader's timezone: theirs if they chose one,
 * otherwise the system default. The view sets the zone before rendering.
 */
final class Clock
{
    private static ?DateTimeZone $zone = null;

    public static function use(string $timezone): void
    {
        try {
            self::$zone = new DateTimeZone($timezone);
        } catch (\Exception) {
            self::$zone = new DateTimeZone('UTC');
        }
    }

    public static function zone(): DateTimeZone
    {
        return self::$zone ??= new DateTimeZone('UTC');
    }

    /** "2026-10-12 14:20 CAT" for a UTC DATETIME string, or null when it cannot be read. */
    public static function format(string $utc, string $format = 'Y-m-d H:i'): ?string
    {
        try {
            $t = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        $local = $t->setTimezone(self::zone());
        return $local->format($format) . ' ' . $local->format('T');
    }
}
