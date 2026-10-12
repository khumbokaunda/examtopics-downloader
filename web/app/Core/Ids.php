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

    /** A random (version 4) UUID, the public identifier of a job or document in URLs. */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    /** Any lower-case hyphenated UUID (rows migrated with MySQL's UUID() are version 1). */
    public static function isUuid(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) === 1;
    }

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
