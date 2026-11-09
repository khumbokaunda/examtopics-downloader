<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/**
 * What the platform asks of institutions (documents, extra profile fields, which built-in fields
 * show), the step-by-step registration form, approving from any open stage, and deleting an
 * institution with its protections.
 */
final class InstitutionSetupTest extends HttpTestCase
{
    use InstitutionFixtures;

    private const BASE = '/platform/institution-requirements';

    protected function setUp(): void
    {
        parent::setUp();
        $this->people();
    }

    private function value(string $sql, array $args = []): mixed
    {
        return $this->app->db()->value($sql, $args);
    }

    private function requirement(string $label): string
    {
        return (string) $this->value('SELECT uuid FROM institution_requirements WHERE label = ?', [$label]);
    }

    private function message(): string
    {
        $f = $this->flashes();
        return (string) (end($f)['message'] ?? '');
    }

    public function testTheAdministratorDecidesWhatInstitutionsProvide(): void
    {
        $this->as('olive');
        self::assertSame(403, $this->get(self::BASE)->status, 'only platform administrators');

        $this->as('admin');
        $page = $this->get(self::BASE);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Registration certificate', $page->body, 'the starting list is in place');

        $this->post(self::BASE, ['kind' => 'document', 'label' => 'Tax clearance certificate', 'required' => '1', 'applies_to' => ['private_centre']]);
        $this->post(self::BASE, ['kind' => 'field', 'label' => 'Number of students', 'field_type' => 'number', 'required' => '1']);
        $this->post(self::BASE, ['kind' => 'field', 'label' => 'Ownership', 'field_type' => 'select', 'options' => '']);
        self::assertStringContainsString('List the choices', $this->message());
        $this->post(self::BASE, ['kind' => 'field', 'label' => 'Ownership', 'field_type' => 'select', 'options' => "Private\nPublic\n\nChurch"]);
        self::assertSame("Private\nPublic\nChurch", $this->value("SELECT options FROM institution_requirements WHERE label = 'Ownership'"));
        $this->post(self::BASE . '/' . $this->requirement('Website') . '/toggle');
        self::assertSame(0, (int) $this->value("SELECT active FROM institution_requirements WHERE field_key = 'website'"));
        $this->post(self::BASE . '/' . $this->requirement('Ownership') . '/move', ['to' => 'up']);
        self::assertLessThan((int) $this->value("SELECT position FROM institution_requirements WHERE label = 'Number of students'"),
            (int) $this->value("SELECT position FROM institution_requirements WHERE label = 'Ownership'"));
        self::assertSame(4, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'platform.requirement_%'"), 'each saved change is recorded');

        // The registration form follows: steps, the extra fields, no website.
        $this->as('olive');
        $form = $this->get('/institutions/register')->body;
        self::assertStringContainsString('data-wizard', $form);
        self::assertStringContainsString('data-wizard-panel data-title="More details"', $form);
        self::assertStringContainsString('name="custom[' . $this->requirement('Number of students') . ']"', $form);
        self::assertStringNotContainsString('id="inst-website"', $form, 'a hidden built-in field is not asked');

        $students = $this->requirement('Number of students');
        $ownership = $this->requirement('Ownership');
        $bad = $this->post('/institutions/register', $this->profile('Blantyre Code School') + ['custom' => [$students => 'many', $ownership => 'Mine']]);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Number of students must be a number.', $bad->body);
        self::assertStringContainsString('Ownership must be one of the choices.', $bad->body);
        self::assertStringContainsString('value="many"', $bad->body, 'the answers come back');
        self::assertSame(422, $this->post('/institutions/register', $this->profile('Blantyre Code School'))->status, 'a required field is required');

        $this->post('/institutions/register', $this->profile('Blantyre Code School') + ['custom' => [$students => '420', $ownership => 'Church']]);
        $uuid = (string) $this->value("SELECT uuid FROM institutions WHERE name = 'Blantyre Code School'");
        self::assertNull($this->value("SELECT website FROM institutions WHERE uuid = ?", [$uuid]), 'a hidden field is not taken from the post');
        self::assertSame('420', $this->value('SELECT v.value FROM institution_field_values v JOIN institution_requirements r ON r.id = v.requirement_id
            WHERE r.label = ?', ['Number of students']));

        // The required document for this kind of institution has its own upload.
        $app = $this->get('/institutions/' . $uuid . '/application')->body;
        self::assertStringContainsString('Tax clearance certificate', $app);
        self::assertStringContainsString('Upload: Tax clearance certificate.', $app);
        self::assertStringContainsString('data-autosubmit', $app, 'one upload per document, no list to choose from');
        $pdf = (string) tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $this->postFiles('/institutions/' . $uuid . '/application/documents', ['slot' => $this->requirement('Tax clearance certificate')],
            ['document' => ['name' => 'tax.pdf', 'tmp_name' => $pdf, 'size' => (int) filesize($pdf), 'error' => UPLOAD_ERR_OK]]);
        self::assertStringNotContainsString('Upload: Tax clearance certificate.', $this->get('/institutions/' . $uuid . '/application')->body);

        // Editing the profile keeps what a hidden field held.
        $this->app->db()->run("UPDATE institutions SET website = 'https://kept.example' WHERE uuid = ?", [$uuid]);
        $this->post('/institutions/' . $uuid . '/profile', $this->profile('Blantyre Code School') + ['custom' => [$students => '450', $ownership => 'Church']]);
        self::assertSame('https://kept.example', $this->value('SELECT website FROM institutions WHERE uuid = ?', [$uuid]));
        self::assertSame('450', $this->value('SELECT v.value FROM institution_field_values v JOIN institution_requirements r ON r.id = v.requirement_id
            WHERE r.label = ?', ['Number of students']));

        // The reviewer sees the answers.
        $this->as('admin');
        $review = $this->get('/platform/institutions/' . $uuid)->body;
        self::assertStringContainsString('Number of students', $review);
        self::assertStringContainsString('450', $review);
    }

    public function testAFieldForOtherKindsIsNotAsked(): void
    {
        $this->as('admin');
        $this->post(self::BASE, ['kind' => 'field', 'label' => 'University charter number', 'required' => '1', 'applies_to' => ['university']]);
        $this->as('olive');
        self::assertStringContainsString('data-applies="university"', $this->get('/institutions/register')->body);
        $this->post('/institutions/register', $this->profile('Mzuzu Tech Hub'));
        self::assertNotNull($this->value("SELECT id FROM institutions WHERE name = 'Mzuzu Tech Hub'"), 'a private centre is not asked for a charter number');
    }

    public function testApprovingWorksFromAnyOpenStage(): void
    {
        $uuid = $this->institution('olive', 'Kasungu Learning Centre', false);
        self::assertSame('draft', $this->value('SELECT status FROM institutions WHERE uuid = ?', [$uuid]));
        $this->as('admin');
        $this->post('/platform/institutions/' . $uuid . '/decision', ['decision' => 'verify']);
        self::assertStringContainsString('Verify the organisation', $this->message(), 'identity and authority still come first');
        $this->post('/platform/institutions/' . $uuid . '/decision', ['decision' => 'verify', 'identity' => 'verified', 'representative' => 'verified',
            'evidence' => 'Visited the centre.']);
        self::assertSame('verified', $this->value('SELECT status FROM institutions WHERE uuid = ?', [$uuid]), 'never stuck as a draft once approved');
        self::assertStringContainsString('milestones', $this->get('/platform/institutions/' . $uuid)->body);
    }

    public function testDeletingIsHardToDoByAccident(): void
    {
        $uuid = $this->institution('olive', 'Dedza Skills Academy');
        $page = '/platform/institutions/' . $uuid;
        $this->as('admin');
        self::assertStringContainsString('Suspend it first to delete it.', $this->get($page)->body);
        $this->post('/confirm', ['password' => 'correct horse battery', 'next' => $page]);
        $this->post($page . '/delete', ['name' => 'Dedza Skills Academy', 'reason' => 'Closed.']);
        self::assertStringContainsString('Suspend it first', $this->message(), 'an approved institution is not deleted');

        $this->post($page . '/decision', ['decision' => 'suspend', 'note' => 'Closing down.']);
        $this->post($page . '/delete', ['name' => 'Dedza Skills', 'reason' => 'Closed.']);
        self::assertNull($this->value('SELECT deleted_at FROM institutions WHERE uuid = ?', [$uuid]), 'the name must be typed exactly');
        $this->post($page . '/delete', ['name' => 'Dedza Skills Academy', 'reason' => '']);
        self::assertNull($this->value('SELECT deleted_at FROM institutions WHERE uuid = ?', [$uuid]), 'a reason is needed');
        $this->post($page . '/delete', ['name' => 'Dedza Skills Academy', 'reason' => 'The academy closed in October.']);
        self::assertNotNull($this->value('SELECT deleted_at FROM institutions WHERE uuid = ?', [$uuid]));
        self::assertStringContainsString('You can restore it', $this->message());
        self::assertStringNotContainsString('Dedza Skills Academy', $this->get('/platform/institutions')->body, 'hidden from the list');
        self::assertStringContainsString('Dedza Skills Academy', $this->get('/platform/institutions', ['status' => 'deleted'])->body);

        $this->post($page . '/purge', ['name' => 'Dedza Skills Academy']);
        self::assertStringContainsString('30 days', $this->message(), 'not removed for good straight away');
        self::assertNotNull($this->value('SELECT id FROM institutions WHERE uuid = ?', [$uuid]));

        $this->as('olive');
        self::assertSame(404, $this->get('/institutions/' . $uuid)->status, 'closed to its members');
        self::assertStringNotContainsString('Dedza Skills Academy', $this->get('/institutions')->body);

        $this->as('admin');
        $this->post($page . '/restore');
        self::assertNull($this->value('SELECT deleted_at FROM institutions WHERE uuid = ?', [$uuid]));
        self::assertSame('suspended', $this->value('SELECT status FROM institutions WHERE uuid = ?', [$uuid]), 'restored, still suspended');

        $this->post('/confirm', ['password' => 'correct horse battery', 'next' => $page]);
        $this->post($page . '/delete', ['name' => 'Dedza Skills Academy', 'reason' => 'Closed for good.']);
        $this->app->db()->run('UPDATE institutions SET deleted_at = ? WHERE uuid = ?', [gmdate('Y-m-d H:i:s', time() - 31 * 86400), $uuid]);
        $this->post($page . '/purge', ['name' => 'Dedza Skills Academy']);
        self::assertNull($this->value('SELECT id FROM institutions WHERE uuid = ?', [$uuid]), 'removed once 30 days have passed');
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action = 'institution.purged'"));
    }

    public function testDeletingAsksForConfirmationFirst(): void
    {
        $uuid = $this->institution('olive', 'Salima Training Hub', false);
        $this->as('admin');
        $r = $this->post('/platform/institutions/' . $uuid . '/delete', ['name' => 'Salima Training Hub', 'reason' => 'Test application.']);
        self::assertStringStartsWith('/confirm', (string) ($r->headers['Location'] ?? ''));
        self::assertNull($this->value('SELECT deleted_at FROM institutions WHERE uuid = ?', [$uuid]));
    }
}
