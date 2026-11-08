<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** Live invigilation: who may watch, the live feed, observations, pause and resume, extra time and ending a sitting. */
final class InvigilationTest extends HttpTestCase
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

    /** @return array{status:int,json:array<string,mixed>} */
    private function getJson(string $path): array
    {
        $r = $this->get($path);
        return ['status' => $r->status, 'json' => (array) json_decode($r->body, true)];
    }

    private function act(string $uuid, array $data): void
    {
        $this->post($this->path . '/live/' . $uuid . '/intervene', $data);
    }

    public function testWhoMayWatch(): void
    {
        $this->as('bob');
        self::assertSame(404, $this->get($this->path . '/live')->status, 'an instructor neither monitors nor is assigned');
        $this->as('carol');
        self::assertSame(404, $this->get($this->path . '/live')->status);

        $this->as('olive');
        $page = $this->get($this->path . '/live');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Save invigilators', $page->body);
        $this->post($this->path . '/invigilators', ['invigilators' => [$this->memberUuid($this->a, 'carol')]]);
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM invigilator_assignments'), 'a student cannot invigilate');
        $this->post($this->path . '/invigilators', ['invigilators' => [$this->memberUuid($this->a, 'bob')]]);
        self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM invigilator_assignments'));

        $this->as('bob');
        $page = $this->get($this->path . '/live');
        self::assertSame(200, $page->status, 'an assigned invigilator watches');
        self::assertStringNotContainsString('End the sitting', $page->body, 'but cannot intervene');
        self::assertStringNotContainsString('Save invigilators', $page->body);
        $uuid = $this->start('carol');
        $this->as('bob');
        $this->act($uuid, ['action' => 'pause']);
        self::assertNull($this->db('SELECT paused_at FROM assessment_sessions WHERE uuid = ?', [$uuid]));
    }

    public function testLiveFeedAndObservations(): void
    {
        $uuid = $this->start('carol');
        $this->as('erin');
        $feed = $this->getJson('/api/institutions/' . $this->a . '/assessments/' . $this->assessment . '/live');
        $rows = array_column($feed['json']['rows'], null, 'member');
        self::assertSame('writing', $rows[$this->memberUuid($this->a, 'carol')]['state']);
        self::assertTrue($rows[$this->memberUuid($this->a, 'carol')]['connected']);
        self::assertSame('not_started', $rows[$this->memberUuid($this->a, 'dan')]['state']);
        self::assertArrayNotHasKey($this->memberUuid($this->a, 'fay'), $rows, 'fay is not in the class');
        self::assertStringNotContainsString('choices', json_encode($feed['json']), 'no questions or answers in the feed');

        $this->post($this->path . '/live/' . $uuid . '/observe', ['category' => 'incident', 'note' => '']);
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM invigilator_observations'));
        $this->post($this->path . '/live/' . $uuid . '/observe', ['category' => 'identity_checked', 'note' => '']);
        $this->post($this->path . '/live/' . $uuid . '/observe', ['category' => 'incident', 'note' => 'Phone rang in her bag.']);
        self::assertSame(2, (int) $this->db('SELECT COUNT(*) FROM invigilator_observations'));
        $report = $this->get($this->path . '/results/' . $uuid)->body;
        self::assertStringContainsString('Phone rang in her bag.', $report);
        self::assertStringContainsString('Identity checked', $report);
    }

    public function testPauseStopsTheClockAndResumeGivesItBack(): void
    {
        $uuid = $this->start('carol');
        $this->as('erin');
        $this->act($uuid, ['action' => 'pause']);
        self::assertNotNull($this->db('SELECT paused_at FROM assessment_sessions WHERE uuid = ?', [$uuid]));
        // Pretend it was paused ten minutes ago.
        $this->app->db()->run('UPDATE assessment_sessions SET paused_at = DATE_SUB(paused_at, INTERVAL 600 SECOND) WHERE uuid = ?', [$uuid]);
        $endsBefore = (int) strtotime($this->db('SELECT ends_at FROM assessment_sessions WHERE uuid = ?', [$uuid]) . ' UTC');

        $this->as('carol');
        $status = $this->getJson('/api/sit/' . $uuid)['json'];
        self::assertTrue($status['paused']);
        $saved = $this->postJson('/api/sit/' . $uuid . '/answer', ['position' => '1', 'answer' => 'A', 'flagged' => '0', 'seq' => '1']);
        self::assertNotSame(200, $saved['status'], 'no answers while paused');
        self::assertStringContainsString('Paused by the invigilator', $this->get('/sit/' . $uuid)->body);

        $this->as('erin');
        $this->act($uuid, ['action' => 'resume']);
        $endsAfter = (int) strtotime($this->db('SELECT ends_at FROM assessment_sessions WHERE uuid = ?', [$uuid]) . ' UTC');
        self::assertEqualsWithDelta(600, $endsAfter - $endsBefore, 5, 'the paused time is added back');
        self::assertNull($this->db('SELECT paused_at FROM assessment_sessions WHERE uuid = ?', [$uuid]));
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM assessment_events WHERE type = 'resumed'"));
    }

    public function testExtraTimeAndEnding(): void
    {
        $uuid = $this->start('carol');
        $this->as('erin');
        $endsBefore = (int) strtotime($this->db('SELECT ends_at FROM assessment_sessions WHERE uuid = ?', [$uuid]) . ' UTC');
        $this->act($uuid, ['action' => 'extend', 'minutes' => '15', 'reason' => '']);
        self::assertSame(0, (int) $this->db('SELECT extra_minutes FROM assessment_sessions WHERE uuid = ?', [$uuid]), 'a reason is needed');
        $this->act($uuid, ['action' => 'extend', 'minutes' => '15', 'reason' => 'Power cut']);
        self::assertSame(15, (int) $this->db('SELECT extra_minutes FROM assessment_sessions WHERE uuid = ?', [$uuid]));
        self::assertSame(900, (int) strtotime($this->db('SELECT ends_at FROM assessment_sessions WHERE uuid = ?', [$uuid]) . ' UTC') - $endsBefore);

        $this->act($uuid, ['action' => 'terminate', 'reason' => '']);
        self::assertSame('in_progress', $this->db('SELECT status FROM assessment_sessions WHERE uuid = ?', [$uuid]));
        $this->act($uuid, ['action' => 'terminate', 'reason' => 'Left the room with notes.']);
        self::assertSame('terminated', $this->db('SELECT status FROM assessment_sessions WHERE uuid = ?', [$uuid]));
        self::assertNotNull($this->db('SELECT percent FROM assessment_sessions WHERE uuid = ?', [$uuid]), 'it is marked');
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM audit_log WHERE action = 'institution.sitting_ended'"));

        $this->as('carol');
        self::assertSame('terminated', $this->getJson('/api/sit/' . $uuid)['json']['status']);
    }

    public function testMessagesReachTheCandidateAndShowWhenSeen(): void
    {
        $uuid = $this->start('carol');
        $this->start('dan');
        $this->as('bob');
        $this->post($this->path . '/live/' . $uuid . '/message', ['body' => 'Not watching']);
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM invigilator_messages'), 'only watchers send messages');

        $this->as('erin');
        $this->post($this->path . '/live/' . $uuid . '/message', ['body' => '   ']);
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM invigilator_messages'));
        $this->post($this->path . '/live/' . $uuid . '/message', ['body' => 'Please sit up straight, <b>Carol</b>.']);
        $this->post($this->path . '/live/message', ['body' => 'Fifteen minutes left.']);
        self::assertSame(3, (int) $this->db('SELECT COUNT(*) FROM invigilator_messages'), 'one to carol, then one to each of the two writing');
        $feed = $this->getJson('/api/institutions/' . $this->a . '/assessments/' . $this->assessment . '/live')['json'];
        $rows = array_column($feed['rows'], null, 'member');
        self::assertSame(2, $rows[$this->memberUuid($this->a, 'carol')]['unseen']);
        $live = $this->get($this->path . '/live')->body;
        self::assertStringContainsString('2 messages, 2 not yet seen', $live);
        self::assertStringContainsString('data-live-count="writing">2<', $live);

        $this->as('carol');
        $status = $this->getJson('/api/sit/' . $uuid)['json'];
        self::assertSame(['Please sit up straight, <b>Carol</b>.', 'Fifteen minutes left.'], array_column($status['messages'], 'body'));
        $page = $this->get('/sit/' . $uuid)->body;
        self::assertStringContainsString('Please sit up straight, &lt;b&gt;Carol&lt;/b&gt;.', $page, 'escaped');
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM invigilator_messages m JOIN assessment_sessions s ON s.id = m.session_id WHERE s.uuid = ? AND m.seen_at IS NULL', [$uuid]));

        $this->as('erin');
        self::assertStringContainsString('seen', $this->get($this->path . '/results/' . $uuid)->body);
        $this->as('dan');
        self::assertSame(404, $this->getJson('/api/sit/' . $uuid)['status'], 'a candidate reads only their own');
        $this->as('carol');
        $this->post('/sit/' . $uuid . '/submit');
        $this->as('erin');
        $this->post($this->path . '/live/' . $uuid . '/message', ['body' => 'Too late']);
        self::assertSame(3, (int) $this->db('SELECT COUNT(*) FROM invigilator_messages'));
    }
}
