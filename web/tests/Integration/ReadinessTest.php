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

    /**
     * A finished mock exam of the CCNA for thandi: questions k<first> onwards, drawn from $source
     * (null: an attempt from before sources were recorded) out of $pool.
     *
     * @return string its uuid
     */
    private function mock(int $total, int $right, int $unanswered = 0, int $minutes = 60, int $daysAgo = 0, ?string $source = 'all', int $pool = 200, int $first = 0): string
    {
        $uuid = \App\Core\Ids::uuid();
        $results = [];
        for ($n = 0; $n < $total; $n++) {
            $results['k' . ($first + $n)] = ['picked' => $n < $unanswered ? '' : 'A', 'answer' => 'A', 'right' => $n >= $unanswered && $n - $unanswered < $right, 'number' => $n + 1];
        }
        $finished = time() - $daysAgo * 86400;
        $this->app->db()->run('INSERT INTO mock_exams (uuid, user_id, exam_id, version_id, source, pool_size, question_keys, picks, flags, minutes, started_at, ends_at, finished_at, total, right_count, results)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uuid, (int) $this->s->users()->findByUsername('thandi')['id'], $this->exam, $this->version, $source, $source === null ? null : $pool,
                json_encode(array_keys($results)), '{}', '[]', $minutes,
                gmdate('Y-m-d H:i:s', $finished - 1800), $minutes > 0 ? gmdate('Y-m-d H:i:s', $finished + 60) : null, gmdate('Y-m-d H:i:s', $finished), $total, $right, json_encode($results)]);
        return $uuid;
    }

    private function db(string $sql, array $args = []): mixed
    {
        return $this->app->db()->value($sql, $args);
    }

    public function testTheLevels(): void
    {
        $keys = static fn(int $from, int $n): array => array_map(static fn(int $i): string => 'k' . $i, range($from, $from + $n - 1));
        $mock = static fn(int $total, int $right, int $minutes = 60, int $unanswered = 0, array $more = []): array => $more + ['id' => 1, 'total' => $total, 'right_count' => $right,
            'minutes' => $minutes, 'source' => 'all', 'pool_size' => 200, 'keys' => $keys(0, $total),
            'flags' => [], 'started_at' => '2026-11-01 10:00:00', 'finished_at' => '2026-11-01 11:00:00',
            'results' => array_fill(0, $total, ['picked' => 'A']) ? array_merge(array_fill(0, $unanswered, ['picked' => '']), array_fill(0, $total - $unanswered, ['picked' => 'A'])) : []];
        $before = static fn(array $more = []): array => [$more + ['id' => 2, 'total' => 40, 'right_count' => 30, 'finished_at' => '2026-10-20 10:00:00', 'source' => 'all',
            'pool_size' => 200, 'keys' => $keys(100, 40)]];
        $earlier = $before();
        self::assertSame('not_yet', Readiness::assess($mock(40, 20), [])['level']);
        self::assertSame('developing', Readiness::assess($mock(40, 24), [])['level']);
        self::assertSame('nearly', Readiness::assess($mock(40, 30), [])['level']);
        self::assertSame('nearly', Readiness::assess($mock(40, 36), [])['level'], '90% without a second attempt is not yet demonstrated');
        self::assertSame('ready', Readiness::assess($mock(40, 36), $earlier)['level']);
        self::assertFalse(Readiness::assess($mock(40, 36, 0), $earlier)['eligible'], 'untimed does not count');
        self::assertFalse(Readiness::assess($mock(20, 19), $earlier)['eligible'], 'too few questions');
        self::assertFalse(Readiness::assess($mock(40, 33, 60, 6), $earlier)['eligible'], 'too many unanswered');
        self::assertFalse(Readiness::assess($mock(40, 36), $before(['finished_at' => '2026-08-01 10:00:00']))['eligible'], 'the other attempt is too long before');

        // Questions the candidate chose do not count, in either attempt.
        foreach (['mistakes', 'due', 'bookmarked'] as $chosen) {
            self::assertFalse(Readiness::assess($mock(40, 40, 60, 0, ['source' => $chosen]), $earlier)['eligible'], $chosen . ' is the candidate\'s own list');
            self::assertFalse(Readiness::assess($mock(40, 36), $before(['source' => $chosen]))['eligible'], 'an earlier attempt from ' . $chosen);
        }
        self::assertSame('nearly', Readiness::assess($mock(40, 40, 60, 0, ['source' => 'bookmarked']), $earlier)['level'], 'a perfect score on a chosen list is not readiness');
        self::assertTrue(Readiness::assess($mock(40, 36, 60, 0, ['source' => 'new']), $before(['source' => 'new']))['eligible'], 'unanswered questions count');
        self::assertFalse(Readiness::assess($mock(40, 36, 60, 0, ['source' => null, 'pool_size' => null]), $earlier)['eligible'], 'an attempt from before sources were recorded');
        // A pool the candidate could predict.
        self::assertFalse(Readiness::assess($mock(40, 36, 60, 0, ['source' => 'new', 'pool_size' => 79]), $earlier)['eligible'], 'drawn from fewer than twice the questions');
        self::assertTrue(Readiness::assess($mock(40, 36, 60, 0, ['pool_size' => 80]), $earlier)['eligible']);
        self::assertFalse(Readiness::assess($mock(40, 36), $before(['pool_size' => 60]))['eligible'], 'the earlier attempt came from too small a pool');
        // The same questions twice is one performance, not two.
        self::assertFalse(Readiness::assess($mock(40, 36), $before(['keys' => $keys(0, 40)]))['eligible'], 'the same questions again');
        self::assertFalse(Readiness::assess($mock(40, 36), $before(['keys' => $keys(19, 40)]))['eligible'], '21 of 40 shared is more than half');
        self::assertTrue(Readiness::assess($mock(40, 36), $before(['keys' => $keys(20, 40)]))['eligible'], 'half shared is allowed');
        self::assertFalse(Readiness::assess($mock(40, 36), $before(['total' => 1, 'right_count' => 1, 'keys' => ['k900'], 'pool_size' => 200]))['eligible'],
            'a one-question attempt is not another attempt');
        $seven = Readiness::assess($mock(40, 36), $earlier)['conditions'];
        self::assertCount(7, $seven, 'every condition is shown');
        self::assertStringContainsString('at random', $seven[4]['text']);
        self::assertStringContainsString('at least 80 questions', $seven[5]['text']);
    }

    public function testAListTheCandidateChoseEarnsNoCertificate(): void
    {
        // Thirty known questions, bookmarked and sat twice: the way a certificate could be had.
        $this->mock(30, 30, 0, 60, 2, 'bookmarked', 30);
        $again = $this->mock(30, 30, 0, 60, 0, 'bookmarked', 30);
        $this->login('thandi', self::PW, Access::MEMBER);
        $page = $this->get('/mock/' . $again)->body;
        self::assertStringNotContainsString('Get your certificate', $page);
        self::assertStringContainsString('you chose the list', $page);
        $this->post('/mock/' . $again . '/certificate');
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM certificates'));

        // The same from "My mistakes", and an honest attempt repeated on the same questions.
        $this->mock(40, 36, 0, 60, 1, 'mistakes', 40, 300);
        $this->post('/mock/' . $this->mock(40, 36, 0, 60, 0, 'mistakes', 40, 300) . '/certificate');
        $this->mock(40, 36, 0, 60, 1, 'all', 200, 500);
        $this->post('/mock/' . $this->mock(40, 36, 0, 60, 0, 'all', 200, 500) . '/certificate');
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM certificates'));

        // Two random draws that share few questions do earn one.
        $earned = $this->mock(40, 36, 0, 60, 0, 'new', 160, 530);
        self::assertStringContainsString('Get your certificate', $this->get('/mock/' . $earned)->body);
        $this->post('/mock/' . $earned . '/certificate');
        self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM certificates'));
    }

    public function testACertificateFromBeforeTheConditionsStays(): void
    {
        $this->mock(40, 30, 0, 60, 3, null);
        $old = $this->mock(40, 35, 0, 60, 0, null);
        $thandi = (int) $this->s->users()->findByUsername('thandi')['id'];
        $this->app->db()->run("INSERT INTO certificates (uuid, number, token, kind, user_id, mock_id, exam_id, holder_name, exam_title, percent, questions, level, issued_at)
            VALUES (?, 'EL-2026-000001', ?, 'readiness', ?, (SELECT id FROM mock_exams WHERE uuid = ?), ?, 'Thandi Moyo', 'Huawei HCIA', '87.50', 40, 'ready', ?)",
            [\App\Core\Ids::uuid(), str_repeat('ab', 16), $thandi, $old, $this->exam, gmdate('Y-m-d H:i:s')]);
        $this->login('thandi', self::PW, Access::MEMBER);
        $page = $this->get('/mock/' . $old)->body;
        self::assertStringContainsString('Your certificate EL-2026-000001', $page);
        self::assertStringContainsString('Issued under the conditions in force at the time', $page);
        self::assertStringContainsString('Genuine and current', $this->get('/verify/' . str_repeat('ab', 16))->body);
        $shown = $this->get('/certificates/' . $this->db('SELECT uuid FROM certificates'))->body;
        self::assertStringNotContainsString('drawn at random', $shown, 'its criteria are the ones it was measured against');
    }

    public function testEarningVerifyingAndRevokingACertificate(): void
    {
        $this->mock(40, 30, 0, 60, 3, 'all', 200, 100);
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
        self::assertStringContainsString('drawn at random', $shown->body, 'the criteria say how the questions were chosen');
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
