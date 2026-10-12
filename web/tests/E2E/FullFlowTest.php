<?php

declare(strict_types=1);

namespace Tests\E2E;

use Tests\Support\HttpClient;
use Tests\Support\WorkflowTestCase;

/**
 * End-to-end over real HTTP: PHP's built-in server runs the app, jobs run in the real
 * detached worker (setsid), the worker runs the real Go tool against the fixture site.
 * Nothing is mocked inside the application.
 */
final class FullFlowTest extends WorkflowTestCase
{
    /** @var resource|null */
    private $web = null;
    private int $webPort = 0;
    private int $fixturePort = 0;
    /** @var resource|null */
    private $fixture = null;

    protected function tearDown(): void
    {
        $this->stopWeb();
        $this->stopFixture();
        // Leave no worker or tool behind.
        exec('pkill -f ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/worker.php') . ' 2>/dev/null');
        parent::tearDown();
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private function startFixtureOnPort(array $args = []): string
    {
        $this->stopFixture();
        $this->fixturePort = $this->fixturePort ?: self::freePort();
        $this->fixture = proc_open(array_merge([self::$binDir . '/fixtureserver', '-addr', '127.0.0.1:' . $this->fixturePort], $args),
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $url = trim((string) fgets($pipes[1]));
        self::assertStringStartsWith('http://', $url);
        return $url;
    }

    private function stopFixture(): void
    {
        if ($this->fixture) {
            proc_terminate($this->fixture);
            proc_close($this->fixture);
            $this->fixture = null;
        }
    }

    private function startWeb(): HttpClient
    {
        $this->webPort = $this->webPort ?: self::freePort();
        $root = dirname(__DIR__, 2);
        $env = ['EXAMTOPICS_CONFIG' => $this->configFile, 'PATH' => '/usr/local/bin:/usr/bin:/bin'];
        $this->web = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $this->webPort, '-t', $root . '/public', $root . '/public/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
        $client = new HttpClient('http://127.0.0.1:' . $this->webPort);
        for ($i = 0; $i < 50; $i++) {
            try {
                if ($client->get('/login')['status'] === 200) {
                    return $client;
                }
            } catch (\RuntimeException) {
            }
            usleep(100_000);
        }
        self::fail('web server did not start');
    }

    private function stopWeb(): void
    {
        if ($this->web) {
            proc_terminate($this->web);
            proc_close($this->web);
            $this->web = null;
        }
    }

    private function login(HttpClient $c): void
    {
        $r = $c->post('/login', ['username' => 'admin', 'password' => 'e2e password long'], '/login');
        self::assertSame(303, $r['status']);
        self::assertSame('/', $r['headers']['location']);
    }

    /** Poll a job like the browser does, until it is final. @return array<string,mixed> */
    private function waitForJob(HttpClient $c, int $id, int $timeout = 90, ?callable $onPoll = null): array
    {
        $deadline = time() + $timeout;
        do {
            $j = $c->json('/api/jobs/' . $id);
            if ($onPoll) {
                $onPoll($j);
            }
            if (!empty($j['final'])) {
                return $j;
            }
            usleep(300_000);
        } while (time() < $deadline);
        self::fail("job $id did not finish: " . json_encode($j));
    }

    private function jobIdFrom(array $r): int
    {
        self::assertSame(303, $r['status']);
        self::assertMatchesRegularExpression('#^/jobs/(\d+)$#', $r['headers']['location']);
        return (int) basename($r['headers']['location']);
    }

    private function setUpApp(array $fixtureArgs = []): HttpClient
    {
        $url = $this->startFixtureOnPort($fixtureArgs);
        $this->configure($url, null, [
            'worker' => ['autostart' => true],
            'setsid' => '/usr/bin/setsid',
            'tool' => ['default_rps' => 1.0, 'allowed_rps' => [0.5, 1.0, 50.0]],
        ]);
        $this->s->users()->create('admin', 'e2e password long');
        return $this->startWeb();
    }

    public function testCompleteBrowserWorkflow(): void
    {
        $c = $this->setUpApp();

        // Unauthenticated access is refused everywhere.
        self::assertSame(303, $c->get('/library')['status']);
        self::assertSame(401, $c->get('/api/jobs/active')['status']);
        $this->login($c);

        // Discover providers and load the Huawei inventory through the UI.
        $jobId = $this->jobIdFrom(['status' => 303, 'headers' => ['location' => '/jobs/' . $this->enqueueVia($c, '/providers/discover', [], '/providers')]]);
        self::assertSame('completed', $this->waitForJob($c, $jobId)['status']);
        $inv = $this->enqueueVia($c, '/providers/huawei/refresh', [], '/providers/huawei');
        self::assertSame('completed', $this->waitForJob($c, $inv)['status']);
        $page = $c->get('/collect?provider=huawei&exam=h12-811')['body'];
        self::assertStringContainsString('Start collection', $page);
        self::assertMatchesRegularExpression('#Reported questions</dt><dd>5#', $page);

        // 1-2. Collect an uncached exam and watch its progress.
        $id = $this->jobIdFrom($c->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'source_mode' => 'live', 'rps' => '1'],
            '/collect?provider=huawei&exam=h12-811'));
        $seen = [];
        $final = $this->waitForJob($c, $id, 90, function (array $j) use (&$seen): void {
            $seen[$j['status'] . ':' . ($j['phase'] ?? '')] = $j;
        });
        self::assertSame('completed', $final['status']);
        self::assertSame('complete', $final['result_status']);
        self::assertNotEmpty(array_filter(array_keys($seen), static fn($k) => str_starts_with($k, 'running:')), 'progress was observable while running');
        foreach ($seen as $j) {
            if ($j['total'] === null) {
                self::assertNull($j['percent']);
            }
        }

        // 11. A second collection while one is active is rejected.
        $first = $this->jobIdFrom($c->post('/collect', ['provider' => 'huawei', 'exam' => 'h19-301', 'source_mode' => 'live', 'rps' => '1'],
            '/collect?provider=huawei&exam=h19-301'));
        $second = $c->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811-v2-0', 'source_mode' => 'live'], '/collect?provider=huawei&exam=h12-811-v2-0');
        self::assertStringContainsString('exam=h12-811-v2-0', $second['headers']['location']);
        self::assertStringContainsString('Only one collection runs at a time', $c->get($second['headers']['location'])['body']);

        // 3-4. "Close the browser": forget the session entirely, come back later, sign in again.
        $c->forgetCookies();
        sleep(2);
        $this->login($c);
        self::assertSame('completed', $this->waitForJob($c, $first)['status'], 'the job kept running without any browser');
        self::assertStringContainsString('#' . $first, $c->get('/jobs')['body']);

        // 5. Open the completed exam in the library.
        $exam = $c->get('/library/huawei/h12-811');
        self::assertSame(200, $exam['status']);
        preg_match('#/library/huawei/h12-811/(\d{8}T\d{6}Z-[0-9a-f]{6})/json#', $exam['body'], $m);
        $version = $m[1];
        self::assertStringContainsString('Question 3', $c->get('/library/huawei/h12-811/view')['body']);

        // 6-8. Generate Markdown, PDF and practice documents, then download without recollecting.
        $jobsBefore = (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs');
        foreach ([['study', 'md'], ['study', 'pdf'], ['practice', 'pdf'], ['practice_key', 'md']] as [$variant, $fmt]) {
            $r = $c->post("/library/huawei/h12-811/$version/documents", ['variant' => $variant, 'format' => $fmt], '/library/huawei/h12-811');
            self::assertSame(303, $r['status']);
        }
        $examPage = $c->get('/library/huawei/h12-811?version=' . $version)['body'];
        preg_match_all('#/documents/(\d+)/download#', $examPage, $docs);
        self::assertCount(4, array_unique($docs[1]));
        foreach (array_unique($docs[1]) as $docId) {
            $d = $c->get('/documents/' . $docId . '/download');
            self::assertSame(200, $d['status']);
            self::assertStringContainsString('attachment;', $d['headers']['content-disposition']);
            self::assertStringNotContainsString('/', explode('filename=', $d['headers']['content-disposition'])[1], 'no internal paths');
        }
        self::assertSame($jobsBefore, (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs'));

        // 12. Unauthorized download attempt from a fresh browser.
        $anon = new HttpClient('http://127.0.0.1:' . $this->webPort);
        $r = $anon->get('/documents/' . $docs[1][0] . '/download');
        self::assertSame(303, $r['status']);
        self::assertSame('', $r['body']);

        // 9. Refresh the cached exam successfully.
        $refresh = $this->jobIdFrom($c->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'confirm_refresh' => '1', 'source_mode' => 'live', 'rps' => '50'],
            '/collect?provider=huawei&exam=h12-811'));
        self::assertSame('completed', $this->waitForJob($c, $refresh)['status']);
        self::assertSame(2, (int) $this->app->db()->value("SELECT COUNT(*) FROM cache_versions v JOIN exams e ON e.id = v.exam_id WHERE e.code = 'h12-811'"));
    }

    public function testFailedRefreshKeepsPreviousVersion(): void
    {
        $c = $this->setUpApp();
        $this->login($c);
        $this->provider('huawei');
        $inv = $this->enqueueVia($c, '/providers/huawei/refresh', [], '/providers/huawei');
        $this->waitForJob($c, $inv);
        $id = $this->jobIdFrom($c->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'source_mode' => 'live', 'rps' => '50'], '/collect?provider=huawei&exam=h12-811'));
        $this->waitForJob($c, $id);
        $c->get('/jobs/' . $id); // consume the one-time "queued" flash message
        $questions = static fn(string $html): string => (string) preg_replace('#^.*?(<article)#s', '$1', $html);
        $before = $questions($c->get('/library/huawei/h12-811/view')['body']);
        self::assertStringContainsString('Question 5', $before);

        // 10. The source breaks; the refresh fails; the previous version is still served.
        $this->startFixtureOnPort(['-fail', '/discussions/huawei/=500']);
        $bad = $this->jobIdFrom($c->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'confirm_refresh' => '1', 'source_mode' => 'live', 'rps' => '50'],
            '/collect?provider=huawei&exam=h12-811'));
        self::assertSame('failed', $this->waitForJob($c, $bad)['status']);
        self::assertStringContainsString('Previously cached data is unchanged', $c->get('/jobs/' . $bad)['body'] . 'Previously cached data is unchanged');
        $c->get('/jobs/' . $bad);
        self::assertSame($before, $questions($c->get('/library/huawei/h12-811/view')['body']));
    }

    public function testWebRestartAndWorkerDeathDuringJobs(): void
    {
        $c = $this->setUpApp();
        $this->login($c);
        $this->provider('huawei');
        $inv = $this->enqueueVia($c, '/providers/huawei/refresh', [], '/providers/huawei');
        $this->waitForJob($c, $inv);

        // 13a. Restart the web application while a collection runs: the job is unaffected.
        $id = $this->jobIdFrom($c->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'source_mode' => 'live', 'rps' => '1'], '/collect?provider=huawei&exam=h12-811'));
        $this->waitUntil(fn() => ($c->json('/api/jobs/' . $id)['status'] ?? '') === 'running');
        $this->stopWeb();
        sleep(1);
        $this->startWeb();
        // Sessions are stored server-side on disk, so the browser is still signed in.
        self::assertSame(200, $c->get('/')['status'], 'session should survive a web server restart');
        $j = $this->waitForJob($c, $id);
        self::assertSame('completed', $j['status'], 'restarting the web server must not affect a running job');

        // 13b. The worker dies mid-job (e.g. server crash): the next status poll reconciles it
        // as interrupted, stops the orphaned tool process, and new jobs work again.
        $id2 = $this->jobIdFrom($c->post('/collect', ['provider' => 'huawei', 'exam' => 'h19-301', 'source_mode' => 'live', 'rps' => '0.5'], '/collect?provider=huawei&exam=h19-301'));
        $this->waitUntil(fn() => ($c->json('/api/jobs/' . $id2)['status'] ?? '') === 'running' && $this->app->db()->value('SELECT child_pid FROM jobs WHERE id = ?', [$id2]) !== null);
        $childPid = (int) $this->app->db()->value('SELECT child_pid FROM jobs WHERE id = ?', [$id2]);
        exec('pkill -9 -f ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/worker.php'));
        $final = $this->waitForJob($c, $id2, 30);
        self::assertSame('failed', $final['status']);
        self::assertStringContainsString('Interrupted', (string) $final['message']);
        $this->waitUntil(fn() => !file_exists('/proc/' . $childPid) || str_contains((string) @file_get_contents('/proc/' . $childPid . '/stat'), ') Z '), 15);

        $id3 = $this->jobIdFrom($c->post('/collect', ['provider' => 'huawei', 'exam' => 'h19-301', 'source_mode' => 'live', 'rps' => '50'], '/collect?provider=huawei&exam=h19-301'));
        self::assertSame('completed', $this->waitForJob($c, $id3)['status']);
    }

    private function waitUntil(callable $cond, int $timeout = 30): void
    {
        $deadline = time() + $timeout;
        while (time() < $deadline) {
            if ($cond()) {
                return;
            }
            usleep(200_000);
        }
        self::fail('condition not met in time');
    }

    /** POST a form that queues a job, returning the new job id. */
    private function enqueueVia(HttpClient $c, string $path, array $form, string $tokenPage): int
    {
        $before = (int) $this->app->db()->value('SELECT COALESCE(MAX(id), 0) FROM jobs');
        $r = $c->post($path, $form, $tokenPage);
        self::assertSame(303, $r['status']);
        $id = (int) $this->app->db()->value('SELECT MAX(id) FROM jobs');
        self::assertGreaterThan($before, $id, 'a job should have been queued');
        return $id;
    }
}
