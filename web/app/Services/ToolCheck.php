<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Whether the installed collection tool is as new as the web app: a tool that was not rebuilt
 * after an update lacks the options the app now passes it. Read from the tool's own -h text
 * (an argument array, no shell), once per binary file version.
 */
final class ToolCheck
{
    /** Options the web app relies on. */
    public const REQUIRED = ['-batch', '-events', '-out-json', '-inventory', '-providers', '-exam', '-images-from', '-images-dir'];

    /** @var array<string,list<string>> */
    private static array $cache = [];

    /** @return list<string> the required options the tool does not know; [] when up to date or not installed */
    public static function missingOptions(string $binary): array
    {
        if (!is_file($binary) || !is_executable($binary)) {
            return [];
        }
        $key = $binary . '|' . filemtime($binary) . '|' . filesize($binary);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $proc = proc_open([$binary, '-h'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return [];
        }
        $out = '';
        $deadline = microtime(true) + 5;
        foreach ([1, 2] as $i) {
            stream_set_blocking($pipes[$i], false);
        }
        while (microtime(true) < $deadline) {
            $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            if (!proc_get_status($proc)['running']) {
                $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
                break;
            }
            usleep(20_000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($proc);
        proc_close($proc);
        $missing = array_values(array_filter(self::REQUIRED,
            static fn(string $opt) => preg_match('/^\s*' . preg_quote($opt, '/') . '\b/m', $out) !== 1));
        return self::$cache[$key] = $missing;
    }

    public static function rebuildHint(): string
    {
        return 'Rebuild it in the project folder with: go build -o bin/examtopics-downloader ./cmd';
    }
}
