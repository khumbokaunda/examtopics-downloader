<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\App;
use App\Core\Config;
use App\Services\Services;
use App\Services\Worker;

/**
 * Integration tests that run the real Go tool against the fixture ExamTopics site
 * (tests/fixtureserver). Binaries are built once per test run.
 */
abstract class WorkflowTestCase extends DbTestCase
{
    protected static string $binDir = '';
    /** @var list<resource> */
    private array $servers = [];
    protected Services $s;
    protected string $configFile = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$binDir === '') {
            self::$binDir = sys_get_temp_dir() . '/examtopics-test-bin';
            @mkdir(self::$binDir, 0755, true);
            $repo = dirname(__DIR__, 3);
            foreach (['examtopics-downloader' => './cmd', 'fixtureserver' => './tests/fixtureserver'] as $out => $pkg) {
                $cmd = sprintf('cd %s && go build -o %s %s 2>&1', escapeshellarg($repo), escapeshellarg(self::$binDir . '/' . $out), escapeshellarg($pkg));
                exec($cmd, $output, $code);
                if ($code !== 0) {
                    throw new \RuntimeException("go build failed:\n" . implode("\n", $output));
                }
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $proc) {
            proc_terminate($proc);
            proc_close($proc);
        }
        $this->servers = [];
        $this->tearDownConfig();
        parent::tearDown();
    }

    /** Start a fixture site; returns its base URL. @param list<string> $args */
    protected function startFixture(array $args = []): string
    {
        $proc = proc_open(array_merge([self::$binDir . '/fixtureserver', '-addr', '127.0.0.1:0'], $args),
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $url = trim((string) fgets($pipes[1]));
        if (!str_starts_with($url, 'http://')) {
            throw new \RuntimeException('fixture server did not start');
        }
        $this->servers[] = $proc;
        return $url;
    }

    /**
     * Point the app (and any process it starts) at a fixture site and a tool binary, via a
     * generated config file selected with EXAMTOPICS_CONFIG.
     *
     * @param array<string,mixed> $overrides
     */
    protected function configure(string $fixtureUrl, ?string $tool = null, array $overrides = []): void
    {
        $base = require dirname(__DIR__) . '/config.test.php';
        $cfg = array_replace_recursive($base, [
            'tool' => [
                'binary' => $tool ?? self::$binDir . '/examtopics-downloader',
                'extra_env' => ['EXAMTOPICS_BASE_URL' => $fixtureUrl, 'EXAMTOPICS_GITHUB_API' => $fixtureUrl . '/gh'],
                'default_rps' => 1.0,
                'allowed_rps' => [0.25, 0.5, 1.0, 50.0],
                'kill_grace' => 2,
            ],
            'worker' => ['autostart' => false],
            'php_cli' => PHP_BINARY,
        ], $overrides);
        $this->configFile = sys_get_temp_dir() . '/examtopics-test-config-' . getmypid() . '.php';
        file_put_contents($this->configFile, '<?php return ' . var_export($cfg, true) . ';');
        putenv('EXAMTOPICS_CONFIG=' . $this->configFile);
        $this->app = App::boot(Config::load());
        $this->s = new Services($this->app);
    }

    /** Run the worker in this process until the queue is empty. */
    protected function drain(): void
    {
        (new Worker(Config::load()))->run(false);
    }

    /** @return array<string,mixed> */
    protected function provider(string $slug): array
    {
        $this->s->providers()->upsert($slug, ucfirst($slug), 'manual');
        return (array) $this->s->providers()->findBySlug($slug);
    }

    protected function fakeTool(string $name, string $shellBody): string
    {
        $path = sys_get_temp_dir() . '/examtopics-fake-' . $name . '-' . getmypid();
        file_put_contents($path, "#!/bin/sh\n" . $shellBody . "\n");
        chmod($path, 0755);
        return $path;
    }

    protected function tearDownConfig(): void
    {
        putenv('EXAMTOPICS_CONFIG=' . dirname(__DIR__) . '/config.test.php');
    }
}
