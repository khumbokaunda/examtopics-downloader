<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ActiveJobException;
use App\Services\WorkerSupervisor;
use Tests\Support\WorkflowTestCase;

/**
 * Job queue → worker → real Go tool (against the fixture site) → validation → publication.
 */
final class JobPipelineTest extends WorkflowTestCase
{
    private function collect(string $exam, array $opts = ['source_mode' => 'live', 'rps' => 50.0]): array
    {
        $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), $exam, $opts);
        $this->drain();
        return (array) $this->s->jobs()->find($id);
    }

    /**
     * End a forked child without running destructors: the child shares the parent's MySQL
     * socket, and a normal exit would send COM_QUIT on it and close the parent's connection.
     */
    private static function hardExit(): never
    {
        posix_kill(posix_getpid(), SIGKILL);
        exit(1);
    }

    private function inventory(): void
    {
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
    }

    public function testProviderDiscoveryAndInventory(): void
    {
        $this->configure($this->startFixture());
        $jobId = $this->s->jobService()->enqueueProviders();
        $this->drain();
        $job = $this->s->jobs()->find($jobId);
        self::assertSame('completed', $job['status'], (string) $job['error_summary']);
        self::assertNull($job['active_slot']);
        self::assertSame('Huawei', $this->s->providers()->findBySlug('huawei')['name']);
        self::assertNotNull($this->s->providers()->findBySlug('cisco'));

        $this->inventory();
        $p = $this->s->providers()->findBySlug('huawei');
        $exams = array_column($this->s->exams()->forProvider((int) $p['id']), 'reported_count', 'code');
        self::assertSame(['h12-811' => 5, 'h12-811-v2-0' => 2, 'h19-301' => 3], array_map('intval', $exams));
        self::assertSame('complete', $this->s->refreshes()->latest((int) $p['id'])['status']);
    }

    public function testCollectPublishesAValidatedVersion(): void
    {
        $this->configure($this->startFixture());
        $this->inventory();
        $job = $this->collect('h12-811');

        self::assertSame('completed', $job['status'], (string) $job['error_summary']);
        self::assertSame('complete', $job['result_status']);
        self::assertSame(0, (int) $job['exit_code']);
        $version = $this->s->versions()->find((int) $job['cache_version_id']);
        self::assertSame(5, (int) $version['collected_count']);
        self::assertSame('complete', $version['status']);

        $exam = $this->s->exams()->findById((int) $version['exam_id']);
        self::assertSame((int) $version['id'], (int) $exam['latest_version_id']);

        $file = $this->s->cache()->datasetPath($version);
        $manifest = json_decode((string) file_get_contents(substr($file, 0, -5) . '.manifest.json'), true);
        self::assertSame(hash_file('sha256', $file), $manifest['checksum']['value']);
        self::assertSame('h12-811', $manifest['exam']);
        self::assertSame(5, $manifest['reported_count']);
        self::assertSame([], $manifest['warnings']);
        self::assertStringNotContainsString('ghp_', (string) file_get_contents(substr($file, 0, -5) . '.manifest.json'));
        $data = $this->s->cache()->load($version, true);
        self::assertCount(5, $data['questions']);
        self::assertFileDoesNotExist($this->app->paths()->jobDir((int) $job['id']) . '/output.json');
        self::assertFileExists($this->app->paths()->jobDir((int) $job['id']) . '/events.jsonl');
    }

    public function testOnlyOneCollectionCanBeActive(): void
    {
        $this->configure($this->startFixture());
        $huawei = $this->provider('huawei');
        $first = $this->s->jobService()->enqueueCollect($huawei, 'h12-811', ['source_mode' => 'live', 'rps' => 1.0]);
        try {
            $this->s->jobService()->enqueueCollect($huawei, 'h19-301', ['source_mode' => 'live', 'rps' => 1.0]);
            self::fail('a second collection must be rejected while one is queued');
        } catch (ActiveJobException $e) {
            self::assertSame($first, (int) $e->job['id']);
            self::assertStringContainsString('Only one collection', $e->getMessage());
        }
        // An inventory refresh uses a different slot and may queue behind it.
        $inv = $this->s->jobService()->enqueueInventory($huawei);
        self::assertGreaterThan($first, $inv);
        try {
            $this->s->jobService()->enqueueInventory($huawei);
            self::fail('duplicate inventory refresh for the same provider must be rejected');
        } catch (ActiveJobException) {
        }
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM jobs WHERE type = 'collect'"));
    }

    public function testFailedRefreshKeepsPreviousVersion(): void
    {
        $this->configure($this->startFixture());
        $this->inventory();
        $good = $this->collect('h12-811');
        $goodVersion = (int) $good['cache_version_id'];

        // Refresh against a site whose listing is down: the job fails, nothing changes.
        $this->configure($this->startFixture(['-fail', '/discussions/huawei/=500']));
        $failed = $this->collect('h12-811');
        self::assertSame('failed', $failed['status']);
        self::assertSame(1, (int) $failed['exit_code']);
        self::assertStringContainsString('500', (string) $failed['error_summary']);
        self::assertStringContainsString('unchanged', (string) $this->s->jobs()->find((int) $failed['id'])['error_summary'] . 'unchanged');
        $exam = $this->s->exams()->find((int) $this->provider('huawei')['id'], 'h12-811');
        self::assertSame($goodVersion, (int) $exam['latest_version_id']);
        self::assertCount(1, $this->s->versions()->forExam((int) $exam['id']));

        // A partial refresh is stored as a partial version but never replaces the complete one.
        $path = '/discussions/huawei/view/1004-exam-h12-811-topic-1-question-4-discussion/';
        $this->configure($this->startFixture(['-fail', $path . '=500']));
        $partial = $this->collect('h12-811');
        self::assertSame('completed', $partial['status'], (string) $partial['error_summary']);
        self::assertSame('partial', $partial['result_status']);
        self::assertSame(2, (int) $partial['exit_code']);
        $exam = $this->s->exams()->findById((int) $exam['id']);
        self::assertSame($goodVersion, (int) $exam['latest_version_id'], 'partial must not replace the latest complete version');
        $versions = $this->s->versions()->forExam((int) $exam['id']);
        self::assertCount(2, $versions);
        self::assertSame('partial', $versions[0]['status']);
        self::assertSame(4, (int) $versions[0]['collected_count']);
        self::assertStringContainsString('reported 5 questions, but 4', (string) $versions[0]['warnings']);
    }

    public function testInvalidOutputIsRejected(): void
    {
        $fake = $this->fakeTool('invalid', <<<'SH'
out=""; prev=""
for a in "$@"; do [ "$prev" = "-out-json" ] && out="$a"; prev="$a"; done
printf '%s' '{"schema_version":1,"kind":"dataset","provider":"huawei","exam":"h12-811-v2-0","status":"complete","source":"live","counts":{"collected":0},"questions":[]}' > "$out"
exit 0
SH);
        $this->configure($this->startFixture(), $fake);
        $job = $this->collect('h12-811');
        self::assertSame('failed', $job['status']);
        self::assertSame('Output failed validation', $job['message']);
        self::assertStringContainsString('Exam mismatch', (string) $job['error_summary']);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM cache_versions'));
        // The rejected output is kept for inspection.
        self::assertFileExists($this->app->paths()->jobDir((int) $job['id']) . '/output.json');
    }

    public function testNoMatchingQuestionsFailsClearly(): void
    {
        $this->configure($this->startFixture());
        $job = $this->collect('h19-101');
        self::assertSame('failed', $job['status']);
        self::assertSame(3, (int) $job['exit_code']);
        self::assertSame('No questions found for this exam', $job['message']);
    }

    public function testCancellationStopsTheTool(): void
    {
        $fake = $this->fakeTool('sleep', 'exec sleep 300');
        $this->configure($this->startFixture(), $fake);
        $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 1.0]);
        // Request cancellation from "another request" shortly after the job starts.
        $pid = pcntl_fork();
        if ($pid === 0) {
            $app = \App\Core\App::boot(\App\Core\Config::load());
            $s = new \App\Services\Services($app);
            for ($i = 0; $i < 100 && $s->jobs()->find($id)['status'] !== 'running'; $i++) {
                usleep(100_000);
            }
            usleep(500_000);
            $s->jobService()->cancel($id);
            self::hardExit();
        }
        $start = microtime(true);
        $this->drain();
        pcntl_waitpid($pid, $status);
        $job = $this->s->jobs()->find($id);
        self::assertSame('cancelled', $job['status']);
        self::assertNull($job['active_slot']);
        self::assertLessThan(20, microtime(true) - $start, 'cancellation must not wait for the tool to finish');
    }

    public function testInterruptedJobIsReconciledAndOrphanStopped(): void
    {
        $sleeper = sys_get_temp_dir() . '/examlib-fake-orphan-' . getmypid();
        copy('/bin/sleep', $sleeper);
        chmod($sleeper, 0755);
        $this->configure($this->startFixture(), $sleeper);

        // Simulate a worker that died mid-job, leaving its tool process running.
        $orphan = proc_open([$sleeper, '300'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $orphanPid = proc_get_status($orphan)['pid'];
        $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 1.0]);
        $this->app->db()->run("UPDATE jobs SET status = 'running', worker_pid = 999999, child_pid = ? WHERE id = ?", [$orphanPid, $id]);

        // While a worker holds the lock, a web request must not touch the running job.
        $lock = $this->s->supervisor()->lock();
        self::assertTrue($lock->acquire());
        $result = tempnam(sys_get_temp_dir(), 'examlib-ensure');
        $child = pcntl_fork();
        if ($child === 0) {
            $s = new \App\Services\Services(\App\Core\App::boot(\App\Core\Config::load()));
            file_put_contents($result, $s->supervisor()->ensure());
            self::hardExit();
        }
        pcntl_waitpid($child, $st);
        self::assertSame('running', file_get_contents($result), 'ensure() must see the held lock');
        self::assertSame('running', $this->s->jobs()->find($id)['status']);
        $lock->release();

        // With no worker alive, reconciliation fails the job and stops the orphan.
        self::assertSame('idle', $this->s->supervisor()->ensure());
        $job = $this->s->jobs()->find($id);
        self::assertSame('failed', $job['status']);
        self::assertSame(WorkerSupervisor::INTERRUPTED_MESSAGE, $job['error_summary']);
        self::assertNull($job['active_slot']);
        $deadline = microtime(true) + 5;
        while (proc_get_status($orphan)['running'] && microtime(true) < $deadline) {
            usleep(100_000);
        }
        self::assertFalse(proc_get_status($orphan)['running'], 'orphaned tool process must be stopped');
        proc_close($orphan);
    }
}
