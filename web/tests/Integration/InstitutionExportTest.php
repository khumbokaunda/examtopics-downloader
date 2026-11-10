<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;
use ZipArchive;

/** An institution takes all of its data away in one file, and nobody else's. */
final class InstitutionExportTest extends HttpTestCase
{
    use InstitutionFixtures;

    public function testTheExportHoldsEverythingOfOneInstitutionAndNoSecrets(): void
    {
        [$a] = $this->sittingScenario();
        $this->s->users()->create('gus', self::$pw, \App\Services\Access::MEMBER, ['email' => 'gus@example.com', 'email_verified' => true]);
        $this->s->users()->create('hal', self::$pw, \App\Services\Access::MEMBER, ['email' => 'hal@example.com', 'email_verified' => true]);
        $other = $this->institution('gus', 'Mzuzu Skills Centre');
        $this->join($other, 'gus', 'hal', 'student');
        $this->as('olive');
        $this->post('/institutions/' . $a . '/invitations', ['email' => 'pending@example.com', 'role' => 'student']);

        $this->as('olive');
        self::assertStringContainsString('Download the export', $this->get('/institutions/' . $a . '/export')->body);
        $r = $this->post('/institutions/' . $a . '/export');
        self::assertStringStartsWith('/confirm', $r->headers['Location'] ?? '', 'asks for the password first');

        $this->session->set('_confirmed', ['user' => (int) $this->s->users()->findByUsername('olive')['id'], 'until' => time() + 3600]);
        $r = $this->post('/institutions/' . $a . '/export');
        self::assertSame('application/zip', $r->headers['Content-Type']);
        self::assertTrue($r->deleteAfterSend);
        $zip = new ZipArchive();
        self::assertTrue($zip->open((string) $r->file));
        $names = [];
        for ($n = 0; $n < $zip->numFiles; $n++) {
            $names[] = $zip->getNameIndex($n);
        }
        foreach (['README.txt', 'data/institution.csv', 'data/people.csv', 'data/activity.csv', 'data/institution_memberships.csv', 'data/assessments.csv', 'data/bank_questions.csv'] as $f) {
            self::assertContains($f, $names);
        }
        $members = (string) $zip->getFromName('data/institution_memberships.csv');
        self::assertSame(substr_count($members, "\n") - 1, (int) $this->app->db()->value(
            'SELECT COUNT(*) FROM institution_memberships m JOIN institutions i ON i.id = m.institution_id WHERE i.uuid = ?', [$a]), 'one row per member');
        $people = (string) $zip->getFromName('data/people.csv');
        self::assertStringContainsString('carol', $people);
        self::assertStringNotContainsString('hal', $people, 'nobody from another institution');
        $invites = (string) $zip->getFromName('data/institution_invitations.csv');
        self::assertStringContainsString('pending@example.com', $invites);
        self::assertStringNotContainsString('token', strtok($invites, "\n"), 'no tokens');
        self::assertStringNotContainsString('Mzuzu', implode('', array_map(static fn(string $n) => (string) $zip->getFromName($n), $names)));
        $zip->close();
        unlink((string) $r->file);

        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'institution.exported'"));
        // Only people who manage the institution.
        $this->as('carol');
        self::assertSame(403, $this->get('/institutions/' . $a . '/export')->status);
    }
}
