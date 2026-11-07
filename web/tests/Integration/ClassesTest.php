<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;
use Tests\Support\InstitutionFixtures;

/** An institution's programmes, classes, students and the student import. */
final class ClassesTest extends HttpTestCase
{
    use InstitutionFixtures;

    private string $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->people('fay', 'gus');
        $this->a = $this->institution('olive', 'Lilongwe ICT Academy');
        $this->join($this->a, 'olive', 'bob', 'instructor');
        $this->join($this->a, 'olive', 'carol', 'student');
        $this->join($this->a, 'olive', 'dan', 'student');
        $this->join($this->a, 'olive', 'erin', 'exam_officer');
        $this->as('olive');
    }

    private function db(string $sql, array $args = []): mixed
    {
        return $this->app->db()->value($sql, $args);
    }

    /** Create a class as the signed-in person. @return string its uuid */
    private function newClass(string $name, string $intake = '', string $programme = ''): string
    {
        $this->post('/institutions/' . $this->a . '/classes', ['name' => $name, 'intake' => $intake, 'programme' => $programme]);
        return (string) $this->db('SELECT uuid FROM institution_classes WHERE name = ? AND intake = ?', [$name, $intake]);
    }

    private function lastFlash(): string
    {
        $f = $this->flashes();
        return (string) (end($f)['message'] ?? '');
    }

    private function base(): string
    {
        return '/institutions/' . $this->a;
    }

    public function testProgrammesAndDepartments(): void
    {
        $base = $this->base() . '/structure';
        self::assertSame(200, $this->get($base)->status);
        $this->post($base . '/departments', ['name' => 'ICT']);
        $this->post($base . '/departments', ['name' => 'ICT']);
        self::assertStringContainsString('already a department', $this->lastFlash());
        $dept = (string) $this->db('SELECT uuid FROM institution_departments WHERE name = ?', ['ICT']);
        $this->post($base . '/programmes', ['name' => 'Diploma in Networking', 'code' => 'DNW', 'department' => $dept]);
        $prog = (string) $this->db('SELECT uuid FROM institution_programmes WHERE code = ?', ['DNW']);
        $this->post($base . '/departments/' . $dept . '/delete');
        self::assertStringContainsString('still has programmes', $this->lastFlash());
        $this->newClass('CCNA 1', '2027', $prog);
        $this->post($base . '/programmes/' . $prog . '/delete');
        self::assertStringContainsString('used by 1 classes', $this->lastFlash());
        self::assertStringContainsString('Diploma in Networking', $this->get($base)->body);

        $this->as('bob');
        self::assertSame(403, $this->get($base)->status, 'instructors do not run the structure');
    }

    public function testClassesEnrolMoveAndRemoveKeepHistory(): void
    {
        $c1 = $this->newClass('CCNA 1', '2027');
        $this->newClass('CCNA 1', '2027');
        self::assertStringContainsString('already a class called CCNA 1 for 2027', $this->lastFlash());
        $c2 = $this->newClass('CCNA 2', '2027');
        $carol = $this->memberUuid($this->a, 'carol');
        $dan = $this->memberUuid($this->a, 'dan');

        $this->post($this->base() . '/classes/' . $c1 . '/students', ['students' => [$carol, $dan]]);
        self::assertStringContainsString('2 students added', $this->lastFlash());
        $this->post($this->base() . '/classes/' . $c1 . '/students', ['students' => [$this->memberUuid($this->a, 'bob')]]);
        self::assertStringContainsString('Only the institution\'s students', $this->lastFlash());
        self::assertSame(2, (int) $this->db("SELECT COUNT(*) FROM class_enrolments WHERE status = 'active'"));

        $carolEnrolment = (int) $this->db('SELECT e.id FROM class_enrolments e JOIN institution_memberships m ON m.id = e.membership_id WHERE m.uuid = ?', [$carol]);
        $this->post($this->base() . '/classes/' . $c1 . '/students/' . $carolEnrolment . '/move', ['to' => $c2]);
        self::assertSame('moved', $this->db('SELECT status FROM class_enrolments WHERE id = ?', [$carolEnrolment]));
        self::assertStringContainsString('Moved to CCNA 2', $this->get($this->base() . '/classes/' . $c1, ['ended' => '1'])->body);
        $danEnrolment = (int) $this->db('SELECT e.id FROM class_enrolments e JOIN institution_memberships m ON m.id = e.membership_id WHERE m.uuid = ?', [$dan]);
        $this->post($this->base() . '/classes/' . $c1 . '/students/' . $danEnrolment . '/remove');
        self::assertSame('removed', $this->db('SELECT status FROM class_enrolments WHERE id = ?', [$danEnrolment]), 'leaving a class keeps the row');
        self::assertSame(3, (int) $this->db('SELECT COUNT(*) FROM class_enrolments'));
        $history = $this->get($this->base() . '/students/' . $carol)->body;
        self::assertStringContainsString('CCNA 1 (2027)', $history);
        self::assertStringContainsString('CCNA 2 (2027)', $history);

        $this->post($this->base() . '/classes/' . $c2 . '/archive');
        $this->post($this->base() . '/classes/' . $c2 . '/students', ['students' => [$dan]]);
        self::assertStringContainsString('archived class takes no new students', $this->lastFlash());

        // Leaving the institution ends every class stint.
        $this->post($this->base() . '/members/' . $carol . '/remove');
        self::assertSame(0, (int) $this->db("SELECT COUNT(*) FROM class_enrolments WHERE status = 'active'"));
    }

    public function testWhoSeesWhichClasses(): void
    {
        $taught = $this->newClass('Linux basics');
        $other = $this->newClass('Security');
        $this->post($this->base() . '/classes/' . $taught . '/instructors', ['instructors' => [$this->memberUuid($this->a, 'bob')]]);
        $this->post($this->base() . '/classes/' . $taught . '/instructors', ['instructors' => [$this->memberUuid($this->a, 'carol')]]);
        self::assertStringContainsString('Only instructors, administrators and owners', $this->lastFlash());

        $this->as('bob');
        $list = $this->get($this->base() . '/classes')->body;
        self::assertStringContainsString('Linux basics', $list);
        self::assertStringNotContainsString('Security', $list, 'an instructor sees the classes they teach');
        self::assertSame(200, $this->get($this->base() . '/classes/' . $taught)->status);
        self::assertSame(403, $this->get($this->base() . '/classes/' . $other)->status);
        self::assertSame(403, $this->post($this->base() . '/classes', ['name' => 'Mine'])->status);
        self::assertStringContainsString('You teach it', $this->get($this->base())->body);

        $this->as('erin');
        self::assertSame(200, $this->get($this->base() . '/classes/' . $other)->status, 'an examination officer sees every class');
        self::assertSame(200, $this->get($this->base() . '/students')->status);
        self::assertSame(403, $this->post($this->base() . '/classes/' . $other . '/archive')->status);

        $this->as('carol');
        self::assertSame(403, $this->get($this->base() . '/classes')->status);
        self::assertSame(403, $this->get($this->base() . '/students')->status);
    }

    public function testClassesAndStudentsStayInsideTheirInstitution(): void
    {
        $mine = $this->newClass('CCNA 1');
        $b = $this->institution('fay', 'Mzuzu Academy');
        $this->join($b, 'fay', 'gus', 'student');
        $this->as('fay');
        $this->post('/institutions/' . $b . '/classes', ['name' => 'Theirs']);
        $theirs = (string) $this->db('SELECT uuid FROM institution_classes WHERE name = ?', ['Theirs']);
        $gus = $this->memberUuid($b, 'gus');

        $this->as('olive');
        self::assertSame(404, $this->get($this->base() . '/classes/' . $theirs)->status);
        self::assertSame(404, $this->get($this->base() . '/students/' . $gus)->status);
        $this->post($this->base() . '/classes/' . $mine . '/students', ['students' => [$gus]]);
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM class_enrolments'), 'another institution\'s student is not put in a class');

        // The database refuses a cross-institution enrolment even if the code did not.
        $iidA = (int) $this->db('SELECT id FROM institutions WHERE uuid = ?', [$this->a]);
        $classA = (int) $this->db('SELECT id FROM institution_classes WHERE uuid = ?', [$mine]);
        $gusId = (int) $this->db('SELECT id FROM institution_memberships WHERE uuid = ?', [$gus]);
        $this->expectException(\PDOException::class);
        $this->app->db()->run("INSERT INTO class_enrolments (institution_id, class_id, membership_id, status, enrolled_at) VALUES (?, ?, ?, 'active', NOW())", [$iidA, $classA, $gusId]);
    }

    public function testStudentRecords(): void
    {
        $carol = $this->memberUuid($this->a, 'carol');
        $dan = $this->memberUuid($this->a, 'dan');
        $this->post($this->base() . '/students/' . $carol, ['student_number' => 'LIA-001', 'programme' => '', 'status' => 'active', 'enrolled_on' => '2027-01-10']);
        $this->post($this->base() . '/students/' . $dan, ['student_number' => 'LIA-001', 'programme' => '', 'status' => 'active', 'enrolled_on' => '']);
        self::assertStringContainsString('already has the number LIA-001', $this->lastFlash());
        self::assertStringContainsString('LIA-001', $this->get($this->base() . '/students')->body);
        self::assertSame(404, $this->get($this->base() . '/students/' . $this->memberUuid($this->a, 'bob'))->status, 'an instructor has no student record');
    }

    public function testImportPreviewsEveryRowThenEnrolsOrInvites(): void
    {
        $c = $this->newClass('CCNA 1', '2027');
        $this->post($this->base() . '/students/' . $this->memberUuid($this->a, 'dan'), ['student_number' => 'TAKEN', 'programme' => '', 'status' => 'active', 'enrolled_on' => '']);
        self::assertStringContainsString('email,name,student_number', $this->get($this->base() . '/students/import/template.csv')->body);

        $csv = "Email,Name,Student Number,Class\n"
            . "carol@example.com,Carol,A-1,CCNA 1\n"          // already a student: updated and enrolled
            . "newperson@example.com,New Person,A-2,CCNA 1 2027\n" // no account: invited
            . "erin@example.com,Erin,A-3,CCNA 1\n"            // an examination officer
            . "not-an-email,X,A-4,\n"
            . "fay@example.com,Fay,A-1,\n"                    // number repeated
            . "gus@example.com,Gus,TAKEN,Nope\n"              // number taken, unknown class
            . "carol@example.com,Again,,\n";
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, $csv);
        $preview = $this->postFiles($this->base() . '/students/import', [], ['file' => ['name' => 's.csv', 'tmp_name' => $tmp, 'size' => strlen($csv), 'error' => UPLOAD_ERR_OK]]);
        self::assertSame(200, $preview->status);
        self::assertStringContainsString('2 ready, 5 with problems', $preview->body);
        foreach (['not Exam officer', 'not valid', 'also on line 2', 'already has the number TAKEN', 'no single open class called &quot;Nope&quot;', 'same email address is on line 2'] as $why) {
            if ($why === 'not Exam officer') {
                self::assertStringContainsString('as Examination officer, not as a student', $preview->body);
                continue;
            }
            self::assertStringContainsString($why, $preview->body);
        }
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM class_enrolments'), 'nothing is written before confirming');
        self::assertSame(0, (int) $this->db("SELECT COUNT(*) FROM institution_invitations WHERE email = 'newperson@example.com'"));

        $this->post($this->base() . '/students/import/confirm');
        self::assertStringContainsString('1 students updated and put in their classes, 1 invited, 5 not imported', $this->lastFlash());
        self::assertSame('A-1', $this->db('SELECT student_number FROM institution_students s JOIN institution_memberships m ON m.id = s.membership_id WHERE m.uuid = ?',
            [$this->memberUuid($this->a, 'carol')]));
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM class_enrolments WHERE status = 'active'"));
        $results = $this->get($this->base() . '/students/import/results.csv')->body;
        self::assertSame(8, count(array_filter(explode("\n", $results))), 'every row has an outcome');
        self::assertStringContainsString('Not imported', $results);
        preg_match('#/invitations/([a-f0-9]{48})#', $results, $m);
        self::assertNotEmpty($m, 'without email set up, the link is in the results');

        // The invited person signs up and accepts: the number and class apply.
        $this->s->users()->create('newperson', self::$pw, 'member', ['email' => 'newperson@example.com', 'email_verified' => true]);
        $this->as('newperson');
        $this->post('/invitations/' . $m[1] . '/accept');
        $member = $this->memberUuid($this->a, 'newperson');
        self::assertSame('A-2', $this->db('SELECT student_number FROM institution_students s JOIN institution_memberships m ON m.id = s.membership_id WHERE m.uuid = ?', [$member]));
        self::assertSame(1, (int) $this->db("SELECT COUNT(*) FROM class_enrolments e JOIN institution_memberships m ON m.id = e.membership_id WHERE m.uuid = ? AND e.status = 'active'", [$member]));
        self::assertStringContainsString('CCNA 1 (2027)', $this->get($this->base())->body, 'the student sees their class');
        self::assertSame($c, (string) $this->db('SELECT c.uuid FROM class_enrolments e JOIN institution_classes c ON c.id = e.class_id JOIN institution_memberships m ON m.id = e.membership_id WHERE m.uuid = ?', [$member]));
    }

    public function testImportRefusesABadFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, "name,number\nX,1\n");
        $r = $this->postFiles($this->base() . '/students/import', [], ['file' => ['name' => 's.csv', 'tmp_name' => $tmp, 'size' => 16, 'error' => UPLOAD_ERR_OK]]);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('one called &quot;email&quot;', $r->body);
        $this->as('bob');
        self::assertSame(403, $this->get($this->base() . '/students/import')->status);
    }
}
