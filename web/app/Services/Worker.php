<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;
use App\Core\Paths;
use Throwable;

/**
 * The background worker. Exactly one runs at a time (WorkerLock). It never needs a browser
 * connection: closing the page or losing connectivity does not affect it.
 *
 *  --drain   process queued jobs, then exit (how the web app launches it)
 *  --daemon  keep polling for jobs (for an optional systemd service)
 */
final class Worker
{
    private bool $stop = false;
    private ?JobRunner $current = null;

    public function __construct(private Config $config)
    {
    }

    public function run(bool $daemon): int
    {
        $boot = App::boot($this->config);
        $lock = new WorkerLock($boot->paths()->workerLock());
        if (!$lock->acquire()) {
            fwrite(STDERR, '[' . gmdate('c') . "] another worker holds the lock; exiting\n");
            return 0;
        }
        $this->installSignalHandlers();
        $boot->logger()->info('jobs', 'Worker started', ['pid' => getmypid(), 'mode' => $daemon ? 'daemon' : 'drain']);

        try {
            // We hold the lock, so nothing else can be running a job: anything still marked
            // running was interrupted.
            (new Services($boot))->supervisor()->failInterruptedJobs();

            while (!$this->stop) {
                // Fresh services (and database connection) per cycle, so a long-lived daemon
                // never trips over a connection the server has timed out.
                $app = App::boot($this->config);
                $services = new Services($app);
                $job = $services->jobs()->claimNext((int) getmypid());
                if ($job === null) {
                    if (!$daemon) {
                        break;
                    }
                    sleep(2);
                    continue;
                }
                $this->current = $services->runner();
                $this->current->run($job);
                $this->current = null;
            }
        } catch (Throwable $e) {
            $boot->logger()->error('jobs', 'Worker error', ['error' => $e->getMessage()]);
            fwrite(STDERR, '[' . gmdate('c') . '] worker error: ' . $e->getMessage() . "\n");
            return 1;
        } finally {
            $lock->release();
            $boot->logger()->info('jobs', 'Worker stopped', ['pid' => getmypid()]);
        }
        return 0;
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (): void {
            $this->stop = true;
            if ($this->current !== null) {
                $this->current->shutdownRequested = true;
            }
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, SIG_IGN);
    }
}
