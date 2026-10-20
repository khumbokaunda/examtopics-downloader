<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What a failed sign-in typed in the username box, made safe to keep in logs and the activity
 * history. People sometimes type their password there. Anything that is not an email address
 * and is as long as a password must be (12 characters) is kept as its length only.
 */
final class IdentifierMask
{
    public const MIN_PASSWORD = 12;

    public static function mask(string $typed): string
    {
        $typed = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $typed));
        if ($typed === '') {
            return '(blank)';
        }
        if (filter_var($typed, FILTER_VALIDATE_EMAIL) !== false) {
            return mb_substr($typed, 0, 190);
        }
        if (mb_strlen($typed) >= self::MIN_PASSWORD) {
            return '(' . mb_strlen($typed) . ' characters, possibly a password)';
        }
        return $typed;
    }
}
