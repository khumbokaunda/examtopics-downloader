<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The single-worker lock: an exclusive flock() on storage/jobs/worker.lock.
 *
 * The kernel releases flock() locks when the holding process exits for any reason (crash,
 * SIGKILL, reboot), so the lock can never go stale. Whoever holds it is the only process
 * allowed to run collection-engine jobs, which also means: if you hold it, any job still
 * marked "running" is not actually running.
 */
final class WorkerLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private string $path)
    {
    }

    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return true;
        }
        $fh = fopen($this->path, 'c');
        if ($fh === false) {
            return false;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false;
        }
        ftruncate($fh, 0);
        fwrite($fh, (string) getmypid());
        fflush($fh);
        $this->handle = $fh;
        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /** True when some process (possibly this one) holds the lock. */
    public function isHeld(): bool
    {
        if ($this->handle !== null) {
            return true;
        }
        $fh = @fopen($this->path, 'c');
        if ($fh === false) {
            return false;
        }
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return !$free;
    }

    public function holderPid(): ?int
    {
        $pid = @file_get_contents($this->path);
        return is_string($pid) && ctype_digit(trim($pid)) ? (int) trim($pid) : null;
    }
}
