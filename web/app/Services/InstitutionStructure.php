<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\StructureRepository;
use RuntimeException;

/**
 * The rules for an institution's departments, programmes, classes, class membership and student
 * records. The controller has already checked that the person may act in the institution; this
 * class checks the change, records it in the activity history and throws a RuntimeException with
 * a message for the person when it is refused.
 */
final class InstitutionStructure
{
    /** Roles that can be assigned to teach a class. */
    public const TEACHING_ROLES = ['owner', 'admin', 'instructor'];

    public function __construct(private StructureRepository $repo, private AuditLog $audit)
    {
    }

    public function repo(): StructureRepository
    {
        return $this->repo;
    }

    public static function cleanName(string $name, int $max = 120): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > $max) {
            throw new RuntimeException('A name needs 2 to ' . $max . ' characters.');
        }
        return $name;
    }

    // Departments and programmes ---------------------------------------------------------------

    /** @param array<string,mixed> $i */
    public function addDepartment(array $i, string $name): void
    {
        $name = self::cleanName($name);
        if ($this->repo->departmentNameTaken((int) $i['id'], $name)) {
            throw new RuntimeException('There is already a department called ' . $name . '.');
        }
        $this->repo->createDepartment((int) $i['id'], $name);
        $this->record('institution.department_added', $i, ['name' => $name]);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $d */
    public function renameDepartment(array $i, array $d, string $name): void
    {
        $name = self::cleanName($name);
        if ($name === $d['name']) {
            return;
        }
        if ($this->repo->departmentNameTaken((int) $i['id'], $name, (int) $d['id'])) {
            throw new RuntimeException('There is already a department called ' . $name . '.');
        }
        $this->repo->renameDepartment((int) $i['id'], (int) $d['id'], $name);
        $this->record('institution.department_renamed', $i, ['from' => $d['name'], 'name' => $name]);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $d */
    public function deleteDepartment(array $i, array $d): void
    {
        foreach ($this->repo->programmes((int) $i['id']) as $p) {
            if ((int) ($p['department_id'] ?? 0) === (int) $d['id']) {
                throw new RuntimeException($d['name'] . ' still has programmes. Move them to another department, or remove them, first.');
            }
        }
        $this->repo->deleteDepartment((int) $i['id'], (int) $d['id']);
        $this->record('institution.department_removed', $i, ['name' => $d['name']]);
    }

    /** @param array<string,mixed> $i @param array<string,mixed>|null $p */
    public function saveProgramme(array $i, ?array $p, string $name, string $code, string $departmentUuid): void
    {
        $iid = (int) $i['id'];
        $name = self::cleanName($name, 160);
        $code = trim($code);
        if (mb_strlen($code) > 32) {
            throw new RuntimeException('A programme code has at most 32 characters.');
        }
        $dept = null;
        if ($departmentUuid !== '') {
            $dept = $this->repo->department($iid, $departmentUuid) ?? throw new RuntimeException('Choose one of the institution\'s departments.');
        }
        if ($this->repo->programmeNameTaken($iid, $name, (int) ($p['id'] ?? 0))) {
            throw new RuntimeException('There is already a programme called ' . $name . '.');
        }
        $this->repo->saveProgramme($iid, $p !== null ? (int) $p['id'] : null, $name, $code !== '' ? $code : null, $dept !== null ? (int) $dept['id'] : null);
        $this->record($p === null ? 'institution.programme_added' : 'institution.programme_changed', $i, ['name' => $name]);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $p */
    public function deleteProgramme(array $i, array $p): void
    {
        foreach ($this->repo->programmes((int) $i['id']) as $row) {
            if ((int) $row['id'] === (int) $p['id'] && ((int) $row['class_count'] > 0 || (int) $row['student_count'] > 0)) {
                throw new RuntimeException($p['name'] . ' is used by ' . (int) $row['class_count'] . ' classes and ' . (int) $row['student_count']
                    . ' student records, so it stays. Change those first.');
            }
        }
        $this->repo->deleteProgramme((int) $i['id'], (int) $p['id']);
        $this->record('institution.programme_removed', $i, ['name' => $p['name']]);
    }

    // Classes --------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $i @param array<string,mixed>|null $c
     * @return string the class's uuid
     */
    public function saveClass(array $i, ?array $c, string $name, string $intake, string $programmeUuid): string
    {
        $iid = (int) $i['id'];
        $name = self::cleanName($name);
        $intake = trim((string) preg_replace('/\s+/u', ' ', $intake));
        if (mb_strlen($intake) > 40) {
            throw new RuntimeException('The intake has at most 40 characters.');
        }
        $programme = null;
        if ($programmeUuid !== '') {
            $programme = $this->repo->programme($iid, $programmeUuid) ?? throw new RuntimeException('Choose one of the institution\'s programmes.');
        }
        if ($this->repo->classNameTaken($iid, $name, $intake, (int) ($c['id'] ?? 0))) {
            throw new RuntimeException('There is already a class called ' . $name . ($intake !== '' ? ' for ' . $intake : '') . '.');
        }
        $uuid = $this->repo->saveClass($iid, $c, $name, $intake, $programme !== null ? (int) $programme['id'] : null);
        $this->record($c === null ? 'institution.class_created' : 'institution.class_changed', $i, ['class' => self::label($name, $intake)]);
        return $uuid;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $c */
    public function setArchived(array $i, array $c, bool $archived): void
    {
        $this->repo->setClassStatus((int) $i['id'], (int) $c['id'], $archived ? 'archived' : 'active');
        $this->record($archived ? 'institution.class_archived' : 'institution.class_restored', $i, ['class' => self::label($c['name'], $c['intake'])]);
    }

    /**
     * Put students into a class. Only the institution's active students can be enrolled.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $c @param list<int> $membershipIds
     * @return int how many were added (ones already in the class are left as they are)
     */
    public function enrol(array $i, array $c, array $membershipIds, ?int $by): int
    {
        if ($c['status'] !== 'active') {
            throw new RuntimeException('An archived class takes no new students. Restore it first.');
        }
        $iid = (int) $i['id'];
        $added = 0;
        foreach (array_unique($membershipIds) as $mid) {
            $m = $this->repo->studentById($iid, $mid) ?? throw new RuntimeException('Only the institution\'s students can be put in a class.');
            if ($this->repo->enrol($iid, (int) $c['id'], (int) $m['id'], $by)) {
                $added++;
            }
        }
        if ($added > 0) {
            $this->record('institution.students_enrolled', $i, ['class' => self::label($c['name'], $c['intake']), 'count' => $added]);
        }
        return $added;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $c */
    public function unenrol(array $i, array $c, int $enrolmentId, string $who, ?int $by): void
    {
        $e = $this->repo->enrolment((int) $i['id'], (int) $c['id'], $enrolmentId) ?? throw new RuntimeException('That student is no longer in the class.');
        $this->repo->endEnrolment((int) $i['id'], (int) $e['id'], 'removed', $by);
        $this->record('institution.student_unenrolled', $i, ['class' => self::label($c['name'], $c['intake']), 'student' => $who]);
    }

    /** Move a student to another class: the old stint ends as "moved", a new one starts. @param array<string,mixed> $i @param array<string,mixed> $c */
    public function move(array $i, array $c, int $enrolmentId, string $targetUuid, string $who, ?int $by): string
    {
        $iid = (int) $i['id'];
        $e = $this->repo->enrolment($iid, (int) $c['id'], $enrolmentId) ?? throw new RuntimeException('That student is no longer in the class.');
        $t = $this->repo->class($iid, $targetUuid);
        if ($t === null || $t['status'] !== 'active' || (int) $t['id'] === (int) $c['id']) {
            throw new RuntimeException('Choose another active class to move the student to.');
        }
        $this->repo->endEnrolment($iid, (int) $e['id'], 'moved', $by, (int) $t['id']);
        $this->repo->enrol($iid, (int) $t['id'], (int) $e['membership_id'], $by);
        $this->record('institution.student_moved', $i, ['student' => $who, 'from' => self::label($c['name'], $c['intake']), 'to' => self::label($t['name'], $t['intake'])]);
        return self::label($t['name'], $t['intake']);
    }

    /**
     * @param array<string,mixed> $i @param array<string,mixed> $c
     * @param list<array<string,mixed>> $people active memberships, already found in this institution
     */
    public function setInstructors(array $i, array $c, array $people): void
    {
        $ids = [];
        foreach ($people as $m) {
            if ($m['status'] !== 'active' || !in_array($m['role'], self::TEACHING_ROLES, true)) {
                throw new RuntimeException('Only instructors, administrators and owners can teach a class.');
            }
            $ids[] = (int) $m['id'];
        }
        $before = array_map(static fn(array $r): int => (int) $r['id'], $this->repo->instructors((int) $i['id'], (int) $c['id']));
        sort($before);
        sort($ids);
        if ($before === array_values(array_unique($ids))) {
            return;
        }
        $this->repo->setInstructors((int) $i['id'], (int) $c['id'], $ids);
        $this->record('institution.instructors_set', $i, ['class' => self::label($c['name'], $c['intake']),
            'instructors' => array_map(static fn(array $m): string => (string) $m['username'], $people)]);
    }

    // Student records ------------------------------------------------------------------------

    /** @param array<string,mixed> $i @param array<string,mixed> $student from StructureRepository::student() */
    public function saveRecord(array $i, array $student, string $number, string $programmeUuid, string $status, string $enrolledOn): void
    {
        $iid = (int) $i['id'];
        $number = trim($number);
        if (mb_strlen($number) > 40) {
            throw new RuntimeException('A student number has at most 40 characters.');
        }
        if ($number !== '' && $this->repo->studentNumberTaken($iid, $number, (int) $student['id'])) {
            throw new RuntimeException('Another student already has the number ' . $number . '.');
        }
        if (!isset(StructureRepository::STUDENT_STATUSES[$status])) {
            throw new RuntimeException('Choose the student\'s standing.');
        }
        $programme = null;
        if ($programmeUuid !== '') {
            $programme = $this->repo->programme($iid, $programmeUuid) ?? throw new RuntimeException('Choose one of the institution\'s programmes.');
        }
        if ($enrolledOn !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $enrolledOn) !== 1 || !checkdate((int) substr($enrolledOn, 5, 2), (int) substr($enrolledOn, 8, 2), (int) substr($enrolledOn, 0, 4)))) {
            throw new RuntimeException('Give the enrolment date as a date.');
        }
        $this->repo->saveRecord($iid, (int) $student['id'], $number !== '' ? $number : null, $programme !== null ? (int) $programme['id'] : null, $status, $enrolledOn !== '' ? $enrolledOn : null);
        $this->record('institution.student_record_saved', $i, ['student' => (string) $student['username']]);
    }

    /**
     * Apply what a student import attached to an invitation, once it is accepted: the student
     * number (unless someone took it meanwhile) and programme, and the classes still open.
     *
     * @param array<string,mixed> $details
     */
    public function applyInvitationDetails(int $iid, int $membershipId, array $details, ?int $by): void
    {
        $number = trim((string) ($details['student_number'] ?? ''));
        $number = $number !== '' && !$this->repo->studentNumberTaken($iid, $number, $membershipId) ? $number : null;
        $programme = isset($details['programme']) ? $this->repo->programme($iid, (string) $details['programme']) : null;
        $existing = $this->repo->record($iid, $membershipId);
        if ($number !== null || $programme !== null || $existing === null) {
            $this->repo->saveRecord($iid, $membershipId, $number ?? ($existing['student_number'] ?? null),
                $programme !== null ? (int) $programme['id'] : (isset($existing['programme_id']) ? (int) $existing['programme_id'] : null),
                (string) ($existing['status'] ?? 'active'), (string) ($existing['enrolled_on'] ?? '') !== '' ? (string) $existing['enrolled_on'] : gmdate('Y-m-d'));
        }
        foreach ((array) ($details['classes'] ?? []) as $uuid) {
            $c = $this->repo->class($iid, (string) $uuid);
            if ($c !== null && $c['status'] === 'active') {
                $this->repo->enrol($iid, (int) $c['id'], $membershipId, $by);
            }
        }
    }

    public static function label(string $name, string $intake): string
    {
        return $intake !== '' ? $name . ' (' . $intake . ')' : $name;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $details */
    public function record(string $action, array $i, array $details = []): void
    {
        $this->audit->record($action, ['institution' => (string) $i['name']] + $details, 'institution', (string) $i['uuid']);
    }
}
