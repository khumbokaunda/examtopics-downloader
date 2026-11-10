<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use App\Repositories\StructureRepository;
use RuntimeException;

/**
 * Course offerings: one class being taught something between two dates. A course does not keep
 * its own list of learners or teachers. Its learners are the students of its class
 * (class_enrolments); its teachers are the class's instructors, plus a lead instructor when one
 * is named. Assessments are linked to a course (assessments.course_id) and keep everything else
 * about them, so an assessment without a course works as before.
 *
 * Who may do what:
 *  - See a course: people who see every class (classes.view), its teachers, and its learners.
 *  - Change it, link assessments and post notices: people who manage classes (classes.manage)
 *    and its teachers. A teacher can create a course only for a class they teach.
 */
final class Courses
{
    public const STATUSES = ['active' => 'Active', 'archived' => 'Archived'];

    public function __construct(private Database $db, private StructureRepository $structure, private AuditLog $audit, private Notifier $notifier)
    {
    }

    // Reading --------------------------------------------------------------------------------

    private const SELECT = "SELECT c.*, k.uuid AS class_uuid, k.name AS class_name, k.intake AS class_intake, k.status AS class_status,
            pm.uuid AS module_uuid, pm.name AS module_name, pm.code AS module_code,
            lm.uuid AS lead_uuid, COALESCE(NULLIF(lu.display_name, ''), lu.username) AS lead_name,
            (SELECT COUNT(*) FROM class_enrolments e WHERE e.class_id = c.class_id AND e.status = 'active') AS learner_count,
            (SELECT COUNT(*) FROM assessments a WHERE a.course_id = c.id AND a.status <> 'archived') AS assessment_count
        FROM courses c JOIN institution_classes k ON k.id = c.class_id
        LEFT JOIN programme_modules pm ON pm.id = c.module_id
        LEFT JOIN institution_memberships lm ON lm.id = c.lead_membership_id LEFT JOIN users lu ON lu.id = lm.user_id";

    /** @return array<string,mixed>|null */
    public function find(int $iid, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one(self::SELECT . ' WHERE c.institution_id = ? AND c.uuid = ?', [$iid, $uuid]) : null;
    }

    /**
     * The courses of an institution this member may see: all of them with classes.view, else
     * the ones they teach or learn in.
     *
     * @param array<string,mixed> $m @return list<array<string,mixed>>
     */
    public function visibleTo(int $iid, array $m, string $status = 'active'): array
    {
        $sql = self::SELECT . ' WHERE c.institution_id = ? AND c.status = ?';
        $args = [$iid, $status];
        if (!InstitutionAccess::can($m, 'classes.view')) {
            $sql .= " AND (c.lead_membership_id = ? OR EXISTS (SELECT 1 FROM class_instructors t WHERE t.class_id = c.class_id AND t.membership_id = ?)
                       OR EXISTS (SELECT 1 FROM class_enrolments e WHERE e.class_id = c.class_id AND e.membership_id = ? AND e.status = 'active'))";
            array_push($args, (int) $m['id'], (int) $m['id'], (int) $m['id']);
        }
        return $this->db->all($sql . ' ORDER BY c.starts_on IS NULL, c.starts_on DESC, c.title', $args);
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $c */
    public function teaches(array $m, array $c): bool
    {
        return (int) ($c['lead_membership_id'] ?? 0) === (int) $m['id'] || $this->structure->teaches((int) $c['institution_id'], (int) $c['class_id'], (int) $m['id']);
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $c */
    public function learns(array $m, array $c): bool
    {
        return $m['role'] === InstitutionAccess::STUDENT && $this->structure->activeEnrolment((int) $c['institution_id'], (int) $c['class_id'], (int) $m['id']) !== null;
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $c */
    public function canSee(array $m, array $c): bool
    {
        return InstitutionAccess::can($m, 'classes.view') || $this->canManage($m, $c) || $this->learns($m, $c);
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $c */
    public function canManage(array $m, array $c): bool
    {
        return InstitutionAccess::can($m, 'classes.manage')
            || (in_array($m['role'], InstitutionStructure::TEACHING_ROLES, true) && $this->teaches($m, $c));
    }

    /** Whether the member may start a course at all (for some class). @param array<string,mixed> $m */
    public function canCreate(array $m): bool
    {
        return InstitutionAccess::can($m, 'classes.manage') || in_array($m['role'], InstitutionStructure::TEACHING_ROLES, true);
    }

    /** The classes this member may start a course for. @param array<string,mixed> $m @return list<array<string,mixed>> */
    public function classesFor(int $iid, array $m): array
    {
        return $this->structure->classes($iid, 'active', InstitutionAccess::can($m, 'classes.manage') ? null : (int) $m['id']);
    }

    // Changing -------------------------------------------------------------------------------

    /**
     * Create a course, or change one.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed>|null $c
     * @param array<string,mixed> $in title, summary, class, module, lead (uuids), starts_on, ends_on (Y-m-d)
     * @return string the course's uuid
     */
    public function save(array $i, array $m, ?array $c, array $in): string
    {
        $iid = (int) $i['id'];
        $title = InstitutionStructure::cleanName((string) ($in['title'] ?? ''), 160);
        $summary = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['summary'] ?? '')));
        if (mb_strlen($summary) > 1000) {
            throw new RuntimeException('The summary has at most 1,000 characters.');
        }
        // The class is chosen when the course is made and stays: its students are the learners.
        if ($c === null) {
            $class = $this->structure->class($iid, (string) ($in['class'] ?? ''));
            if ($class === null || $class['status'] !== 'active') {
                throw new RuntimeException('Choose the class this course is for.');
            }
            if (!InstitutionAccess::can($m, 'classes.manage') && !$this->structure->teaches($iid, (int) $class['id'], (int) $m['id'])) {
                throw new RuntimeException('You can start a course only for a class you teach.');
            }
            $classId = (int) $class['id'];
        } else {
            $classId = (int) $c['class_id'];
        }
        $moduleId = null;
        if ((string) ($in['module'] ?? '') !== '') {
            $module = Ids::isUuid((string) $in['module'])
                ? $this->db->one("SELECT id FROM programme_modules WHERE institution_id = ? AND uuid = ? AND status = 'active'", [$iid, (string) $in['module']]) : null;
            $moduleId = (int) (($module ?? throw new RuntimeException('Choose one of the institution\'s modules, or none.'))['id']);
        }
        $leadId = null;
        if ((string) ($in['lead'] ?? '') !== '') {
            $lead = Ids::isUuid((string) $in['lead'])
                ? $this->db->one("SELECT id, role FROM institution_memberships WHERE institution_id = ? AND uuid = ? AND status = 'active'", [$iid, (string) $in['lead']]) : null;
            if ($lead === null || !in_array($lead['role'], InstitutionStructure::TEACHING_ROLES, true)) {
                throw new RuntimeException('The lead instructor must be one of the institution\'s teaching staff.');
            }
            $leadId = (int) $lead['id'];
        }
        $pass = trim((string) ($in['pass_percent'] ?? ''));
        if ($pass !== '' && (!is_numeric($pass) || (float) $pass < 0 || (float) $pass > 100)) {
            throw new RuntimeException('The pass mark is a percentage from 0 to 100, or empty for none.');
        }
        $pass = $pass !== '' ? number_format((float) $pass, 2, '.', '') : null;
        $starts = self::date((string) ($in['starts_on'] ?? ''), 'start');
        $ends = self::date((string) ($in['ends_on'] ?? ''), 'end');
        if ($starts !== null && $ends !== null && $ends < $starts) {
            throw new RuntimeException('The course ends before it starts.');
        }
        $now = Database::now();
        if ($c === null) {
            $uuid = Ids::uuid();
            $this->db->run('INSERT INTO courses (uuid, institution_id, class_id, module_id, title, summary, starts_on, ends_on, pass_percent, lead_membership_id, created_by, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$uuid, $iid, $classId, $moduleId, $title, $summary !== '' ? $summary : null, $starts, $ends, $pass, $leadId, (int) $m['id'], $now, $now]);
            $this->record('institution.course_created', $i, ['course' => $title]);
            return $uuid;
        }
        $this->db->run('UPDATE courses SET module_id = ?, title = ?, summary = ?, starts_on = ?, ends_on = ?, pass_percent = ?, lead_membership_id = ?, updated_at = ? WHERE id = ? AND institution_id = ?',
            [$moduleId, $title, $summary !== '' ? $summary : null, $starts, $ends, $pass, $leadId, $now, (int) $c['id'], $iid]);
        $this->record('institution.course_changed', $i, ['course' => $title]);
        return (string) $c['uuid'];
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $c */
    public function setArchived(array $i, array $c, bool $archived): void
    {
        $this->db->run('UPDATE courses SET status = ?, updated_at = ? WHERE id = ? AND institution_id = ?',
            [$archived ? 'archived' : 'active', Database::now(), (int) $c['id'], (int) $i['id']]);
        $this->record($archived ? 'institution.course_archived' : 'institution.course_restored', $i, ['course' => $c['title']]);
    }

    // Assessments ----------------------------------------------------------------------------

    /** @return list<array<string,mixed>> the course's assessments, soonest first */
    public function assessments(int $iid, int $courseId): array
    {
        return $this->db->all("SELECT a.* FROM assessments a WHERE a.institution_id = ? AND a.course_id = ? AND a.status <> 'archived'
                               ORDER BY a.opens_at IS NULL, a.opens_at, a.title", [$iid, $courseId]);
    }

    /** Put an assessment in the course, or take it out ($c null). @param array<string,mixed> $i @param array<string,mixed>|null $c @param array<string,mixed> $a */
    public function link(array $i, ?array $c, array $a): void
    {
        $this->db->run('UPDATE assessments SET course_id = ?, updated_at = ? WHERE id = ? AND institution_id = ?',
            [$c !== null ? (int) $c['id'] : null, Database::now(), (int) $a['id'], (int) $i['id']]);
        $this->record($c !== null ? 'institution.course_assessment_added' : 'institution.course_assessment_removed', $i,
            ['assessment' => $a['title']] + ($c !== null ? ['course' => $c['title']] : []));
    }

    // Notices --------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> newest first */
    public function announcements(int $iid, int $courseId, int $limit = 50): array
    {
        return $this->db->all("SELECT n.*, COALESCE(NULLIF(u.display_name, ''), u.username) AS author FROM course_announcements n
                               LEFT JOIN institution_memberships m ON m.id = n.posted_by LEFT JOIN users u ON u.id = m.user_id
                               WHERE n.institution_id = ? AND n.course_id = ? AND n.removed_at IS NULL ORDER BY n.posted_at DESC, n.id DESC LIMIT " . max(1, $limit), [$iid, $courseId]);
    }

    /**
     * Post a notice and tell the course's learners (their inbox, and email where they take it).
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $c
     * @return int how many learners were told
     */
    public function announce(array $i, array $m, array $c, string $title, string $body): int
    {
        if ($c['status'] !== 'active') {
            throw new RuntimeException('An archived course takes no new notices.');
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if ($title === '' || mb_strlen($title) > 160) {
            throw new RuntimeException('Give the notice a title of up to 160 characters.');
        }
        if ($body === '' || mb_strlen($body) > 5000) {
            throw new RuntimeException('Write the notice (up to 5,000 characters).');
        }
        $uuid = Ids::uuid();
        $this->db->run('INSERT INTO course_announcements (uuid, institution_id, course_id, title, body, posted_by, posted_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$uuid, (int) $i['id'], (int) $c['id'], $title, $body, (int) $m['id'], Database::now()]);
        $this->record('institution.course_notice_posted', $i, ['course' => $c['title'], 'title' => $title]);
        $told = 0;
        foreach ($this->learners((int) $i['id'], (int) $c['class_id']) as $learner) {
            $this->notifier->notify($learner, 'institution', $c['title'] . ': ' . $title, mb_substr($body, 0, 300),
                '/institutions/' . $i['uuid'] . '/courses/' . $c['uuid'], null, 'course-notice:' . $uuid);
            $told++;
        }
        return $told;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $c */
    public function removeAnnouncement(array $i, array $c, string $uuid): void
    {
        $n = Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM course_announcements WHERE institution_id = ? AND course_id = ? AND uuid = ? AND removed_at IS NULL',
            [(int) $i['id'], (int) $c['id'], $uuid]) : null;
        if ($n === null) {
            throw new RuntimeException('That notice is not there any more.');
        }
        $this->db->run('UPDATE course_announcements SET removed_at = ? WHERE id = ?', [Database::now(), (int) $n['id']]);
        $this->record('institution.course_notice_removed', $i, ['course' => $c['title'], 'title' => $n['title']]);
    }

    /** The accounts of a class's active students. @return list<array<string,mixed>> */
    private function learners(int $iid, int $classId): array
    {
        return $this->db->all("SELECT u.* FROM class_enrolments e JOIN institution_memberships m ON m.id = e.membership_id AND m.status = 'active'
                               JOIN users u ON u.id = m.user_id AND u.status = 'active'
                               WHERE e.institution_id = ? AND e.class_id = ? AND e.status = 'active'", [$iid, $classId]);
    }

    // For home -------------------------------------------------------------------------------

    /**
     * A person's active courses across their institutions (as teacher or learner), each with its
     * latest notice.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->db->all("SELECT c.uuid, c.title, c.starts_on, c.ends_on, i.uuid AS institution_uuid, i.name AS institution_name, i.short_name AS institution_short,
                    k.name AS class_name, m.role AS member_role,
                    (SELECT n.title FROM course_announcements n WHERE n.course_id = c.id AND n.removed_at IS NULL ORDER BY n.posted_at DESC, n.id DESC LIMIT 1) AS notice_title,
                    (SELECT n.posted_at FROM course_announcements n WHERE n.course_id = c.id AND n.removed_at IS NULL ORDER BY n.posted_at DESC, n.id DESC LIMIT 1) AS notice_at
                FROM institution_memberships m
                JOIN institutions i ON i.id = m.institution_id AND i.status = 'verified' AND i.deleted_at IS NULL
                JOIN courses c ON c.institution_id = m.institution_id AND c.status = 'active'
                JOIN institution_classes k ON k.id = c.class_id
                WHERE m.user_id = ? AND m.status = 'active'
                  AND (c.lead_membership_id = m.id OR EXISTS (SELECT 1 FROM class_instructors t WHERE t.class_id = c.class_id AND t.membership_id = m.id)
                       OR (m.role = 'student' AND EXISTS (SELECT 1 FROM class_enrolments e WHERE e.class_id = c.class_id AND e.membership_id = m.id AND e.status = 'active')))
                ORDER BY c.starts_on IS NULL, c.starts_on DESC, c.title LIMIT 50", [$userId]);
    }

    /**
     * What is coming up for a person, soonest first, from dates that already exist: when the
     * assessments set for them open and close, when the assessments of courses they teach open
     * and close, and when their courses start and end.
     *
     * @param list<array<string,mixed>> $assigned from SittingRepository::assignedTo()
     * @return list<array{at:string,date_only:bool,label:string,title:string,where:string,href:string,icon:string}>
     */
    public function upcoming(int $userId, array $assigned, int $limit = 8): array
    {
        $now = Database::now();
        $today = substr($now, 0, 10);
        $items = [];
        foreach ($assigned as $a) {
            $href = '/my/assessments/' . $a['institution_uuid'] . '/' . $a['uuid'];
            $where = (string) ($a['institution_short'] ?: $a['institution_name']);
            if ($a['opens_at'] !== null && $a['opens_at'] > $now) {
                $items[] = ['at' => (string) $a['opens_at'], 'date_only' => false, 'label' => 'Opens', 'title' => (string) $a['title'], 'where' => $where, 'href' => $href, 'icon' => 'unlock'];
            }
            if ($a['closes_at'] !== null && $a['closes_at'] > $now && $a['status'] === 'published') {
                $items[] = ['at' => (string) $a['closes_at'], 'date_only' => false, 'label' => 'Closes', 'title' => (string) $a['title'], 'where' => $where, 'href' => $href, 'icon' => 'hourglass-split'];
            }
        }
        foreach ($this->forUser($userId) as $c) {
            $href = '/institutions/' . $c['institution_uuid'] . '/courses/' . $c['uuid'];
            $where = (string) ($c['institution_short'] ?: $c['institution_name']);
            if ($c['starts_on'] !== null && $c['starts_on'] > $today) {
                $items[] = ['at' => $c['starts_on'] . ' 00:00:00', 'date_only' => true, 'label' => 'Starts', 'title' => (string) $c['title'], 'where' => $where, 'href' => $href, 'icon' => 'flag'];
            }
            if ($c['ends_on'] !== null && $c['ends_on'] >= $today) {
                $items[] = ['at' => $c['ends_on'] . ' 23:59:59', 'date_only' => true, 'label' => 'Ends', 'title' => (string) $c['title'], 'where' => $where, 'href' => $href, 'icon' => 'flag-fill'];
            }
        }
        // Assessments of the courses this person teaches.
        foreach ($this->db->all("SELECT a.uuid, a.title, a.opens_at, a.closes_at, i.uuid AS institution_uuid, i.name AS institution_name, i.short_name AS institution_short
                FROM institution_memberships m JOIN institutions i ON i.id = m.institution_id AND i.status = 'verified' AND i.deleted_at IS NULL
                JOIN courses c ON c.institution_id = m.institution_id AND c.status = 'active'
                JOIN assessments a ON a.course_id = c.id AND a.status = 'published'
                WHERE m.user_id = ? AND m.status = 'active' AND m.role <> 'student'
                  AND (c.lead_membership_id = m.id OR EXISTS (SELECT 1 FROM class_instructors t WHERE t.class_id = c.class_id AND t.membership_id = m.id))
                LIMIT 100", [$userId]) as $a) {
            $href = '/institutions/' . $a['institution_uuid'] . '/assessments/' . $a['uuid'];
            $where = (string) ($a['institution_short'] ?: $a['institution_name']);
            if ($a['opens_at'] !== null && $a['opens_at'] > $now) {
                $items[] = ['at' => (string) $a['opens_at'], 'date_only' => false, 'label' => 'Opens', 'title' => (string) $a['title'], 'where' => $where, 'href' => $href, 'icon' => 'unlock'];
            }
            if ($a['closes_at'] !== null && $a['closes_at'] > $now) {
                $items[] = ['at' => (string) $a['closes_at'], 'date_only' => false, 'label' => 'Closes', 'title' => (string) $a['title'], 'where' => $where, 'href' => $href, 'icon' => 'hourglass-split'];
            }
        }
        usort($items, static fn(array $x, array $y): int => strcmp($x['at'], $y['at']));
        return array_slice($items, 0, $limit);
    }

    private static function date(string $value, string $which): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw new RuntimeException('Give the ' . $which . ' date as a date, or leave it empty.');
        }
        return $value;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $details */
    private function record(string $action, array $i, array $details = []): void
    {
        $this->audit->record($action, ['institution' => (string) $i['name']] + $details, 'institution', (string) $i['uuid']);
    }
}
