<?php

declare(strict_types=1);

namespace App\Core;

/** Removes credentials from any text before it is logged or shown in the browser. */
final class Redactor
{
    /** @var list<string> exact secret values known at runtime (e.g. the configured token) */
    private static array $secrets = [];

    public static function addSecret(string $secret): void
    {
        if (strlen($secret) >= 8) {
            self::$secrets[] = $secret;
        }
    }

    public static function clean(string $text): string
    {
        foreach (self::$secrets as $s) {
            $text = str_replace($s, '[REDACTED]', $text);
        }
        $patterns = [
            '/\b(gh[pousr]_[A-Za-z0-9]{20,})\b/'          => '[REDACTED]',
            '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/'           => '[REDACTED]',
            '/(Authorization:\s*)(Bearer|token|Basic)\s+\S+/i' => '$1$2 [REDACTED]',
            '/(Cookie:\s*)[^\r\n]+/i'                       => '$1[REDACTED]',
            '/([?&](?:token|access_token|password|key)=)[^&\s"]+/i' => '$1[REDACTED]',
            '/(EXAMTOPICS_GITHUB_TOKEN=)\S+/'                => '$1[REDACTED]',
        ];
        foreach ($patterns as $re => $rep) {
            $text = preg_replace($re, $rep, $text) ?? $text;
        }
        return $text;
    }
}
