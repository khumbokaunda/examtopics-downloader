<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Ids;

/**
 * An institution's departments, programmes and classes, who teaches and who is in each class,
 * and its student records. Every method takes the institution's id and puts it in the WHERE
 * clause; the composite foreign keys of migration 028 refuse a cross-institution reference even
 * if a caller got that wrong.
 */
final class StructureRepository
{
    public const STUDENT_STATUSES = ['active' => 'Active', 'suspended' => 'Suspended', 'completed' => 'Completed', 'withdrawn' => 'Withdrawn'];

    public function __construct(private Database $db)
    {
    }

    // Departments ----------------------------------------------------------------------------

    /** @return list<array<string,mixed>> with how many programmes each has */
    public function departments(int $iid): array
    {
        return $this->db->all('SELECT d.*, (SELECT COUNT(*) FROM institution_programmes p WHERE p.department_id = d.id) AS programme_count
                               FROM institution_departments d WHERE d.institution_id = ? ORDER BY d.name', [$iid]);
    }

    /** @return array<string,mixed>|null */
    public function department(int $iid, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM institution_departments WHERE institution_id = ? AND uuid = ?', [$iid, $uuid]) : null;
    }

    public function departmentNameTaken(int $iid, string $name, int $exceptId = 0): bool
    {
        return $this->db->value('SELECT 1 FROM institution_departments WHERE institution_id = ? AND name = ? AND id <> ?', [$iid, $name, $exceptId]) !== null;
    }

    public function createDepartment(int $iid, string $name): void
    {
        $now = Database::now();
        $this->db->run('INSERT INTO institution_departments (uuid, institution_id, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [Ids::uuid(), $iid, $name, $now, $now]);
    }

    public function renameDepartment(int $iid, int $id, string $name): void
    {
        $this->db->run('UPDATE institution_departments SET name = ?, updated_at = ? WHERE institution_id = ? AND id = ?', [$name, Database::now(), $iid, $id]);
    }

    public function deleteDepartment(int $iid, int $id): void
    {
        $this->db->run('DELETE FROM institution_departments WHERE institution_id = ? AND id = ?', [$iid, $id]);
    }

    // Programmes -----------------------------------------------------------------------------

    /** @return list<array<string,mixed>> with the department's name and how many classes and students use each */
    public function programmes(int $iid): array
    {
        return $this->db->all('SELECT p.*, d.name AS department_name, d.uuid AS department_uuid,
                                      (SELECT COUNT(*) FROM institution_classes c WHERE c.programme_id = p.id) AS class_count,
                                      (SELECT COUNT(*) FROM institution_students s WHERE s.programme_id = p.id) AS student_count
                               FROM institution_programmes p LEFT JOIN institution_departments d ON d.id = p.department_id
                               WHERE p.institution_id = ? ORDER BY p.name', [$iid]);
    }

    /** @return array<string,mixed>|null */
    public function programme(int $iid, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM institution_programmes WHERE institution_id = ? AND uuid = ?', [$iid, $uuid]) : null;
    }

    /** A programme by its name or code, ignoring case (for imports). @return array<string,mixed>|null */
    public function programmeByName(int $iid, string $name): ?array
    {
        return $this->db->one('SELECT * FROM institution_programmes WHERE institution_id = ? AND (LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?)) LIMIT 1', [$iid, $name, $name]);
    }

    public function programmeNameTaken(int $iid, string $name, int $exceptId = 0): bool
    {
        return $this->db->value('SELECT 1 FROM institution_programmes WHERE institution_id = ? AND name = ? AND id <> ?', [$iid, $name, $exceptId]) !== null;
    }

    public function saveProgramme(int $iid, ?int $id, string $name, ?string $code, ?int $departmentId): void
    {
        $now = Database::now();
        if ($id === null) {
            $this->db->run('INSERT INTO institution_programmes (uuid, institution_id, department_id, name, code, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [Ids::uuid(), $iid, $departmentId, $name, $code, $now, $now]);
        } else {
            $this->db->run('UPDATE institution_programmes SET name = ?, code = ?, department_id = ?, updated_at = ? WHERE institution_id = ? AND id = ?',
                [$name, $code, $departmentId, $now, $iid, $id]);
        }
    }

    public function deleteProgramme(int $iid, int $id): void
    {
        $this->db->run('DELETE FROM institution_programmes WHERE institution_id = ? AND id = ?', [$iid, $id]);
    }

    // Classes --------------------------------------------------------------------------------

    /**
     * Classes with their programme, number of students and instructors' names.
     *
     * @param int|null $teacher only the classes this membership teaches
     * @return list<array<string,mixed>>
     */
    public function classes(int $iid, string $status = 'active', ?int $teacher = null): array
    {
        $sql = "SELECT c.*, p.name AS programme_name,
                       (SELECT COUNT(*) FROM class_enrolments e WHERE e.class_id = c.id AND e.status = 'active') AS student_count,
                       (SELECT GROUP_CONCAT(COALESCE(NULLIF(u.display_name, ''), u.username) ORDER BY u.display_name, u.username SEPARATOR ', ')
                          FROM class_instructors ci JOIN institution_memberships m ON m.id = ci.membership_id JOIN users u ON u.id = m.user_id
                         WHERE ci.class_id = c.id AND m.status = 'active') AS instructor_names
                FROM institution_classes c LEFT JOIN institution_programmes p ON p.id = c.programme_id
                WHERE c.institution_id = ?";
        $args = [$iid];
        if ($status !== '') {
            $sql .= ' AND c.status = ?';
            $args[] = $status;
        }
        if ($teacher !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM class_instructors t WHERE t.class_id = c.id AND t.membership_id = ?)';
            $args[] = $teacher;
        }
        return $this->db->all($sql . ' ORDER BY c.name, c.intake', $args);
    }

    /** @return array<string,mixed>|null */
    public function class(int $iid, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT c.*, p.name AS programme_name, p.uuid AS programme_uuid FROM institution_classes c
            LEFT JOIN institution_programmes p ON p.id = c.programme_id WHERE c.institution_id = ? AND c.uuid = ?', [$iid, $uuid]) : null;
    }

    /** An active class by name (and intake, when given), ignoring case (for imports). @return array<string,mixed>|null */
    public function classByName(int $iid, string $name): ?array
    {
        $rows = $this->db->all("SELECT * FROM institution_classes WHERE institution_id = ? AND status = 'active'
                                AND (LOWER(name) = LOWER(?) OR LOWER(CONCAT(name, ' ', intake)) = LOWER(?))", [$iid, $name, $name]);
        return count($rows) === 1 ? $rows[0] : null;
    }

    public function classNameTaken(int $iid, string $name, string $intake, int $exceptId = 0): bool
    {
        return $this->db->value('SELECT 1 FROM institution_classes WHERE institution_id = ? AND name = ? AND intake = ? AND id <> ?', [$iid, $name, $intake, $exceptId]) !== null;
    }

    /** @return string the class's uuid */
    public function saveClass(int $iid, ?array $existing, string $name, string $intake, ?int $programmeId): string
    {
        $now = Database::now();
        if ($existing === null) {
            $uuid = Ids::uuid();
            $this->db->run('INSERT INTO institution_classes (uuid, institution_id, programme_id, name, intake, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$uuid, $iid, $programmeId, $name, $intake, $now, $now]);
            return $uuid;
        }
        $this->db->run('UPDATE institution_classes SET name = ?, intake = ?, programme_id = ?, updated_at = ? WHERE institution_id = ? AND id = ?',
            [$name, $intake, $programmeId, $now, $iid, (int) $existing['id']]);
        return (string) $existing['uuid'];
    }

    public function setClassStatus(int $iid, int $id, string $status): void
    {
        $this->db->run('UPDATE institution_classes SET status = ?, updated_at = ? WHERE institution_id = ? AND id = ?', [$status, Database::now(), $iid, $id]);
    }

    public function teaches(int $iid, int $classId, int $membershipId): bool
    {
        return $this->db->value('SELECT 1 FROM class_instructors WHERE institution_id = ? AND class_id = ? AND membership_id = ?', [$iid, $classId, $membershipId]) !== null;
    }

    /** @return list<array<string,mixed>> the class's instructors (active memberships) */
    public function instructors(int $iid, int $classId): array
    {
        return $this->db->all("SELECT m.id, m.uuid, m.role, u.username, u.display_name FROM class_instructors ci
                               JOIN institution_memberships m ON m.id = ci.membership_id JOIN users u ON u.id = m.user_id
                               WHERE ci.institution_id = ? AND ci.class_id = ? AND m.status = 'active' ORDER BY u.display_name, u.username", [$iid, $classId]);
    }

    /** @param list<int> $membershipIds */
    public function setInstructors(int $iid, int $classId, array $membershipIds): void
    {
        $this->db->transaction(function (Database $db) use ($iid, $classId, $membershipIds): void {
            $db->run('DELETE FROM class_instructors WHERE institution_id = ? AND class_id = ?', [$iid, $classId]);
            $now = Database::now();
            foreach (array_unique($membershipIds) as $m) {
                $db->run('INSERT INTO class_instructors (class_id, membership_id, institution_id, assigned_at) VALUES (?, ?, ?, ?)', [$classId, $m, $iid, $now]);
            }
        });
    }

    // Enrolments -----------------------------------------------------------------------------

    /**
     * The students of a class with their accounts and records; ended stints too when asked.
     *
     * @return list<array<string,mixed>>
     */
    public function classStudents(int $iid, int $classId, bool $withEnded = false): array
    {
        return $this->db->all("SELECT e.id AS enrolment_id, e.status AS enrolment_status, e.enrolled_at, e.ended_at, e.moved_to,
                                      mc.name AS moved_to_name, m.id AS membership_id, m.uuid AS membership_uuid, m.status AS membership_status,
                                      u.username, u.display_name, u.email, s.student_number, s.status AS student_status
                               FROM class_enrolments e
                               JOIN institution_memberships m ON m.id = e.membership_id JOIN users u ON u.id = m.user_id
                               LEFT JOIN institution_students s ON s.membership_id = m.id
                               LEFT JOIN institution_classes mc ON mc.id = e.moved_to
                               WHERE e.institution_id = ? AND e.class_id = ?" . ($withEnded ? '' : " AND e.status = 'active'")
            . " ORDER BY e.status = 'active' DESC, u.display_name, u.username, e.id DESC", [$iid, $classId]);
    }

    /** @return array<string,mixed>|null the student's current stint in the class */
    public function activeEnrolment(int $iid, int $classId, int $membershipId): ?array
    {
        return $this->db->one("SELECT * FROM class_enrolments WHERE institution_id = ? AND class_id = ? AND membership_id = ? AND status = 'active'",
            [$iid, $classId, $membershipId]);
    }

    /** @return array<string,mixed>|null an active enrolment of this class */
    public function enrolment(int $iid, int $classId, int $id): ?array
    {
        return $this->db->one("SELECT * FROM class_enrolments WHERE institution_id = ? AND class_id = ? AND id = ? AND status = 'active'", [$iid, $classId, $id]);
    }

    /** @return bool false when the student is already in the class */
    public function enrol(int $iid, int $classId, int $membershipId, ?int $by): bool
    {
        if ($this->activeEnrolment($iid, $classId, $membershipId) !== null) {
            return false;
        }
        $this->db->run("INSERT INTO class_enrolments (institution_id, class_id, membership_id, status, enrolled_at, enrolled_by) VALUES (?, ?, ?, 'active', ?, ?)",
            [$iid, $classId, $membershipId, Database::now(), $by]);
        return true;
    }

    public function endEnrolment(int $iid, int $enrolmentId, string $status, ?int $by, ?int $movedTo = null): void
    {
        $this->db->run("UPDATE class_enrolments SET status = ?, ended_at = ?, ended_by = ?, moved_to = ? WHERE institution_id = ? AND id = ? AND status = 'active'",
            [$status, Database::now(), $by, $movedTo, $iid, $enrolmentId]);
    }

    /** End every class stint of someone leaving the institution. */
    public function endAllEnrolments(int $iid, int $membershipId, ?int $by): void
    {
        $this->db->run("UPDATE class_enrolments SET status = 'removed', ended_at = ?, ended_by = ? WHERE institution_id = ? AND membership_id = ? AND status = 'active'",
            [Database::now(), $by, $iid, $membershipId]);
        $this->db->run('DELETE FROM class_instructors WHERE institution_id = ? AND membership_id = ?', [$iid, $membershipId]);
    }

    /** @return list<array<string,mixed>> every stint of a student, newest first, with the class */
    public function enrolmentsOf(int $iid, int $membershipId): array
    {
        return $this->db->all('SELECT e.*, c.name AS class_name, c.intake, c.uuid AS class_uuid, mc.name AS moved_to_name FROM class_enrolments e
                               JOIN institution_classes c ON c.id = e.class_id LEFT JOIN institution_classes mc ON mc.id = e.moved_to
                               WHERE e.institution_id = ? AND e.membership_id = ? ORDER BY e.status = \'active\' DESC, e.id DESC', [$iid, $membershipId]);
    }

    // Students -------------------------------------------------------------------------------

    /**
     * Every active student of the institution, with their record and current classes.
     *
     * @return list<array<string,mixed>>
     */
    public function students(int $iid): array
    {
        return $this->db->all("SELECT m.id AS membership_id, m.uuid AS membership_uuid, m.joined_at, u.username, u.display_name, u.email,
                                      s.student_number, s.status AS student_status, s.enrolled_on, p.name AS programme_name,
                                      (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') FROM class_enrolments e JOIN institution_classes c ON c.id = e.class_id
                                        WHERE e.membership_id = m.id AND e.status = 'active') AS class_names
                               FROM institution_memberships m JOIN users u ON u.id = m.user_id
                               LEFT JOIN institution_students s ON s.membership_id = m.id
                               LEFT JOIN institution_programmes p ON p.id = s.programme_id
                               WHERE m.institution_id = ? AND m.role = 'student' AND m.status = 'active'
                               ORDER BY u.display_name, u.username", [$iid]);
    }

    /** @return array<string,mixed>|null a student-role membership of this institution with its record */
    public function student(int $iid, string $membershipUuid): ?array
    {
        if (!Ids::isUuid($membershipUuid)) {
            return null;
        }
        return $this->db->one("SELECT m.*, u.username, u.display_name, u.email, s.student_number, s.programme_id, s.status AS student_status, s.enrolled_on,
                                      p.uuid AS programme_uuid, p.name AS programme_name
                               FROM institution_memberships m JOIN users u ON u.id = m.user_id
                               LEFT JOIN institution_students s ON s.membership_id = m.id LEFT JOIN institution_programmes p ON p.id = s.programme_id
                               WHERE m.institution_id = ? AND m.uuid = ? AND m.role = 'student'", [$iid, $membershipUuid]);
    }

    /** @return array<string,mixed>|null an active student-role membership of this institution, by its id */
    public function studentById(int $iid, int $membershipId): ?array
    {
        return $this->db->one("SELECT * FROM institution_memberships WHERE institution_id = ? AND id = ? AND role = 'student' AND status = 'active'", [$iid, $membershipId]);
    }

    public function studentNumberTaken(int $iid, string $number, int $exceptMembershipId = 0): bool
    {
        return $this->db->value('SELECT 1 FROM institution_students WHERE institution_id = ? AND student_number = ? AND membership_id <> ?',
            [$iid, $number, $exceptMembershipId]) !== null;
    }

    /** @return array<string,mixed>|null */
    public function record(int $iid, int $membershipId): ?array
    {
        return $this->db->one('SELECT * FROM institution_students WHERE institution_id = ? AND membership_id = ?', [$iid, $membershipId]);
    }

    public function saveRecord(int $iid, int $membershipId, ?string $number, ?int $programmeId, string $status, ?string $enrolledOn): void
    {
        $this->db->run('INSERT INTO institution_students (membership_id, institution_id, student_number, programme_id, status, enrolled_on, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE student_number = VALUES(student_number), programme_id = VALUES(programme_id), status = VALUES(status),
                            enrolled_on = VALUES(enrolled_on), updated_at = VALUES(updated_at)',
            [$membershipId, $iid, $number, $programmeId, $status, $enrolledOn, Database::now()]);
    }

    /** @return array<string,int> */
    public function counts(int $iid): array
    {
        return [
            'classes' => (int) $this->db->value("SELECT COUNT(*) FROM institution_classes WHERE institution_id = ? AND status = 'active'", [$iid]),
            'programmes' => (int) $this->db->value('SELECT COUNT(*) FROM institution_programmes WHERE institution_id = ?', [$iid]),
            'departments' => (int) $this->db->value('SELECT COUNT(*) FROM institution_departments WHERE institution_id = ?', [$iid]),
        ];
    }
}
