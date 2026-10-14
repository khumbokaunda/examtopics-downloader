<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Appearance preferences: what each one may be, the defaults, and how a stored or submitted
 * value is reduced to a known one. The layout renders them into attributes on <html>; CSS
 * selects on those attributes, so a value outside these lists can never reach the page.
 */
final class Appearance
{
    public const OPTIONS = [
        'theme'   => ['system', 'light', 'dark'],
        'accent'  => ['teal', 'indigo', 'violet', 'amber', 'rose', 'emerald'],
        'density' => ['comfortable', 'compact', 'cozy'],
        'surface' => ['classic', 'soft'],
        'motion'  => ['system', 'reduced'],
    ];

    /**
     * The defaults: the first of each list, except that the branding's theme and preset colour
     * are the defaults for people who have not chosen their own.
     *
     * @param array{accent?:string,theme?:string}|null $brand
     * @return array<string,string>
     */
    public static function defaults(?array $brand = null): array
    {
        $defaults = array_map(static fn(array $values): string => $values[0], self::OPTIONS);
        return $brand === null ? $defaults : self::merge($defaults, ['accent' => $brand['accent'] ?? '', 'theme' => $brand['theme'] ?? '']);
    }

    /**
     * The preferences of a user row (or the defaults for nobody).
     *
     * @param array<string,mixed>|null $user
     * @param array{accent?:string,theme?:string}|null $brand
     * @return array<string,string>
     */
    public static function forUser(?array $user, ?array $brand = null): array
    {
        $stored = json_decode((string) ($user['preferences'] ?? ''), true);
        return self::merge(self::defaults($brand), is_array($stored) ? $stored : []);
    }

    /**
     * The accent painted on the page: a custom branding colour applies to everyone, otherwise
     * the person's own choice.
     *
     * @param array<string,string> $prefs @param array{accent:string} $brand
     */
    public static function pageAccent(array $prefs, array $brand): string
    {
        return $brand['accent'] === 'custom' ? 'custom' : $prefs['accent'];
    }

    /**
     * Overlay submitted values on a base, keeping only known keys with allowed values.
     *
     * @param array<string,string> $base
     * @param array<mixed> $input
     * @return array<string,string>
     */
    public static function merge(array $base, array $input): array
    {
        foreach (self::OPTIONS as $key => $allowed) {
            $value = $input[$key] ?? null;
            if (is_string($value) && in_array($value, $allowed, true)) {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /** The theme painted before any script runs: a stored "system" is resolved in the browser. */
    public static function initialTheme(array $prefs): string
    {
        return ($prefs['theme'] ?? '') === 'dark' ? 'dark' : 'light';
    }
}
