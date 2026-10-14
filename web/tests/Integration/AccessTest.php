<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Http\Router;
use App\Services\Access;
use App\Services\Branding;
use Tests\Support\HttpTestCase;

/** Roles, permission gates, user management and branding. */
final class AccessTest extends HttpTestCase
{
    private const PW = 'member password long';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
    }

    /** Every route declares who may use it, and every permission the code checks exists. */
    public function testEveryGateIsInTheCatalogue(): void
    {
        $router = new Router();
        (require dirname(__DIR__, 2) . '/routes/web.php')($router);
        foreach ($router->routes() as $r) {
            if ($r['public']) {
                self::assertMatchesRegularExpression('#^/(login|register|verify|forgot|reset|branding/)#', $r['pattern'], 'unexpected public route');
                continue;
            }
            self::assertNotNull($r['can'], $r['method'] . ' ' . $r['pattern'] . ' names no permission');
            self::assertTrue($r['can'] === Access::SIGNED_IN || isset(Access::PERMISSIONS[$r['can']]), $r['can']);
        }
        $code = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app')) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $code .= file_get_contents($f->getPathname());
            }
        }
        preg_match_all("/\\bcan\\('([a-z_.-]+)'\\)/", $code, $m);
        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $perm) {
            self::assertArrayHasKey($perm, Access::PERMISSIONS, "can('$perm') is not in the catalogue");
        }
    }

    public function testMembersReadButDoNotCollectOrAdminister(): void
    {
        $this->login('member', self::PW, Access::MEMBER);
        foreach (['/', '/library', '/providers', '/account'] as $path) {
            self::assertSame(200, $this->get($path)->status, $path);
        }
        foreach (['/collect', '/jobs', '/settings', '/users', '/settings/branding'] as $path) {
            $r = $this->get($path);
            self::assertSame(403, $r->status, $path);
        }
        self::assertStringContainsString('&quot;exams.collect&quot; permission', $this->get('/collect')->body, 'the refusal names what is missing');
        self::assertSame(403, $this->post('/providers/discover')->status);
        self::assertSame(403, $this->post('/providers/add', ['slug' => 'cisco'])->status);
        self::assertSame(403, $this->get('/api/jobs/active')->status);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs'));

        $page = $this->get('/')->body;
        self::assertStringNotContainsString('href="/collect"', $page, 'the menu hides what the server refuses');
        self::assertStringNotContainsString('href="/users"', $page);
        self::assertStringNotContainsString('active-indicator', $page);
        self::assertStringContainsString('href="/account"', $page);
    }

    /** A member may load a provider's exam list once, while it has never been fetched. */
    public function testMembersRefreshOnlyProvidersNeverFetched(): void
    {
        $this->s->providers()->upsert('huawei', 'Huawei', 'manual');
        $this->login('member', self::PW, Access::MEMBER);
        self::assertStringContainsString('Load exam list', $this->get('/providers/huawei')->body);
        self::assertStringContainsString('Load exam list', $this->get('/providers')->body);

        $r = $this->post('/providers/huawei/refresh');
        self::assertSame(303, $r->status);
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM jobs WHERE type = 'inventory'"));
        $this->drain();

        self::assertStringNotContainsString('Load exam list', $this->get('/providers/huawei')->body);
        self::assertStringNotContainsString('Load exam list', $this->get('/providers')->body);
        self::assertSame(403, $this->post('/providers/huawei/refresh')->status);
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM jobs WHERE type = 'inventory'"));
    }

    public function testExtraPermissionsAndTheReader(): void
    {
        $this->login();
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        $version = $this->s->versions()->find((int) $this->s->jobs()->find($id)['cache_version_id'])['version'];

        $this->newSession();
        $this->login('member', self::PW, Access::MEMBER);
        $reader = $this->get('/library/huawei/h12-811/view')->body;
        self::assertStringContainsString('question', strtolower($reader));
        self::assertStringNotContainsString('answer-edit', $reader, 'members cannot correct answers');
        $exam = $this->get('/library/huawei/h12-811')->body;
        self::assertStringContainsString('Not built yet', $exam);
        self::assertStringNotContainsString('Generate', $exam);
        self::assertSame(403, $this->post('/library/huawei/h12-811/' . $version . '/documents', ['variant' => 'study', 'format' => 'md'])->status);
        self::assertSame(403, $this->post('/library/huawei/h12-811/answers', ['key' => '1-1'])->status);

        // An administrator gives this member document building on top of their role.
        $member = $this->s->users()->findByUsername('member');
        $this->s->users()->update((int) $member['id'], ['permissions' => ['documents.build']]);
        self::assertSame(303, $this->post('/library/huawei/h12-811/' . $version . '/documents', ['variant' => 'study', 'format' => 'md'])->status);
        self::assertStringContainsString('Generate', $this->get('/library/huawei/h12-811')->body);
    }

    public function testUserManagement(): void
    {
        $this->login();
        $r = $this->post('/users', ['username' => 'reader', 'display_name' => 'Chikondi', 'email' => 'Reader@Example.com',
            'role' => 'member', 'status' => 'active', 'password' => 'reader password 1', 'permissions' => ['answers.edit', 'library.view', 'bogus']]);
        self::assertSame(303, $r->status);
        $u = $this->s->users()->findByUsername('reader');
        self::assertSame('reader@example.com', $u['email'], 'addresses are stored in lower case');
        self::assertSame(['answers.edit'], Access::extras($u), 'only extras the role does not give, from the catalogue');
        self::assertTrue(Access::can($u, 'answers.edit'));
        self::assertFalse(Access::can($u, 'users.manage'));

        self::assertSame(422, $this->post('/users', ['username' => 'reader', 'password' => 'another long one'])->status, 'usernames are unique');
        self::assertSame(422, $this->post('/users', ['username' => 'x', 'password' => 'another long one'])->status);
        self::assertSame(422, $this->post('/users', ['username' => 'third', 'email' => 'reader@example.com', 'password' => 'another long one'])->status, 'emails are unique');

        // Your own role and status are not changed from the users page.
        $this->post('/users/admin', ['role' => 'member', 'status' => 'disabled', 'display_name' => 'Owner']);
        $me = $this->s->users()->findByUsername('admin');
        self::assertSame(['admin', 'active', 'Owner'], [$me['role'], $me['status'], $me['display_name']]);
        self::assertSame(303, $this->post('/users/admin/delete')->status);
        self::assertNotNull($this->s->users()->findByUsername('admin'), 'you cannot delete yourself');

        // A member trusted with users.manage still cannot remove the last administrator.
        $this->s->users()->update((int) $u['id'], ['permissions' => ['users.manage']]);
        $this->newSession();
        $this->login('reader', 'reader password 1');
        self::assertSame(200, $this->get('/users')->status);
        $r = $this->post('/users/admin', ['role' => 'member', 'status' => 'active']);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('only active administrator', $r->body);
        self::assertSame('admin', $this->s->users()->findByUsername('admin')['role']);
    }

    public function testDisabledAccountsAreSignedOutAndCannotSignIn(): void
    {
        $id = $this->s->users()->create('member', self::PW, Access::MEMBER, ['email' => 'm@example.com']);
        $r = $this->post('/login', ['username' => 'M@example.com', 'password' => self::PW]);
        self::assertSame(303, $r->status, 'an email address signs in too');
        self::assertSame(200, $this->get('/library')->status);

        $this->s->users()->update($id, ['status' => 'disabled']);
        $r = $this->get('/library');
        self::assertSame(303, $r->status, 'a disabled account stops working on its next request');
        self::assertStringStartsWith('/login', $r->headers['Location']);

        self::assertStringContainsString('Incorrect username or password', $this->post('/login', ['username' => 'member', 'password' => 'wrong password'])->body);
        self::assertStringContainsString('disabled', $this->post('/login', ['username' => 'member', 'password' => self::PW])->body,
            'the state is only told to someone who knows the password');
    }

    public function testBranding(): void
    {
        $this->login();
        self::assertSame(303, $this->post('/settings/branding', ['name' => 'Kaunda Exams', 'tagline' => 'Study <well>', 'accent' => 'custom', 'color' => '#ffee00', 'theme' => 'dark'])->status);
        $page = $this->get('/')->body;
        self::assertStringContainsString('Kaunda Exams</span>', $page);
        self::assertStringContainsString('data-accent="custom"', $page);
        self::assertStringContainsString('/branding/theme.css', $page);

        $this->newSession();
        $login = $this->get('/login')->body;
        self::assertStringContainsString('Kaunda Exams', $login, 'the sign-in page carries the branding');
        self::assertStringContainsString('Study &lt;well&gt;', $login);
        self::assertStringContainsString('data-theme-choice="dark"', $login);
        $css = $this->get('/branding/theme.css');
        self::assertSame(200, $css->status, 'reachable before sign-in');
        self::assertStringContainsString('[data-accent="custom"]', $css->body);
        self::assertSame(1, preg_match('/--color-primary: (#[0-9A-F]{6});/', $css->body, $m));
        $rgb = sscanf($m[1], '#%02x%02x%02x');
        self::assertGreaterThanOrEqual(4.5, Branding::contrast($rgb, [255, 255, 255]), 'a pale colour is darkened until it reads on white');

        // A logo: a large photo is scaled down to a PNG and served publicly; names are checked.
        $this->login();
        $img = imagecreatetruecolor(1600, 400);
        $file = $this->app->paths()->storage() . '/upload.jpg';
        imagejpeg($img, $file);
        $r = $this->postFiles('/settings/branding', ['name' => 'Kaunda Exams', 'accent' => 'teal'], ['logo' => ['name' => 'logo.jpg', 'tmp_name' => $file, 'size' => filesize($file), 'error' => UPLOAD_ERR_OK]]);
        self::assertSame(303, $r->status);
        self::assertSame('success', $this->flashes()[0]['type']);
        $logo = $this->s->branding()->get()['logo'];
        self::assertMatchesRegularExpression('/^logo_\d+_[0-9a-f]{4}\.png$/', (string) $logo);
        [$w] = getimagesize($this->s->branding()->path($logo));
        self::assertSame(Branding::LOGO_MAX_SIDE, $w);
        self::assertStringContainsString('src="/branding/' . $logo . '?v=', $this->get('/')->body);
        $this->newSession();
        $served = $this->get('/branding/' . $logo);
        self::assertSame(200, $served->status);
        self::assertSame('image/png', $served->headers['Content-Type']);
        foreach (['..%2Fconfig.php', 'logo_1.php', '.htaccess'] as $bad) {
            self::assertSame(404, $this->get('/branding/' . rawurldecode($bad))->status, $bad);
        }

        $this->login();
        file_put_contents($file, "\0\0\0\x18ftypheic....");
        $this->postFiles('/settings/branding', ['accent' => 'teal'], ['logo' => ['name' => 'IMG_1.HEIC', 'tmp_name' => $file, 'size' => 20, 'error' => UPLOAD_ERR_OK]]);
        self::assertStringContainsString('HEIC', $this->flashes()[0]['message']);
        self::assertSame($logo, $this->s->branding()->get()['logo'], 'a refused upload keeps the current logo');
    }
}
