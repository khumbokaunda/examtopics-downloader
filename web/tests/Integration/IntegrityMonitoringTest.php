<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Integrity;
use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** Integrity monitoring: what is collected under each policy, indicators, reports and conclusions. */
final class IntegrityMonitoringTest extends HttpTestCase
{
    use InstitutionFixtures;

    private string $a;
    private string $path;
    private string $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->path, $this->assessment] = $this->sittingScenario();
    }

    private function db(string $sql, array $args = []): mixed
    {
        return $this->app->db()->value($sql, $args);
    }

    private function start(string $who): string
    {
        $this->as($who);
        $r = $this->post('/my/assessments/' . $this->a . '/' . $this->assessment . '/start', ['accept' => '1']);
        return substr((string) $r->headers['Location'], 5);
    }

    /** @param list<array<string,mixed>> $events */
    private function send(string $uuid, array $events): array
    {
        return $this->postJson('/api/sit/' . $uuid . '/events', ['events' => json_encode($events)]);
    }

    public function testWhatIsCollectedFollowsThePolicyFixedAtTheStart(): void
    {
        $this->app->db()->run("UPDATE assessments SET monitoring = 'standard'");
        $this->as('carol');
        self::assertStringContainsString('Monitoring: Standard', $this->get('/my/assessments/' . $this->a . '/' . $this->assessment)->body, 'the candidate is told before starting');
        $uuid = $this->start('carol');
        $page = $this->get('/sit/' . $uuid)->body;
        self::assertStringContainsString('data-monitor="hidden visible blur focus offline online"', $page);
        $this->app->db()->run("UPDATE assessments SET monitoring = 'enhanced'");

        $sent = $this->send($uuid, [
            ['seq' => 1, 'type' => 'hidden', 'at' => time() * 1000],
            ['seq' => 2, 'type' => 'visible', 'at' => time() * 1000, 'duration_ms' => 75000],
            ['seq' => 2, 'type' => 'visible', 'at' => time() * 1000, 'duration_ms' => 75000],
            ['seq' => 3, 'type' => 'paste', 'at' => time() * 1000],
            ['seq' => 4, 'type' => 'keystroke', 'key' => 'a'],
        ]);
        self::assertSame(2, $sent['json']['stored'], 'a repeat, a type the policy does not collect (it stays standard) and an unknown type are dropped');
        self::assertSame('standard', $this->db('SELECT monitoring FROM assessment_sessions WHERE uuid = ?', [$uuid]));
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM assessment_events WHERE type = 'opened'"), 'the server records the page opening itself');
        $this->post('/sit/' . $uuid . '/submit');
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM assessment_events WHERE type = 'submitted'"));

        $this->as('dan');
        self::assertSame(404, $this->send($uuid, [['seq' => 9, 'type' => 'hidden']])['status'], 'only the candidate sends for their sitting');

        $this->as('erin');
        self::assertStringContainsString('Worth a look', $this->get($this->path . '/results')->body);
        $report = $this->get($this->path . '/results/' . $uuid)->body;
        self::assertStringContainsString('The page was hidden for 75 seconds', $report);
        self::assertStringContainsString('The page was shown again', $report);
        self::assertStringNotContainsString('cheat', strtolower($report), 'neutral words only');
        $this->post($this->path . '/results/' . $uuid . '/integrity', ['outcome' => 'no_concern', 'note' => '']);
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM integrity_reviews'));
        $this->post($this->path . '/results/' . $uuid . '/integrity', ['outcome' => 'no_concern', 'note' => 'She told the invigilator her phone rang.']);
        self::assertStringContainsString('her phone rang', $this->get($this->path . '/results/' . $uuid)->body);
    }

    public function testBasicCollectsNothing(): void
    {
        $uuid = $this->start('carol');
        self::assertStringContainsString('data-monitor=""', $this->get('/sit/' . $uuid)->body);
        self::assertSame(0, $this->send($uuid, [['seq' => 1, 'type' => 'hidden']])['json']['stored']);
    }

    public function testIndicators(): void
    {
        $e = static fn(string $type, ?int $ms = null): array => ['type' => $type, 'duration_ms' => $ms];
        self::assertSame('none', Integrity::summary([])['level']);
        self::assertSame('info', Integrity::summary([$e('hidden'), $e('visible', 3000)])['level'], 'one brief absence is only noted');
        self::assertSame('review', Integrity::summary(array_fill(0, 5, $e('blur')))['level']);
        self::assertSame('technical', Integrity::summary([$e('opened'), $e('opened'), $e('opened'), $e('opened')])['level']);
        self::assertSame('review', Integrity::summary([$e('paste'), $e('paste'), $e('paste')])['level']);
    }
}
