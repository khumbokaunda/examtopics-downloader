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

    /** @return array<string,string> */
    public static function defaults(): array
    {
        return array_map(static fn(array $values): string => $values[0], self::OPTIONS);
    }

    /**
     * The preferences of a user row (or the defaults for nobody).
     *
     * @param array<string,mixed>|null $user
     * @return array<string,string>
     */
    public static function forUser(?array $user): array
    {
        $stored = json_decode((string) ($user['preferences'] ?? ''), true);
        return self::merge(self::defaults(), is_array($stored) ? $stored : []);
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
