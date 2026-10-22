<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Ids;
use App\Services\Access;
use Tests\Support\HttpTestCase;

/** Accounts are addressed by a random id, never their username, in every URL. */
final class AccountAddressTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
        $this->s->users()->create('ruth', 'ruth password long', Access::MEMBER, ['display_name' => 'Ruth Phiri']);
    }

    public function testLinksUseTheIdAndOldLinksMoveToIt(): void
    {
        $ruth = $this->s->users()->findByUsername('ruth');
        self::assertTrue(Ids::isUuid((string) $ruth['uuid']), 'every account gets one when it is made');

        $list = $this->get('/users')->body;
        self::assertStringContainsString('href="/users/' . $ruth['uuid'] . '"', $list);
        self::assertStringNotContainsString('href="/users/ruth"', $list);
        $page = $this->get('/users/' . $ruth['uuid']);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Ruth Phiri', $page->body);
        self::assertStringNotContainsString('/users/ruth/', $page->body, 'its forms post to the id too');

        // A link from before ids (a bookmark, an old notification) moves to the id.
        self::assertSame('/users/' . $ruth['uuid'], $this->get('/users/ruth')->headers['Location']);
        self::assertSame(404, $this->get('/users/00000000-0000-4000-8000-000000000000')->status);
        self::assertSame(404, $this->get('/users/nobody')->status);

        // Saving goes back to the list; the form posts to the id.
        $r = $this->post('/users/' . $ruth['uuid'], ['display_name' => 'Ruth Banda', 'email' => '', 'role' => 'member', 'status' => 'active']);
        self::assertSame('/users', $r->headers['Location']);
        self::assertSame('Ruth Banda', $this->s->users()->findByUsername('ruth')['display_name']);
    }

    public function testAccountsRestoredWithoutAnIdGetOne(): void
    {
        $this->app->db()->run("UPDATE users SET uuid = NULL WHERE username = 'ruth'");
        self::assertSame(1, $this->s->users()->fillMissingUuids());
        self::assertTrue(Ids::isUuid((string) $this->s->users()->findByUsername('ruth')['uuid']));
        self::assertSame(0, $this->s->users()->fillMissingUuids());
    }
}
