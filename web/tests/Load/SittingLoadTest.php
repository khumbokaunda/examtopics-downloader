<?php

declare(strict_types=1);

namespace Tests\Load;

use App\Services\Access;
use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/**
 * A full sitting under load, over real HTTP: every candidate signs in, starts, answers, changes
 * answers, polls and hands in at the same moment, as on exam day when a room starts together.
 *
 * Not part of the normal suite (it takes a minute and needs free CPU). Run it with:
 *
 *     LOAD_CANDIDATES=150 LOAD_WORKERS=8 vendor/bin/phpunit --configuration phpunit.xml tests/Load
 *
 * It fails on any error response or any answer not stored as last saved, and prints how long
 * each step took (median, 95th percentile, slowest).
 */
final class SittingLoadTest extends HttpTestCase
{
    use InstitutionFixtures;

    /** @var resource|null */
    private $server = null;
    private string $base = '';
    /** @var array<string,list<float>> seconds per request, by step */
    private array $times = [];
    /** @var list<string> */
    private array $errors = [];

    protected function tearDown(): void
    {
        if ($this->server) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
    }

    public function testAFullRoomSitsAtOnce(): void
    {
        $n = max(2, (int) (getenv('LOAD_CANDIDATES') ?: 120));
        $workers = max(1, (int) (getenv('LOAD_WORKERS') ?: 8));
        $changes = max(1, (int) (getenv('LOAD_CHANGES') ?: 3)); // times each answer is saved

        [$a, $path, $assessmentUuid] = $this->sittingScenario();
        $iid = (int) $this->app->db()->value('SELECT id FROM institutions WHERE uuid = ?', [$a]);
        $uuids = [];
        for ($i = 1; $i <= $n; $i++) {
            $this->s->users()->create('cand' . $i, self::$pw, Access::MEMBER, ['email' => 'cand' . $i . '@example.com', 'email_verified' => true, 'library_access' => false]);
            $uid = (int) $this->s->users()->findByUsername('cand' . $i)['id'];
            $this->s->institutions()->repo()->join($iid, $uid, 'student', [], null);
            $uuids[] = $this->memberUuid($a, 'cand' . $i);
        }
        $this->as('olive');
        $class = (string) $this->app->db()->value('SELECT uuid FROM institution_classes');
        $this->post('/institutions/' . $a . '/classes/' . $class . '/students', ['students' => $uuids]);
        self::assertSame($n + 2, (int) $this->app->db()->value("SELECT COUNT(*) FROM class_enrolments WHERE status = 'active'"));

        $this->startServer($workers);
        $c = array_fill(1, $n, ['cookies' => [], 'csrf' => '', 'sitting' => '', 'questions' => []]);
        $started = microtime(true);

        // Sign in.
        $this->each($c, 'open sign-in', fn(int $i) => ['GET', '/login'], function (int $i, array $r) use (&$c): void { $c[$i]['csrf'] = self::csrf($r['body']); });
        $this->each($c, 'sign in', fn(int $i) => ['POST', '/login', ['username' => 'cand' . $i, 'password' => self::$pw, '_csrf' => $c[$i]['csrf']]], null, 303);
        $this->each($c, 'list assessments', fn(int $i) => ['GET', '/my/assessments'], function (int $i, array $r) use (&$c): void { $c[$i]['csrf'] = self::csrf($r['body']); });
        // Start, all at once.
        $mine = '/my/assessments/' . $a . '/' . $assessmentUuid . '/start';
        $this->each($c, 'start', fn(int $i) => ['POST', $mine, ['accept' => '1', '_csrf' => $c[$i]['csrf']]], function (int $i, array $r) use (&$c): void {
            $c[$i]['sitting'] = (string) preg_replace('#^.*/sit/#', '', (string) ($r['location'] ?? ''));
        }, 303);
        $this->each($c, 'open the paper', fn(int $i) => ['GET', '/sit/' . $c[$i]['sitting']], function (int $i, array $r) use (&$c): void {
            $c[$i]['csrf'] = self::csrf($r['body']);
            preg_match_all('#name="a(\d+)(\[\])?" value="([A-Za-z0-9]+)"#', $r['body'], $m, PREG_SET_ORDER);
            foreach ($m as $x) {
                $c[$i]['questions'][(int) $x[1]][] = [$x[3], $x[2] !== ''];
            }
        });
        // Answer every question, change each answer, and poll between, as the page does.
        $seq = 0;
        for ($round = 0; $round < $changes; $round++) {
            foreach (array_keys($c[1]['questions']) as $k => $_) {
                $seq++;
                $this->each($c, 'save an answer', function (int $i) use (&$c, $k, $round, $seq): array {
                    $positions = array_keys($c[$i]['questions']);
                    $pos = $positions[$k];
                    $choices = $c[$i]['questions'][$pos];
                    $c[$i]['last'][$pos] = $choices[$round % count($choices)][0];
                    return ['POST', '/api/sit/' . $c[$i]['sitting'] . '/answer', ['position' => (string) $pos, 'answer' => $c[$i]['last'][$pos], 'seq' => (string) $seq, '_csrf' => $c[$i]['csrf']], true];
                });
            }
            $this->each($c, 'poll', fn(int $i) => ['GET', '/api/sit/' . $c[$i]['sitting'], [], true]);
        }
        // Hand in, all at once.
        $this->each($c, 'hand in', function (int $i) use (&$c): array {
            $form = ['sent' => '1', '_csrf' => $c[$i]['csrf']];
            foreach ($c[$i]['last'] as $pos => $key) {
                $multiple = $c[$i]['questions'][$pos][0][1];
                $form['a' . $pos] = $multiple ? [$key] : $key;
            }
            return ['POST', '/sit/' . $c[$i]['sitting'] . '/submit', $form];
        }, null, 303);
        $elapsed = microtime(true) - $started;

        $this->report($n, $workers, $elapsed);
        self::assertSame([], array_slice($this->errors, 0, 10), count($this->errors) . ' requests failed');

        // Nothing lost: every sitting handed in, every answer as last saved, every score worked out.
        $db = $this->app->db();
        self::assertSame($n, (int) $db->value("SELECT COUNT(*) FROM assessment_sessions s JOIN institution_memberships m ON m.id = s.membership_id JOIN users u ON u.id = m.user_id
            WHERE u.username LIKE 'cand%' AND s.submitted_at IS NOT NULL AND s.percent IS NOT NULL"));
        foreach ($c as $i => $cand) {
            $stored = [];
            foreach ($db->all('SELECT r.position, r.answer FROM assessment_responses r JOIN assessment_sessions s ON s.id = r.session_id WHERE s.uuid = ?', [$cand['sitting']]) as $r) {
                $stored[(int) $r['position']] = (string) $r['answer'];
            }
            ksort($stored);
            $last = $cand['last'];
            ksort($last);
            self::assertSame(array_map('strval', $last), $stored, 'candidate ' . $i);
        }
    }

    /**
     * Send one request per candidate at the same time and wait for them all.
     *
     * @param array<int,array<string,mixed>> $c
     * @param callable(int):array{0:string,1:string,2?:array<string,mixed>,3?:bool} $make method, path, form, wants JSON
     */
    private function each(array &$c, string $step, callable $make, ?callable $then = null, int $expect = 200): void
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach (array_keys($c) as $i) {
            $req = $make($i);
            [$method, $path] = $req;
            $form = $req[2] ?? [];
            $json = $req[3] ?? false;
            $h = curl_init($this->base . $path);
            $headers = $json ? ['Accept: application/json'] : [];
            if ($c[$i]['cookies'] !== []) {
                $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($c[$i]['cookies']), $c[$i]['cookies']));
            }
            $respHeaders = [];
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $respHeaders[] = [strtolower(trim($k)), trim($v)];
                    }
                    return strlen($line);
                }]);
            if ($method === 'POST') {
                curl_setopt($h, CURLOPT_POST, true);
                curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($form));
            }
            curl_multi_add_handle($multi, $h);
            $handles[$i] = [$h, &$respHeaders];
            unset($respHeaders);
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $i => [$h, $hdrs]) {
            $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $this->times[$step][] = (float) curl_getinfo($h, CURLINFO_TOTAL_TIME);
            $body = (string) curl_multi_getcontent($h);
            $location = null;
            foreach ($hdrs as [$k, $v]) {
                if ($k === 'set-cookie') {
                    [$pair] = explode(';', $v, 2);
                    [$ck, $cv] = array_pad(explode('=', $pair, 2), 2, '');
                    $c[$i]['cookies'][$ck] = $cv;
                } elseif ($k === 'location') {
                    $location = $v;
                }
            }
            if ($code !== $expect) {
                $this->errors[] = $step . ' (candidate ' . $i . '): ' . $code . ' ' . mb_substr(strip_tags($body), 0, 160);
            } elseif ($then !== null) {
                $then($i, ['body' => $body, 'location' => $location]);
            }
            curl_multi_remove_handle($multi, $h);
            curl_close($h);
        }
        curl_multi_close($multi);
    }

    private static function csrf(string $html): string
    {
        return preg_match('#name="csrf-token" content="([^"]+)"#', $html, $m) === 1 ? $m[1] : '';
    }

    private function startServer(int $workers): void
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($s, false);
        fclose($s);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $root = dirname(__DIR__, 2);
        $env = getenv() + ['EXAMTOPICS_CONFIG' => $this->configFile];
        $env['EXAMTOPICS_CONFIG'] = $this->configFile;
        $env['PHP_CLI_SERVER_WORKERS'] = (string) $workers;
        $this->server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $root . '/public/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
        $this->base = 'http://127.0.0.1:' . $port;
        for ($i = 0; $i < 100; $i++) {
            $h = curl_init($this->base . '/login');
            curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
            curl_exec($h);
            $ok = curl_getinfo($h, CURLINFO_RESPONSE_CODE) === 200;
            curl_close($h);
            if ($ok) {
                return;
            }
            usleep(100_000);
        }
        self::fail('The web server did not start.');
    }

    private function report(int $n, int $workers, float $elapsed): void
    {
        $lines = [sprintf('%d candidates, %d server workers, %.1f s from first sign-in to last hand-in, %d failed requests', $n, $workers, $elapsed, count($this->errors)),
            sprintf('%-18s %8s %8s %8s %8s', 'step', 'requests', 'median', 'p95', 'slowest')];
        foreach ($this->times as $step => $t) {
            sort($t);
            $at = static fn(float $q): float => $t[(int) min(count($t) - 1, floor($q * count($t)))];
            $lines[] = sprintf('%-18s %8d %7.0fms %7.0fms %7.0fms', $step, count($t), $at(0.5) * 1000, $at(0.95) * 1000, end($t) * 1000);
        }
        fwrite(STDERR, "\n" . implode("\n", $lines) . "\n");
    }
}
