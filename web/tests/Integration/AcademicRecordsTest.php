<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** Programmes with modules, module completion from published results, programme history, transcripts and students under 18. */
final class AcademicRecordsTest extends HttpTestCase
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

    private function lastFlash(): string
    {
        $f = $this->flashes();
        return (string) (end($f)['message'] ?? '');
    }

    public function testModulesCompleteFromPublishedResultsAndTranscriptsKeepTheRecordAsIssued(): void
    {
        $base = '/institutions/' . $this->a;
        $this->as('olive');
        $this->post($base . '/structure/programmes', ['name' => 'CCNA Programme', 'code' => 'CCNA', 'department' => '']);
        $p = (string) $this->db('SELECT uuid FROM institution_programmes');
        $pp = $base . '/structure/programmes/' . $p;
        $this->post($pp . '/details', ['description' => 'Cisco networking', 'duration' => '6 months', 'objectives' => 'Route and switch', 'completion_requirements' => 'Every required module', 'status' => 'active']);
        $this->post($pp . '/modules', ['code' => 'M1', 'name' => 'Routing', 'required' => '1']);
        $this->post($pp . '/modules', ['code' => 'M2', 'name' => 'Security', 'required' => '1']);
        $this->post($pp . '/modules', ['code' => 'X', 'name' => 'Extras']);
        self::assertStringContainsString('Routing', $this->get($pp)->body);
        $routing = (int) $this->db("SELECT id FROM programme_modules WHERE name = 'Routing'");
        $this->app->db()->run('UPDATE assessments SET module_id = ?', [$routing]);
        $carol = $this->memberUuid($this->a, 'carol');
        $this->post($base . '/students/' . $carol, ['student_number' => 'ICT001', 'programme' => $p, 'status' => 'active', 'enrolled_on' => '2026-01-10']);
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM programme_enrolments WHERE outcome = 'active'"));

        $this->as('carol');
        $r = $this->post('/my/assessments/' . $this->a . '/' . $this->assessment . '/start', ['accept' => '1']);
        $sit = substr((string) $r->headers['Location'], 5);
        $this->post('/sit/' . $sit . '/submit');
        $this->app->db()->run('UPDATE assessment_sessions SET passed = 1, percent = 90 WHERE uuid = ?', [$sit]);
        self::assertStringNotContainsString('Module test', $this->get('/my/record')->body, 'students see final results only');

        $this->as('olive');
        $page = $this->get($base . '/students/' . $carol)->body;
        self::assertStringContainsString('Provisional', $page);
        self::assertMatchesRegularExpression('#Routing\s*<span class="text-muted">· not completed#', $page, 'not completed until results are published');
        $this->post($this->path . '/results/publication', ['action' => 'publish']);
        $page = $this->get($base . '/students/' . $carol)->body;
        self::assertStringContainsString('completed ' . gmdate('Y-m-d') . ', 90%', $page);
        self::assertMatchesRegularExpression('#Extras\s*<span class="text-muted">\(optional\)#', $page);

        $this->post($base . '/students/' . $carol . '/programme/complete');
        self::assertStringContainsString('Not every required module', $this->lastFlash());
        $this->post($base . '/students/' . $carol . '/modules', ['module' => (string) $this->db("SELECT uuid FROM programme_modules WHERE name = 'Security'")]);
        $this->app->db()->run("UPDATE assessments SET module_id = (SELECT id FROM programme_modules WHERE name = 'Extras')");
        $this->as('carol');
        $mine = $this->get('/my/record')->body;
        self::assertStringContainsString('Module test', $mine);
        self::assertStringContainsString('Routing: completed', $mine, 'relinking the assessment does not rewrite a completion');

        $this->as('erin');
        $this->post($base . '/students/' . $carol . '/transcripts', []);
        $t = $this->app->db()->one('SELECT * FROM transcripts');
        self::assertMatchesRegularExpression('/^TR-\d{4}-\d{6}$/', (string) $t['number']);
        $snapshot = json_decode((string) $t['snapshot'], true);
        self::assertSame('Routing', $snapshot['programmes'][0]['modules'][0]['name']);

        $this->as('olive');
        $this->post($base . '/students/' . $carol . '/programme/complete');
        self::assertSame('completed', $this->db("SELECT outcome FROM programme_enrolments"));
        self::assertSame('completed', $this->db("SELECT status FROM institution_students WHERE student_number = 'ICT001'"));
        self::assertSame($t['snapshot'], $this->db('SELECT snapshot FROM transcripts'), 'an issued transcript never changes');

        $this->as('carol');
        $pdf = $this->get('/my/record/' . $this->a . '/transcripts/' . $t['uuid'] . '.pdf');
        self::assertSame(200, $pdf->status);
        self::assertStringStartsWith('%PDF', $pdf->body);
        $this->as('dan');
        self::assertSame(404, $this->get('/my/record/' . $this->a . '/transcripts/' . $t['uuid'] . '.pdf')->status, 'only its holder');

        $this->as('erin');
        $this->post($base . '/students/' . $carol . '/transcripts/' . $t['uuid'] . '/withdraw', ['reason' => '']);
        self::assertSame('issued', $this->db('SELECT status FROM transcripts'));
        $this->post($base . '/students/' . $carol . '/transcripts/' . $t['uuid'] . '/withdraw', ['reason' => 'Issued before the resit was marked.']);
        self::assertSame(200, $this->get($base . '/students/' . $carol . '/transcripts/' . $t['uuid'] . '.pdf')->status, 'staff can still open it, marked withdrawn');
        $this->as('carol');
        self::assertSame(404, $this->get('/my/record/' . $this->a . '/transcripts/' . $t['uuid'] . '.pdf')->status);

        $this->as('bob');
        self::assertSame(403, $this->post($base . '/students/' . $carol . '/transcripts', [])->status, 'issuing needs transcripts.issue');
    }

    public function testProgrammeHistoryAndUnder18(): void
    {
        $base = '/institutions/' . $this->a;
        $this->as('olive');
        $this->post($base . '/structure/programmes', ['name' => 'First', 'code' => '', 'department' => '']);
        $this->post($base . '/structure/programmes', ['name' => 'Second', 'code' => '', 'department' => '']);
        $first = (string) $this->db("SELECT uuid FROM institution_programmes WHERE name = 'First'");
        $second = (string) $this->db("SELECT uuid FROM institution_programmes WHERE name = 'Second'");
        $dan = $this->memberUuid($this->a, 'dan');
        $this->post($base . '/students/' . $dan, ['student_number' => '', 'programme' => $first, 'status' => 'active', 'enrolled_on' => '']);
        $this->post($base . '/students/' . $dan, ['student_number' => '', 'programme' => $second, 'status' => 'active', 'enrolled_on' => '']);
        self::assertSame(['changed', 'active'], array_column($this->app->db()->all('SELECT outcome FROM programme_enrolments ORDER BY id'), 'outcome'));
        self::assertStringContainsString('Moved to another programme', $this->get($base . '/students/' . $dan)->body);

        $this->post($base . '/students/' . $dan . '/guardian', ['date_of_birth' => gmdate('Y-m-d', strtotime('-15 years')), 'guardian_name' => 'Ruth Banda',
            'guardian_contact' => '+265 999 111 222', 'consent' => '1']);
        $page = $this->get($base . '/students/' . $dan)->body;
        self::assertStringContainsString('Under 18. Guardian involvement is your responsibility.', $page);
        self::assertNotNull($this->db('SELECT guardian_consent_at FROM institution_students WHERE guardian_name = ?', ['Ruth Banda']));
        $this->as('bob');
        self::assertSame(403, $this->post($base . '/students/' . $dan . '/guardian', ['date_of_birth' => ''])->status);
    }
}
