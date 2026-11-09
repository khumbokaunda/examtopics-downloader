<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** Institution onboarding: documents by kind of institution, the agreement, review decisions, verification dimensions and setup. */
final class OnboardingTest extends HttpTestCase
{
    use InstitutionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->people();
    }

    private function db(string $sql, array $args = []): mixed
    {
        return $this->app->db()->value($sql, $args);
    }

    /** @return array{name:string,tmp_name:string,size:int,error:int} */
    private function file(string $name, string $content): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($tmp, $content);
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }

    /** The document slot for one of the starting documents. */
    private function slot(string $key): string
    {
        (new \App\Services\Services($this->app))->onboarding()->requirements()->all('document'); // the defaults are put in place on first use
        return (string) $this->db("SELECT uuid FROM institution_requirements WHERE kind = 'document' AND field_key = ?", [$key]);
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    private function lastFlash(): string
    {
        $f = $this->flashes();
        return (string) (end($f)['message'] ?? '');
    }

    private function decide(string $uuid, array $data): string
    {
        $this->post('/platform/institutions/' . $uuid . '/decision', $data);
        return (string) ($this->lastFlash() ?? '');
    }

    public function testATechnicalCollegeSendsItsRegistrationAndIsReviewedDimensionByDimension(): void
    {
        $this->as('olive');
        $this->post('/institutions/register', ['type' => 'tevet', 'registration_authority' => 'TEVETA', 'registration_number' => 'TEV/123',
            'accreditation_authority' => '', 'year_established' => '2009'] + $this->profile('Zomba Technical College'));
        $uuid = (string) $this->db("SELECT uuid FROM institutions WHERE name = 'Zomba Technical College'");
        $app = '/institutions/' . $uuid . '/application';
        $page = $this->get($app)->body;
        self::assertStringContainsString('Upload: Registration certificate', $page, 'a technical college must send its registration');

        $this->post($app . '/submit', ['accept' => '1']);
        self::assertSame('draft', $this->db('SELECT status FROM institutions WHERE uuid = ?', [$uuid]));
        $this->postFiles($app . '/documents', ['slot' => $this->slot('registration_certificate')], ['document' => $this->file('evil.pdf', '<?php echo 1;')]);
        self::assertStringContainsString('PDF, JPEG or PNG', $this->lastFlash(), 'judged by content, not name');
        $this->postFiles($app . '/documents', ['slot' => $this->slot('registration_certificate')], ['document' => $this->file('TEVETA cert.pdf', $this->pdf())]);
        $doc = (string) $this->db('SELECT uuid FROM institution_documents');
        $this->post($app . '/submit', []);
        self::assertStringContainsString('accept it on behalf', $this->lastFlash(), 'the agreement must be accepted');
        $this->post($app . '/submit', ['accept' => '1']);
        self::assertSame('pending', $this->db('SELECT status FROM institutions WHERE uuid = ?', [$uuid]));
        self::assertSame(6, (int) $this->db("SELECT COUNT(*) FROM policy_acceptances WHERE context = 'institution'"), 'each policy version is recorded');
        self::assertSame(200, $this->get($app . '/documents/' . $doc)->status);

        $this->as('carol');
        self::assertSame(404, $this->get($app . '/documents/' . $doc)->status, 'documents are for the institution and its reviewers');

        $this->as('admin');
        self::assertSame(200, $this->get('/platform/institutions/' . $uuid . '/documents/' . $doc)->status);
        $this->decide($uuid, ['decision' => 'review']);
        self::assertStringContainsString('Verify the organisation', $this->decide($uuid, ['decision' => 'verify']), 'approval needs identity and authority');
        self::assertStringContainsString('Give the reason', $this->decide($uuid, ['decision' => 'request_info', 'note' => '']));
        $this->decide($uuid, ['decision' => 'request_info', 'note' => 'Send a letter showing Olive may act for the college.']);
        self::assertSame('info_requested', $this->db('SELECT status FROM institutions WHERE uuid = ?', [$uuid]));

        $this->as('olive');
        self::assertStringContainsString('Send a letter showing Olive', $this->get('/institutions/' . $uuid)->body);
        $this->postFiles($app . '/documents', ['slot' => $this->slot('authority_letter')], ['document' => $this->file('letter.pdf', $this->pdf())]);
        $this->post($app . '/submit', ['response' => '']);
        self::assertStringContainsString('answer the reviewer', $this->lastFlash());
        $this->post($app . '/submit', ['response' => 'The board\'s letter is attached.']);
        self::assertSame('pending', $this->db('SELECT status FROM institutions WHERE uuid = ?', [$uuid]), 'the agreement already accepted is not asked again');

        $this->as('admin');
        self::assertStringContainsString('Verification saved', $this->decide($uuid, ['decision' => 'dimensions', 'identity' => 'verified', 'registration' => 'verified',
            'accreditation' => 'not_applicable', 'representative' => 'verified', 'evidence' => 'Checked TEV/123 with TEVETA; board letter.']));
        self::assertSame(4, (int) $this->db("SELECT COUNT(*) FROM institution_reviews WHERE dimension <> 'platform'"), 'each dimension is its own history row');
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM institution_reviews WHERE dimension = 'registration' AND evidence LIKE 'Checked TEV/123%'"));
        $this->decide($uuid, ['decision' => 'verify']);
        self::assertSame('verified', $this->db('SELECT status FROM institutions WHERE uuid = ?', [$uuid]));

        $this->as('olive');
        $page = $this->get('/institutions/' . $uuid)->body;
        self::assertStringContainsString('Registration verified', $page);
        self::assertStringNotContainsString('Accreditation verified', $page, 'never claimed when it was not verified');
        self::assertStringContainsString('Getting started', $page);
        self::assertStringContainsString('Invite your staff', $page);
        $this->post($app . '/documents/' . $doc . '/remove');
        self::assertNull($this->db('SELECT removed_at FROM institution_documents WHERE uuid = ?', [$doc]), 'evidence stays once approved');
    }

    public function testAPrivateCentreNeedsNoDocumentsAndSuspensionKeepsItsRecords(): void
    {
        $uuid = $this->institution('olive', 'Lilongwe ICT Academy');
        $this->join($uuid, 'olive', 'carol', 'student');
        $this->as('admin');
        self::assertStringContainsString('Give the reason', $this->decide($uuid, ['decision' => 'suspend', 'note' => '']));
        $this->decide($uuid, ['decision' => 'suspend', 'note' => 'Complaint under investigation.']);
        $this->as('carol');
        self::assertSame(403, $this->get('/institutions/' . $uuid)->status);
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM institution_memberships WHERE role = 'student' AND status = 'active'"), 'memberships are kept');
        $this->as('admin');
        $this->decide($uuid, ['decision' => 'verify']);
        self::assertSame('verified', $this->db('SELECT status FROM institutions WHERE uuid = ?', [$uuid]), 'reinstated');
        $this->as('carol');
        self::assertSame(200, $this->get('/institutions/' . $uuid)->status);
    }

    public function testPoliciesArePublic(): void
    {
        $this->newSession();
        self::assertStringContainsString('Data processing agreement', $this->get('/policies')->body);
        $p = $this->get('/policies/data_processing');
        self::assertSame(200, $p->status);
        self::assertStringContainsString('lawful basis', $p->body);
        self::assertSame(404, $this->get('/policies/nothing')->status);
    }
}
