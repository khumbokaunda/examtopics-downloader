<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON Lines logger with separate channels (app, jobs, inventory, documents, security).
 * Every message and context value is redacted. Rotate with logrotate (see docs/OPERATIONS.md).
 */
final class Logger
{
    public const CHANNELS = ['app', 'jobs', 'inventory', 'documents', 'security'];

    public function __construct(private string $dir)
    {
    }

    /** @param array<string,mixed> $context */
    public function log(string $channel, string $level, string $message, array $context = []): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            $channel = 'app';
        }
        $record = [
            'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
            'level'   => $level,
            'message' => Redactor::clean($message),
        ];
        foreach ($context as $k => $v) {
            $record[$k] = is_string($v) ? Redactor::clean($v) : $v;
        }
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        @file_put_contents($this->dir . '/' . $channel . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context */
    public function info(string $channel, string $message, array $context = []): void
    {
        $this->log($channel, 'info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $channel, string $message, array $context = []): void
    {
        $this->log($channel, 'warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $channel, string $message, array $context = []): void
    {
        $this->log($channel, 'error', $message, $context);
    }
}
