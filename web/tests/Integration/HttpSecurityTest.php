<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Http\Router;
use Tests\Support\HttpTestCase;

final class HttpSecurityTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
    }

    /** Every route except the sign-in page and the branding files must refuse anonymous access. */
    public function testEveryRouteRequiresAuthentication(): void
    {
        $router = new Router();
        (require dirname(__DIR__, 2) . '/routes/web.php')($router);
        $samples = ['slug' => 'huawei', 'provider' => 'huawei', 'exam' => 'h12-811', 'version' => '20261012T083000Z-abcdef',
            'uuid' => '1', 'number' => '1', 'hash' => str_repeat('ab', 32), 'username' => 'someone', 'file' => 'logo.png', 'id' => '1', 'name' => 'examtopics-backup-20261014-000000.zip'];
        $paths = [];
        foreach ($router->routes() as $route) {
            if ($route['public']) {
                continue;
            }
            $paths[] = [$route['method'], preg_replace_callback('/\{([a-z_]+)\}/', static fn($m) => $samples[$m[1]], $route['pattern'])];
        }
        self::assertGreaterThan(40, count($paths));
        foreach ($paths as [$method, $path]) {
            $r = $method === 'GET' ? $this->get($path) : $this->post($path, []);
            if (str_starts_with($path, '/api/')) {
                self::assertSame(401, $r->status, "$method $path");
            } else {
                self::assertSame(303, $r->status, "$method $path");
                self::assertStringStartsWith('/login', $r->headers['Location'], "$method $path");
            }
        }
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs'), 'no anonymous request may create a job');
        self::assertSame(200, $this->get('/login')->status);
    }

    public function testCsrfIsRequiredForStateChanges(): void
    {
        $this->login();
        $r = $this->post('/providers/discover', [], withCsrf: false);
        self::assertSame(403, $r->status);
        $r = $this->post('/providers/discover', ['_csrf' => 'forged-token'], withCsrf: false);
        self::assertSame(403, $r->status);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs'));
        self::assertSame(303, $this->post('/providers/discover')->status);
        self::assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs'));
        // A login POST without a token is refused too (login CSRF): nobody is signed in, and the
        // form comes back with a fresh token and a note, rather than an error page.
        $this->newSession();
        $r = $this->post('/login', ['username' => 'admin', 'password' => 'correct horse battery', 'next' => '/library'], withCsrf: false);
        self::assertSame(303, $r->status);
        self::assertSame('/login?next=%2Flibrary', $r->headers['Location']);
        self::assertStringContainsString('sign-in form had expired', $this->session->takeFlashes()[0]['message']);
        self::assertSame(303, $this->get('/library')->status, 'not signed in');
    }

    public function testLoginThrottlingAndSafeRedirects(): void
    {
        $this->s->users()->create('admin', 'correct horse battery');
        for ($i = 0; $i < 5; $i++) {
            $r = $this->post('/login', ['username' => 'admin', 'password' => 'wrong']);
            self::assertSame(401, $r->status);
            self::assertStringContainsString('Incorrect username or password', $r->body);
        }
        $r = $this->post('/login', ['username' => 'admin', 'password' => 'correct horse battery']);
        self::assertSame(401, $r->status, 'correct password must still be refused while locked');
        self::assertStringContainsString('This account is locked after too many wrong passwords', $r->body);
        // The lock is the account's, so another network is refused too, until it is unlocked.
        $this->ip = '198.51.100.20';
        self::assertStringContainsString('This account is locked', $this->post('/login', ['username' => 'admin', 'password' => 'correct horse battery'])->body);
        $this->s->signInGuard()->unlock((array) $this->s->users()->findByUsername('admin'));

        $r = $this->post('/login', ['username' => 'admin', 'password' => 'correct horse battery', 'next' => '//evil.example/x']);
        self::assertSame('/', $r->headers['Location'], 'protocol-relative next must not be followed');
        $this->newSession();
        $r = $this->post('/login', ['username' => 'admin', 'password' => 'correct horse battery', 'next' => '/library']);
        self::assertSame('/library', $r->headers['Location']);
        // Unknown users get the same message as wrong passwords.
        $this->newSession();
        $r = $this->post('/login', ['username' => 'nobody', 'password' => 'whatever']);
        self::assertStringContainsString('Incorrect username or password', $r->body);
    }

    public function testSecurityHeadersAndNoStackTraces(): void
    {
        $r = $this->get('/login');
        self::assertStringContainsString("script-src 'self'", $r->headers['Content-Security-Policy']);
        self::assertSame('DENY', $r->headers['X-Frame-Options']);
        self::assertSame('no-store, private', $r->headers['Cache-Control']);
        $this->login();
        $r = $this->get('/library/huawei/h12-811');
        self::assertSame(404, $r->status);
        self::assertStringNotContainsString('.php', $r->body);
        self::assertStringNotContainsString('Stack trace', $r->body);
    }

    public function testIdentifiersAreValidated(): void
    {
        $this->login();
        $this->provider('huawei');
        foreach (['/providers/..%2Fetc', '/providers/HUAWEI', '/library/huawei/..', '/library/huawei/h12-811/view',
                  '/library/huawei/h12--811', '/jobs/1%20OR%201=1', '/documents/abc/download',
                  // Row numbers no longer address anything: jobs and documents are found by UUID.
                  '/jobs/1', '/api/jobs/1', '/documents/1/download',
                  '/jobs/00000000-0000-4000-8000-000000000000', '/jobs/AAAAAAAA-0000-4000-8000-000000000000'] as $path) {
            self::assertSame(404, $this->get(rawurldecode($path))->status, $path);
        }
        $r = $this->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811;rm -rf /']);
        self::assertSame(404, $r->status);
        $r = $this->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811']);
        self::assertSame(404, $r->status, 'an exam unknown to the inventory cannot be collected');
        $r = $this->post('/providers/add', ['slug' => 'bad slug!']);
        self::assertSame(303, $r->status);
        self::assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM providers WHERE slug LIKE 'bad%'"));
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs'));
    }

    public function testCacheFirstAndSingleCollection(): void
    {
        $this->login();
        $huawei = $this->provider('huawei');
        $this->s->jobService()->enqueueInventory($huawei);
        $this->drain();

        $r = $this->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'source_mode' => 'live', 'rps' => '50']);
        self::assertSame(303, $r->status);
        self::assertMatchesRegularExpression('#^/jobs/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#', $r->headers['Location'], 'job URLs use UUIDs');
        // Duplicate submission of the same exam while it is queued: rejected, still one job.
        $this->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'source_mode' => 'live']);
        self::assertSame('warning', $this->flashes()[1]['type'] ?? $this->flashes()[0]['type'] ?? null);
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM jobs WHERE type = 'collect'"));
        // Another exam can join the queue; this one shows where it is.
        self::assertStringContainsString('Add to the queue', $this->get('/collect', ['provider' => 'huawei', 'exam' => 'h19-301'])->body);
        self::assertStringContainsString('Already in the queue', $this->get('/collect', ['provider' => 'huawei', 'exam' => 'h12-811'])->body);
        $this->drain();

        // Cached now: a plain submission must not start a new collection.
        $before = (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs');
        $r = $this->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811']);
        self::assertSame(303, $r->status);
        self::assertStringContainsString('exam=h12-811', $r->headers['Location']);
        self::assertSame($before, (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs'));
        // The collect page offers the cached version.
        $page = $this->get('/collect', ['provider' => 'huawei', 'exam' => 'h12-811']);
        self::assertStringContainsString('Open cached exam', $page->body);
        self::assertStringContainsString('Refresh this exam', $page->body);
        // With explicit confirmation, a refresh is queued.
        $this->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'confirm_refresh' => '1', 'rps' => '50']);
        self::assertSame($before + 1, (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs'));
        // Unverified options are not accepted: an unknown rps falls back to the default.
        $opts = json_decode((string) $this->app->db()->value('SELECT options FROM jobs ORDER BY id DESC LIMIT 1'), true);
        self::assertSame(50.0, (float) $opts['rps']);
    }

    public function testJobStatusApiReportsUnknownTotalsHonestly(): void
    {
        $this->login();
        $id = $this->s->jobService()->enqueueProviders();
        $this->app->db()->run("UPDATE jobs SET status='running', phase='listing_pages', progress_done=3, progress_total=NULL WHERE id=?", [$id]);
        $json = json_decode($this->get('/api/jobs/' . $this->s->jobs()->find($id)['uuid'])->body, true);
        self::assertSame(3, $json['done']);
        self::assertNull($json['total']);
        self::assertNull($json['percent'], 'no percentage may be invented without a total');
        $this->app->db()->run('UPDATE jobs SET progress_total = 12 WHERE id = ?', [$id]);
        self::assertSame(25, json_decode($this->get('/api/jobs/' . $this->s->jobs()->find($id)['uuid'])->body, true)['percent']);
    }

    public function testRouterRejectsWrongMethod(): void
    {
        $this->login();
        self::assertSame(405, $this->kernel()->handle(new \App\Core\Http\Request('DELETE', '/library', [], [], []))->status);
        self::assertInstanceOf(Router::class, new Router());
    }
}
