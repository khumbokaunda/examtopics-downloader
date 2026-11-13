<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Credentials;
use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** Institutional credentials: templates, eligibility, signatories and approval, duplicates, signing, verification, correction, revocation. */
final class CredentialsTest extends HttpTestCase
{
    use InstitutionFixtures;

    private string $a;
    private string $path;
    private string $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->path, $this->assessment] = $this->sittingScenario();
        $this->as('carol');
        $r = $this->post('/my/assessments/' . $this->a . '/' . $this->assessment . '/start', ['accept' => '1']);
        $this->post('/sit/' . substr((string) $r->headers['Location'], 5) . '/submit');
        $this->app->db()->run("UPDATE assessment_sessions SET passed = 1, percent = 80");
        $this->as('olive');
        $this->post($this->path . '/results/publication', ['action' => 'publish']);
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

    public function testTheLifecycleFromTemplateToVerification(): void
    {
        $base = '/institutions/' . $this->a . '/credentials';
        $tpl = ['category' => 'completion', 'name' => 'Module test pass', 'title' => 'Certificate of Completion', 'body' => 'has passed the CCNA 1 module test',
            'footer' => '', 'rule' => 'assessment', 'assessment' => $this->assessment, 'programme' => '', 'min_percent' => '70', 'requires_approval' => '1', 'validity_months' => '24'];
        $this->as('olive');
        $this->post($base . '/templates', ['category' => 'qualification'] + $tpl);
        self::assertStringContainsString('authorised awarding body', $this->lastFlash(), 'formal qualifications need the platform to authorise the issuer');
        $this->post($base . '/templates', $tpl);
        $t = (string) $this->db('SELECT uuid FROM credential_templates');

        $this->as('erin');
        $page = $this->get($base . '/templates/' . $t . '/eligible')->body;
        self::assertStringContainsString('carol', $page);
        self::assertStringNotContainsString('>dan<', $page, 'dan did not pass');
        $carol = $this->memberUuid($this->a, 'carol');
        $this->post($base . '/templates/' . $t . '/issue', ['members' => [$carol, $this->memberUuid($this->a, 'dan')]]);
        $flash = $this->lastFlash();
        self::assertStringContainsString('0 issued, 1 waiting', $flash);
        self::assertStringContainsString('not eligible', $flash);
        self::assertSame(403, $this->post($base . '/signatories', ['member' => $this->memberUuid($this->a, 'erin'), 'position' => 'X', 'categories' => ['completion']])->status,
            'only an owner chooses signatories');
        $c = (string) $this->db('SELECT uuid FROM credentials');
        self::assertSame(403, $this->post($base . '/' . $c . '/decision', ['decision' => 'approve'])->status, 'not yet a signatory');

        $this->as('olive');
        $this->post($base . '/signatories', ['member' => $this->memberUuid($this->a, 'erin'), 'position' => 'Examinations Officer', 'categories' => ['completion'], 'active' => '1']);
        $this->post($base . '/signatories', ['member' => $this->memberUuid($this->a, 'olive'), 'position' => 'Principal', 'categories' => ['completion', 'achievement'], 'active' => '1']);
        $this->post($base . '/signatories', ['member' => $carol, 'position' => 'Student', 'categories' => ['completion'], 'active' => '1']);
        self::assertSame(2, (int) $this->db('SELECT COUNT(*) FROM credential_signatories'), 'students do not sign');

        $this->as('erin');
        self::assertStringContainsString('Waiting for your approval', $this->get($base)->body);
        $this->post($base . '/' . $c . '/decision', ['decision' => 'approve']);
        $row = $this->app->db()->one('SELECT * FROM credentials WHERE uuid = ?', [$c]);
        self::assertSame('issued', $row['status']);
        self::assertMatchesRegularExpression('/^EXL-\d{4}-[A-Z2-9]{8}$/', (string) $row['number']);
        self::assertSame('Examinations Officer', $row['signatory_position']);
        self::assertSame(gmdate('Y-m-d', strtotime('+24 months')), $row['expires_on']);
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM notifications WHERE title LIKE '%issued you a credential%'"));

        $this->post($base . '/templates/' . $t . '/issue', ['members' => [$carol]]);
        self::assertStringContainsString('already holds ' . $row['number'], $this->lastFlash(), 'no accidental duplicates');
        $this->post($base . '/templates/' . $t . '/issue', ['members' => [$carol], 'allow_duplicate' => '1']);
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM credentials WHERE status = 'pending'"), 'a deliberate duplicate is allowed');
        $this->post($base . '/' . $this->db("SELECT uuid FROM credentials WHERE status = 'pending'") . '/decision', ['decision' => 'decline']);

        $this->newSession();
        $v = $this->get('/verify/' . $row['token']);
        self::assertSame(200, $v->status);
        foreach (['Genuine and current', 'carol', 'has passed the CCNA 1 module test', 'Examinations Officer', 'Organisation identity: verified', 'Accreditation: not verified'] as $text) {
            self::assertStringContainsString($text, $v->body);
        }
        self::assertStringNotContainsString('carol@example.com', $v->body, 'no contact details');
        self::assertStringContainsString('Ed25519', $this->get('/verify/keys')->body);
        $this->app->db()->run("UPDATE credentials SET holder_name = 'Mallory' WHERE uuid = ?", [$c]);
        self::assertStringContainsString('does not match its signature', $this->get('/verify/' . $row['token'])->body, 'a changed record is caught');
        $this->app->db()->run("UPDATE credentials SET holder_name = ? WHERE uuid = ?", [$row['holder_name'], $c]);

        $this->as('carol');
        self::assertStringContainsString($row['number'], $this->get('/certificates')->body);
        $pdf = $this->get('/credentials/' . $c . '/pdf');
        self::assertSame(200, $pdf->status);
        self::assertStringStartsWith('%PDF', $pdf->body);
        self::assertMatchesRegularExpression('#/MediaBox \[0(\.0+)? 0(\.0+)? 595\.28\d* 841\.89\d*\]#', $pdf->body, 'A4 portrait');
        $this->as('dan');
        self::assertSame(404, $this->get('/credentials/' . $c . '/pdf')->status);

        $this->as('olive');
        $this->post($base . '/' . $c . '/correct', ['holder_name' => 'Carol Phiri', 'achievement' => $row['achievement'], 'reason' => 'Full name as registered.']);
        $new = $this->app->db()->one('SELECT * FROM credentials WHERE replaces_id = ?', [(int) $row['id']]);
        self::assertSame(['replaced', 'issued', 'Principal'], [$this->db('SELECT status FROM credentials WHERE uuid = ?', [$c]), $new['status'], $new['signatory_position']]);
        $this->newSession();
        self::assertStringContainsString('Replaced', $this->get('/verify/' . $row['token'])->body);
        self::assertStringContainsString((string) $new['number'], $this->get('/verify/' . $row['token'])->body);

        $this->as('olive');
        $this->post($base . '/' . $new['uuid'] . '/revoke', ['reason' => '']);
        self::assertSame('issued', $this->db('SELECT status FROM credentials WHERE id = ?', [(int) $new['id']]));
        $this->post($base . '/' . $new['uuid'] . '/revoke', ['reason' => 'Result overturned on appeal.']);
        $this->newSession();
        $v = $this->get('/verify/' . $new['token'])->body;
        self::assertStringContainsString('Revoked', $v);
        self::assertStringContainsString('Result overturned on appeal.', $v, 'revoked credentials stay verifiable as revoked');

        $this->as('olive');
        $this->post($this->path . '/results/publication', ['action' => 'unpublish']);
        self::assertStringContainsString('Results taken back: review', $this->get($base)->body);
        $this->as('admin');
        $this->post('/platform/institutions/' . $this->a . '/decision', ['decision' => 'suspend', 'note' => 'Under review.']);
        $this->newSession();
        self::assertStringContainsString('currently suspended', $this->get('/verify/' . $new['token'])->body, 'suspension does not erase what was issued');
        self::assertSame(404, $this->get('/verify/' . str_repeat('ab', 16))->status);
    }

    public function testExpiryAndFormalQualifications(): void
    {
        self::assertSame('expired', Credentials::state(['status' => 'issued', 'expires_on' => '2001-01-01']));
        self::assertSame('issued', Credentials::state(['status' => 'issued', 'expires_on' => null]));
        $this->as('admin');
        $this->post('/platform/institutions/' . $this->a . '/awarding', ['awarding_body' => 'TEVETA']);
        $this->as('olive');
        $this->post('/institutions/' . $this->a . '/credentials/templates', ['category' => 'qualification', 'name' => 'Q', 'title' => 'Certificate', 'body' => 'awarded',
            'rule' => 'manual', 'requires_approval' => '1']);
        self::assertSame('qualification', $this->db('SELECT category FROM credential_templates'), 'an authorised awarding body may');
    }
}
