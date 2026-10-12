<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Identifier rules. Every provider slug, exam code and version that reaches a filesystem
 * path or the tool's argument list must pass one of these checks first.
 */
final class Ids
{
    // Lower-case alphanumerics and single hyphens, as used in ExamTopics URLs.
    public const PROVIDER = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';
    // Exam codes from discussion links, e.g. "h12-811", "200-301", "professional-cloud-architect".
    public const EXAM = '/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/';
    // 20261012T083000Z-3f9a1c
    public const VERSION = '/^\d{8}T\d{6}Z-[0-9a-f]{6}$/';

    public static function isProvider(mixed $v): bool
    {
        return is_string($v) && preg_match(self::PROVIDER, $v) === 1 && !str_contains($v, '--');
    }

    public static function isExam(mixed $v): bool
    {
        return is_string($v) && preg_match(self::EXAM, $v) === 1 && !str_contains($v, '--');
    }

    public static function isVersion(mixed $v): bool
    {
        return is_string($v) && preg_match(self::VERSION, $v) === 1;
    }

    public static function newVersion(?int $time = null): string
    {
        return gmdate('Ymd\THis\Z', $time ?? time()) . '-' . bin2hex(random_bytes(3));
    }
}
