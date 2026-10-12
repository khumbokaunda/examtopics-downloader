<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Paths;
use App\Repositories\JobRepository;

/**
 * Keeps the background worker alive and the job table honest.
 *
 * - ensure() is cheap and called by web requests (job submission, status polling). If no
 *   worker holds the lock, it reconciles interrupted jobs and starts a worker when jobs
 *   are queued. A crashed worker is therefore replaced by the next poll.
 * - failInterruptedJobs() must only run while holding the worker lock.
 */
final class WorkerSupervisor
{
    public const INTERRUPTED_MESSAGE =
        'Interrupted: the worker stopped before this job finished (process ended or server restarted). '
        . 'It was not retried automatically. Previously cached data is unchanged; start the job again if needed.';

    public function __construct(
        private Config $config,
        private Paths $paths,
        private JobRepository $jobs,
        private Logger $log,
    ) {
    }

    public function lock(): WorkerLock
    {
        return new WorkerLock($this->paths->workerLock());
    }

    public function workerRunning(): bool
    {
        return $this->lock()->isHeld();
    }

    /** @return string 'running' | 'started' | 'queued' | 'idle' | 'start-failed' */
    public function ensure(): string
    {
        $lock = $this->lock();
        if (!$lock->acquire()) {
            return 'running';
        }
        try {
            $this->failInterruptedJobs();
            $queued = $this->jobs->hasQueued();
        } finally {
            $lock->release();
        }
        if (!$queued) {
            return 'idle';
        }
        if (!(bool) $this->config->get('worker.autostart', true)) {
            return 'queued'; // a systemd-managed --daemon worker (or a test) will pick it up
        }
        return $this->launch() ? 'started' : 'start-failed';
    }

    /** Mark jobs left "running" by a dead worker as failed, stopping any orphaned tool process. */
    public function failInterruptedJobs(): int
    {
        $n = 0;
        foreach ($this->jobs->running() as $job) {
            $this->stopOrphan($job);
            $this->jobs->finish((int) $job['id'], 'failed', 'Interrupted', self::INTERRUPTED_MESSAGE);
            $this->log->warning('jobs', 'Reconciled interrupted job', ['job_id' => (int) $job['id'], 'exam' => $job['exam_code']]);
            $n++;
        }
        return $n;
    }

    /** Start a detached worker that drains the queue and exits. */
    public function launch(): bool
    {
        $setsid = (string) $this->config->get('setsid', '/usr/bin/setsid');
        $php = (string) $this->config->get('php_cli', PHP_BINDIR . '/php');
        $script = dirname(__DIR__, 2) . '/bin/worker.php';
        if (!is_executable($setsid) || !is_executable($php)) {
            $this->log->error('jobs', 'Cannot launch worker: setsid or PHP CLI not executable', ['setsid' => $setsid, 'php' => $php]);
            return false;
        }
        // setsid -f forks into a new session and returns at once: the worker is detached
        // from the web server (a php-fpm restart does not touch it) and is reparented to
        // init, which reaps it, so no zombie processes accumulate.
        $proc = proc_open(
            array_merge([$setsid, '-f'], Process::withClosedFds([$php, $script, '--drain'])),
            [0 => ['file', '/dev/null', 'r'],
             1 => ['file', $this->paths->logsRoot() . '/worker.log', 'a'],
             2 => ['file', $this->paths->logsRoot() . '/worker.log', 'a']],
            $pipes,
            dirname(__DIR__, 2),
            self::workerEnv()
        );
        if (!is_resource($proc)) {
            $this->log->error('jobs', 'proc_open failed launching worker');
            return false;
        }
        proc_close($proc);
        return true;
    }

    /**
     * Environment for the PHP worker: a minimal base plus every EXAMLIB_* variable, so a
     * database configured through the web server's environment reaches the worker too.
     *
     * @return array<string,string>
     */
    public static function workerEnv(): array
    {
        $env = self::baseEnv();
        foreach (getenv() as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'EXAMLIB_') && is_string($v)) {
                $env[$k] = $v;
            }
        }
        return $env;
    }

    /**
     * Environment for the Go tool: nothing inherited except the GitHub token (never passed
     * on the command line, where `ps` would show it) and test-only overrides that must be
     * set explicitly in configuration.
     *
     * @return array<string,string>
     */
    public static function toolEnv(Config $config): array
    {
        $env = self::baseEnv();
        $token = (string) $config->get('github_token', '');
        if ($token !== '') {
            $env['EXAMTOPICS_GITHUB_TOKEN'] = $token;
        }
        foreach ((array) $config->get('tool.extra_env', []) as $k => $v) {
            if (is_string($k) && preg_match('/^EXAMTOPICS_(BASE_URL|GITHUB_API)$/', $k) === 1 && is_string($v)) {
                $env[$k] = $v;
            }
        }
        return $env;
    }

    /** @return array<string,string> */
    private static function baseEnv(): array
    {
        return [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'LANG' => 'C.UTF-8',
            'HOME' => sys_get_temp_dir(),
        ];
    }

    /** @param array<string,mixed> $job */
    private function stopOrphan(array $job): void
    {
        $pid = (int) ($job['child_pid'] ?? 0);
        if ($pid <= 0 || !function_exists('posix_kill') || !$this->isToolProcess($pid)) {
            return;
        }
        posix_kill($pid, 15);
        $deadline = microtime(true) + (int) $this->config->get('tool.kill_grace', 10);
        while (microtime(true) < $deadline && $this->isToolProcess($pid)) {
            usleep(200_000);
        }
        if ($this->isToolProcess($pid)) {
            posix_kill($pid, 9);
        }
        $this->log->warning('jobs', 'Stopped orphaned tool process', ['job_id' => (int) $job['id'], 'pid' => $pid]);
    }

    /** Only signal a PID that is still our tool (PIDs are reused). */
    private function isToolProcess(int $pid): bool
    {
        $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
        if (!is_string($cmd) || $cmd === '') {
            return false;
        }
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if (is_string($stat) && preg_match('/\)\s+Z\s/', $stat) === 1) {
            return false; // zombie: already exited
        }
        $argv0 = explode("\0", $cmd)[0];
        $tool = (string) $this->config->get('tool.binary');
        return $argv0 === $tool || (realpath($argv0) !== false && realpath($argv0) === realpath($tool));
    }
}
