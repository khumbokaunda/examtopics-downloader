<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A child process started from an argument array: no shell, so arguments are never
 * interpreted. stdin is /dev/null; stdout and stderr go to $logFile.
 */
final class Process
{
    /**
     * proc_open() passes every open descriptor of the parent to the child. Without this,
     * a worker launched from the web server would hold the server's listening sockets
     * (blocking a restart of Apache/php-fpm), and the tool would hold the worker's lock file
     * (keeping the single-worker lock "held" after the worker died). This fixed preamble
     * closes descriptors above 2 and then execs the real program; arguments are passed
     * positionally, never interpolated.
     */
    private const CLOSE_FDS = 'for fd in /proc/self/fd/*; do n=${fd##*/}; '
        . 'if [ "$n" -gt 2 ] 2>/dev/null; then eval "exec $n>&-"; fi; done; exec "$0" "$@"';

    /**
     * @param list<string> $argv
     * @return list<string>
     */
    public static function withClosedFds(array $argv, string $bash = '/bin/bash'): array
    {
        if (!is_executable($bash) || !is_dir('/proc/self/fd')) {
            return $argv;
        }
        return array_merge([$bash, '-c', self::CLOSE_FDS], $argv);
    }

    /** @var resource */
    private $proc;
    private int $pid;
    private ?int $exitCode = null;

    /**
     * @param list<string> $argv
     * @param array<string,string> $env  complete environment for the child
     */
    public function __construct(array $argv, string $cwd, array $env, string $logFile)
    {
        $proc = proc_open(
            self::withClosedFds($argv),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
            $pipes,
            $cwd,
            $env
        );
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not start ' . basename($argv[0]));
        }
        $this->proc = $proc;
        $status = proc_get_status($proc);
        $this->pid = (int) $status['pid'];
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /** Null while running; the exit code once finished (128+N when killed by signal N). */
    public function poll(): ?int
    {
        if ($this->exitCode !== null) {
            return $this->exitCode;
        }
        $s = proc_get_status($this->proc);
        if ($s['running']) {
            return null;
        }
        // proc_get_status reports the real exit code only once; keep it.
        $this->exitCode = $s['signaled'] ? 128 + (int) $s['termsig'] : (int) $s['exitcode'];
        proc_close($this->proc);
        return $this->exitCode;
    }

    public function signal(int $signal): void
    {
        if ($this->exitCode === null) {
            proc_terminate($this->proc, $signal);
        }
    }

    /** SIGTERM, then SIGKILL after $graceSeconds. Returns the exit code. */
    public function stop(int $graceSeconds): int
    {
        $this->signal(15);
        $deadline = microtime(true) + $graceSeconds;
        while (($code = $this->poll()) === null) {
            if (microtime(true) >= $deadline) {
                $this->signal(9);
                $deadline = microtime(true) + 5;
            }
            usleep(100_000);
        }
        return $code;
    }
}
