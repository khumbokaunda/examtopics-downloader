<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use App\Services\Readiness;
use Tests\Support\HttpTestCase;

/** Readiness judged from mock exams, certificates, public verification and revoking. */
final class ReadinessTest extends HttpTestCase
{
    private const PW = 'member password long';
    private int $exam;
    private int $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        $version = (array) $this->s->versions()->find((int) $this->s->jobs()->find($id)['cache_version_id']);
        $this->exam = (int) $version['exam_id'];
        $this->version = (int) $version['id'];
        $this->s->users()->create('thandi', self::PW, Access::MEMBER, ['display_name' => 'Thandi Moyo']);
        $this->s->users()->create('other', self::PW, Access::MEMBER);
        $this->newSession();
    }

    /** A finished mock exam of the CCNA for thandi. @return string its uuid */
    private function mock(int $total, int $right, int $unanswered = 0, int $minutes = 60, int $daysAgo = 0): string
    {
        $uuid = \App\Core\Ids::uuid();
        $results = [];
        for ($n = 0; $n < $total; $n++) {
            $results['k' . $n] = ['picked' => $n < $unanswered ? '' : 'A', 'answer' => 'A', 'right' => $n >= $unanswered && $n - $unanswered < $right, 'number' => $n + 1];
        }
        $finished = time() - $daysAgo * 86400;
        $this->app->db()->run('INSERT INTO mock_exams (uuid, user_id, exam_id, version_id, question_keys, picks, flags, minutes, started_at, ends_at, finished_at, total, right_count, results)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uuid, (int) $this->s->users()->findByUsername('thandi')['id'], $this->exam, $this->version, json_encode(array_keys($results)), '{}', '[]', $minutes,
                gmdate('Y-m-d H:i:s', $finished - 1800), $minutes > 0 ? gmdate('Y-m-d H:i:s', $finished + 60) : null, gmdate('Y-m-d H:i:s', $finished), $total, $right, json_encode($results)]);
        return $uuid;
    }

    private function db(string $sql, array $args = []): mixed
    {
        return $this->app->db()->value($sql, $args);
    }

    public function testTheLevels(): void
    {
        $mock = static fn(int $total, int $right, int $minutes = 60, int $unanswered = 0): array => ['id' => 1, 'total' => $total, 'right_count' => $right, 'minutes' => $minutes,
            'flags' => [], 'started_at' => '2026-11-01 10:00:00', 'finished_at' => '2026-11-01 11:00:00',
            'results' => array_fill(0, $total, ['picked' => 'A']) ? array_merge(array_fill(0, $unanswered, ['picked' => '']), array_fill(0, $total - $unanswered, ['picked' => 'A'])) : []];
        $earlier = [['id' => 2, 'total' => 40, 'right_count' => 30, 'finished_at' => '2026-10-20 10:00:00']];
        self::assertSame('not_yet', Readiness::assess($mock(40, 20), [])['level']);
        self::assertSame('developing', Readiness::assess($mock(40, 24), [])['level']);
        self::assertSame('nearly', Readiness::assess($mock(40, 30), [])['level']);
        self::assertSame('nearly', Readiness::assess($mock(40, 36), [])['level'], '90% without a second attempt is not yet demonstrated');
        self::assertSame('ready', Readiness::assess($mock(40, 36), $earlier)['level']);
        self::assertFalse(Readiness::assess($mock(40, 36, 0), $earlier)['eligible'], 'untimed does not count');
        self::assertFalse(Readiness::assess($mock(20, 19), $earlier)['eligible'], 'too few questions');
        self::assertFalse(Readiness::assess($mock(40, 33, 60, 6), $earlier)['eligible'], 'too many unanswered');
        self::assertFalse(Readiness::assess($mock(40, 36), [['id' => 2, 'total' => 40, 'right_count' => 30, 'finished_at' => '2026-08-01 10:00:00']])['eligible'],
            'the other attempt is too long before');
    }

    public function testEarningVerifyingAndRevokingACertificate(): void
    {
        $this->mock(40, 30, 0, 60, 3);
        $uuid = $this->mock(40, 35);
        $this->login('thandi', self::PW, Access::MEMBER);
        $page = $this->get('/mock/' . $uuid);
        self::assertStringContainsString('Demonstrated readiness', $page->body);
        self::assertStringContainsString('Get your certificate', $page->body);

        $r = $this->post('/mock/' . $uuid . '/certificate');
        $cert = $this->app->db()->one('SELECT * FROM certificates');
        self::assertSame('/certificates/' . $cert['uuid'], $r->headers['Location']);
        self::assertSame(['Thandi Moyo', 'Huawei', '87.50', 'issued'], [$cert['holder_name'], substr($cert['exam_title'], 0, 6), $cert['percent'], $cert['status']]);
        self::assertMatchesRegularExpression('/^EL-\d{4}-000001$/', $cert['number']);
        $this->post('/mock/' . $uuid . '/certificate');
        self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM certificates'), 'asking again gives the same certificate');
        $shown = $this->get('/certificates/' . $cert['uuid']);
        self::assertStringContainsString('not a professional certification', $shown->body);
        self::assertStringStartsWith('%PDF', $this->get('/certificates/' . $cert['uuid'] . '/pdf')->body);

        // An attempt that does not qualify earns nothing.
        $weak = $this->mock(40, 25);
        $this->post('/mock/' . $weak . '/certificate');
        self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM certificates'));

        // Someone else cannot see it; anyone with the link can verify it.
        $this->newSession();
        $this->login('other', self::PW, Access::MEMBER);
        self::assertSame(404, $this->get('/certificates/' . $cert['uuid'])->status);
        self::assertSame(404, $this->post('/mock/' . $uuid . '/certificate')->status);
        $this->newSession();
        $verify = $this->get('/verify/' . $cert['token']);
        self::assertSame(200, $verify->status);
        self::assertStringContainsString('Genuine and current', $verify->body);
        self::assertStringContainsString('Thandi Moyo', $verify->body);
        self::assertSame(404, $this->get('/verify/' . str_repeat('0', 32))->status);

        $this->login();
        $this->post('/platform/certificates/' . $cert['uuid'] . '/revoke', ['reason' => '']);
        self::assertSame('issued', $this->db('SELECT status FROM certificates'));
        $this->post('/platform/certificates/' . $cert['uuid'] . '/revoke', ['reason' => 'Shared answers found.']);
        self::assertSame('revoked', $this->db('SELECT status FROM certificates'));
        $this->newSession();
        $verify = $this->get('/verify/' . $cert['token'])->body;
        self::assertStringContainsString('Revoked', $verify);
        self::assertStringContainsString('Shared answers found.', $verify);
        self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM certificates'), 'a revoked certificate is kept');

        $this->login('thandi', self::PW, Access::MEMBER);
        self::assertSame(403, $this->get('/platform/certificates')->status);
    }
}
