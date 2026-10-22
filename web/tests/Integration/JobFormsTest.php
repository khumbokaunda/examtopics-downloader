<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use Tests\Support\HttpTestCase;

/**
 * Starting a job (collect an exam, refresh an inventory) keeps you on the page: the job shows
 * in place of the button, so several can be started one after another.
 */
final class JobFormsTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
    }

    public function testARefreshStaysOnThePageItWasStartedFrom(): void
    {
        $this->s->providers()->upsert('huawei', 'Huawei', 'manual');
        $r = $this->post('/providers/huawei/refresh', ['back' => '/providers']);
        self::assertSame('/providers', $r->headers['Location'], 'back to the list, not the provider page');
        self::assertSame('Inventory refresh for Huawei queued.', $this->flashes()[0]['message']);
        $list = $this->get('/providers')->body;
        self::assertStringContainsString('data-job-chip', $list, 'the job shows where the button was');
        self::assertStringContainsString('In the queue', $list);
        self::assertStringNotContainsString('>Refresh inventory</button>', $list);

        // Another site is never a place to go back to.
        $this->drain();
        foreach (['//evil.example/providers', 'https://evil.example/', '/\\evil.example', '/jobs'] as $back) {
            self::assertSame('/providers/huawei', $this->post('/providers/huawei/refresh', ['back' => $back])->headers['Location'], $back);
            $this->drain();
        }
    }

    public function testThePagesScriptGetsTheJobToShowInPlace(): void
    {
        $this->s->providers()->upsert('huawei', 'Huawei', 'manual');
        $first = $this->postJson('/providers/huawei/refresh');
        self::assertSame(200, $first['status']);
        self::assertTrue($first['json']['ok']);
        self::assertSame('queued', $first['json']['job']['status']);
        self::assertSame(['text' => 'In the queue', 'icon' => 'hourglass-split'], $first['json']['job']['chip']);
        $uuid = $first['json']['job']['uuid'];
        self::assertSame('/jobs/' . $uuid, $first['json']['job_url']);
        self::assertSame('/api/jobs/' . $uuid, $first['json']['status_url']);

        // Pressed again while it waits: refused, and pointed at the job already waiting.
        $again = $this->postJson('/providers/huawei/refresh');
        self::assertSame(409, $again['status']);
        self::assertFalse($again['json']['ok']);
        self::assertSame($uuid, $again['json']['job']['uuid']);
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM jobs WHERE type = 'inventory'"));
        $this->drain();
        self::assertSame(['text' => 'Done', 'icon' => 'check2-circle'], json_decode($this->get('/api/jobs/' . $uuid)->body, true)['chip']);
    }

    public function testSeveralExamsCanBeQueuedFromTheCollectPage(): void
    {
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        $one = $this->postJson('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'source_mode' => 'live', 'rps' => '50', 'q' => 'h1']);
        $two = $this->postJson('/collect', ['provider' => 'huawei', 'exam' => 'h19-301', 'source_mode' => 'live', 'rps' => '50']);
        self::assertTrue($one['json']['ok'] && $two['json']['ok']);
        self::assertSame('h12-811', $one['json']['mark'], 'the exam to mark in the list');
        self::assertStringContainsString('1 job ahead of it', $two['json']['message']);
        self::assertSame(2, (int) $this->app->db()->value("SELECT COUNT(*) FROM jobs WHERE type = 'collect'"));

        // Without the script: back to the same exam with the list's search kept.
        $r = $this->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811-v2-0', 'source_mode' => 'live', 'q' => 'h12']);
        self::assertSame('/collect?provider=huawei&exam=h12-811-v2-0&q=h12', $r->headers['Location']);

        // The page marks every exam waiting or running, and shows the job in place of the button.
        $page = $this->get('/collect', ['provider' => 'huawei', 'exam' => 'h12-811'])->body;
        self::assertSame(4, substr_count($page, 'data-job-chip'), 'three in the list, one in the panel');
        self::assertStringContainsString('job-chip job-chip--block', $page);
        self::assertStringNotContainsString('Add to the queue</button>', $page);

        // A problem is said without leaving the page.
        $bad = $this->postJson('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'source_mode' => 'live']);
        self::assertSame(409, $bad['status']);
        self::assertStringContainsString('already in the queue', $bad['json']['message']);
    }

    public function testAMemberSeesTheJobButNoLinkToIt(): void
    {
        $this->s->users()->create('member', 'member password long', Access::MEMBER);
        $this->s->providers()->upsert('huawei', 'Huawei', 'manual');
        $this->newSession();
        $this->login('member', 'member password long');
        $r = $this->postJson('/providers/huawei/refresh');
        self::assertTrue($r['json']['ok'], 'members may load a provider\'s exam list the first time');
        self::assertNull($r['json']['job_url'], 'the jobs pages are for collectors');
        self::assertStringContainsString('<span class="job-chip"', $this->get('/providers')->body);
    }
}
