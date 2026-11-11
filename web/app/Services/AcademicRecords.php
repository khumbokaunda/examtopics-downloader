<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use RuntimeException;

/**
 * Programmes in detail (description, duration, objectives, completion requirements, modules),
 * a student's academic record with an institution, and the transcripts it issues.
 *
 * - A module is completed by passing an assessment linked to it once the assessment's results are
 *   published, or by staff recording it. Completions are written once and kept, so changing a
 *   programme or relinking an assessment never rewrites what a student completed.
 * - The record separates final results (published) from provisional ones (handed in, results not
 *   yet published). Students see only final results; staff see both, marked.
 * - A transcript is a snapshot of the record when it was issued. It is never regenerated: a
 *   corrected record means issuing a new transcript and, if needed, withdrawing the old one.
 */
final class AcademicRecords
{
    public const OUTCOMES = ['active' => 'Current', 'completed' => 'Completed', 'withdrawn' => 'Withdrawn', 'changed' => 'Moved to another programme'];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    // Programmes and modules -----------------------------------------------------------------

    /** @param array<string,mixed> $i @param array<string,mixed> $p @param array<string,string> $in */
    public function saveDetails(array $i, array $p, array $in): void
    {
        $f = [];
        foreach (['description' => 2000, 'duration' => 60, 'objectives' => 2000, 'completion_requirements' => 1000] as $k => $max) {
            $v = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in[$k] ?? '')));
            if (mb_strlen($v) > $max) {
                throw new RuntimeException('At most ' . $max . ' characters for the ' . str_replace('_', ' ', $k) . '.');
            }
            $f[$k] = $v !== '' ? $v : null;
        }
        $status = (string) ($in['status'] ?? 'active');
        if (!in_array($status, ['active', 'retired'], true)) {
            throw new RuntimeException('Choose whether the programme is offered.');
        }
        $this->db->run('UPDATE institution_programmes SET description = ?, duration = ?, objectives = ?, completion_requirements = ?, status = ?, updated_at = ?
                        WHERE id = ? AND institution_id = ?', [$f['description'], $f['duration'], $f['objectives'], $f['completion_requirements'], $status, Database::now(),
            (int) $p['id'], (int) $i['id']]);
        $this->audit->record('institution.programme_details_saved', ['institution' => $i['name'], 'programme' => $p['name']], 'institution', (string) $i['uuid']);
    }

    /** @return list<array<string,mixed>> the programme's modules in order, with how many assessments each has */
    public function modules(int $iid, int $programmeId, bool $withRetired = true): array
    {
        return $this->db->all('SELECT m.*, (SELECT COUNT(*) FROM assessments a WHERE a.module_id = m.id) AS assessment_count FROM programme_modules m
                               WHERE m.institution_id = ? AND m.programme_id = ?' . ($withRetired ? '' : " AND m.status = 'active'") . ' ORDER BY m.position, m.id', [$iid, $programmeId]);
    }

    /** @return list<array<string,mixed>> every active module of the institution, with its programme, for choosing */
    public function allModules(int $iid): array
    {
        return $this->db->all("SELECT m.id, m.uuid, m.code, m.name, p.name AS programme_name FROM programme_modules m JOIN institution_programmes p ON p.id = m.programme_id
                               WHERE m.institution_id = ? AND m.status = 'active' ORDER BY p.name, m.position, m.id", [$iid]);
    }

    /** @return array<string,mixed>|null */
    public function module(int $iid, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM programme_modules WHERE institution_id = ? AND uuid = ?', [$iid, $uuid]) : null;
    }

    /** Add a module, or change one. @param array<string,mixed> $i @param array<string,mixed> $p @param array<string,mixed>|null $m */
    public function saveModule(array $i, array $p, ?array $m, string $name, string $code, string $description, bool $required): void
    {
        $name = InstitutionStructure::cleanName($name, 160);
        $code = mb_substr(trim($code), 0, 32);
        $description = mb_substr(trim($description), 0, 1000);
        if ($name === '') {
            throw new RuntimeException('Give the module a name.');
        }
        $now = Database::now();
        if ($m === null) {
            $pos = (int) $this->db->value('SELECT COALESCE(MAX(position), 0) + 1 FROM programme_modules WHERE programme_id = ?', [(int) $p['id']]);
            $this->db->run('INSERT INTO programme_modules (uuid, institution_id, programme_id, code, name, description, position, required, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ids::uuid(), (int) $i['id'], (int) $p['id'], $code !== '' ? $code : null, $name, $description !== '' ? $description : null, $pos, $required ? 1 : 0, $now, $now]);
        } else {
            $this->db->run('UPDATE programme_modules SET code = ?, name = ?, description = ?, required = ?, updated_at = ? WHERE id = ? AND institution_id = ?',
                [$code !== '' ? $code : null, $name, $description !== '' ? $description : null, $required ? 1 : 0, $now, (int) $m['id'], (int) $i['id']]);
        }
        $this->audit->record('institution.module_saved', ['institution' => $i['name'], 'programme' => $p['name'], 'module' => $name], 'institution', (string) $i['uuid']);
    }

    /** Retire a module (or offer it again). Completions of it are kept. @param array<string,mixed> $i @param array<string,mixed> $m */
    public function setRetired(array $i, array $m, bool $retired): void
    {
        $this->db->run('UPDATE programme_modules SET status = ?, updated_at = ? WHERE id = ? AND institution_id = ?', [$retired ? 'retired' : 'active', Database::now(), (int) $m['id'], (int) $i['id']]);
        $this->audit->record($retired ? 'institution.module_retired' : 'institution.module_restored', ['institution' => $i['name'], 'module' => $m['name']], 'institution', (string) $i['uuid']);
    }

    /** Link an assessment to a module of this institution, or unlink it. @param array<string,mixed> $i @param array<string,mixed> $a */
    public function linkAssessment(array $i, array $a, string $moduleUuid): void
    {
        $m = $moduleUuid !== '' ? ($this->module((int) $i['id'], $moduleUuid) ?? throw new RuntimeException('Choose one of the institution\'s modules.')) : null;
        $this->db->run('UPDATE assessments SET module_id = ? WHERE id = ? AND institution_id = ?', [$m !== null ? (int) $m['id'] : null, (int) $a['id'], (int) $i['id']]);
    }

    // Completions ----------------------------------------------------------------------------

    /**
     * Record the modules a student completed by passing an assessment whose results are
     * published. Existing completions are left as they are.
     *
     * A module taught through a course with a pass mark is not completed this way: there the
     * final course grade decides (Gradebook::completeModule), so passing one quiz of the course
     * does not complete the module.
     */
    public function syncCompletions(int $iid, int $membershipId): void
    {
        $this->db->run("INSERT IGNORE INTO module_completions (institution_id, membership_id, module_id, session_id, percent, method, completed_at)
                        SELECT s.institution_id, s.membership_id, a.module_id, s.id, s.percent, 'assessment', s.submitted_at
                          FROM assessment_sessions s JOIN assessments a ON a.id = s.assessment_id
                         WHERE s.institution_id = ? AND s.membership_id = ? AND a.module_id IS NOT NULL AND a.results_published_at IS NOT NULL
                           AND s.passed = 1 AND s.status <> 'in_progress'
                           AND NOT EXISTS (SELECT 1 FROM courses c WHERE c.module_id = a.module_id AND c.institution_id = s.institution_id AND c.pass_percent IS NOT NULL)
                         ORDER BY s.submitted_at", [$iid, $membershipId]);
    }

    /** Staff record a module as completed (for example, assessed outside the platform). @param array<string,mixed> $i @param array<string,mixed> $student @param array<string,mixed> $user */
    public function recordCompletion(array $i, array $student, string $moduleUuid, array $user): void
    {
        $m = $this->module((int) $i['id'], $moduleUuid) ?? throw new RuntimeException('Choose one of the institution\'s modules.');
        $this->db->run("INSERT IGNORE INTO module_completions (institution_id, membership_id, module_id, method, recorded_by, completed_at) VALUES (?, ?, ?, 'manual', ?, ?)",
            [(int) $i['id'], (int) $student['id'], (int) $m['id'], (int) $user['id'], Database::now()]);
        $this->audit->record('institution.module_completion_recorded', ['institution' => $i['name'], 'student' => $student['username'], 'module' => $m['name']],
            'institution', (string) $i['uuid']);
    }

    // The record -----------------------------------------------------------------------------

    /**
     * A student's academic record with the institution.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $student a membership with the account's names
     * @return array<string,mixed>
     */
    public function record(array $i, array $student, bool $includeProvisional): array
    {
        $iid = (int) $i['id'];
        $mid = (int) $student['id'];
        $this->syncCompletions($iid, $mid);
        $programmes = $this->db->all('SELECT e.*, p.name, p.code, p.uuid AS programme_uuid FROM programme_enrolments e JOIN institution_programmes p ON p.id = e.programme_id
                                      WHERE e.institution_id = ? AND e.membership_id = ? ORDER BY e.started_on, e.id', [$iid, $mid]);
        $completed = [];
        foreach ($this->db->all('SELECT * FROM module_completions WHERE institution_id = ? AND membership_id = ?', [$iid, $mid]) as $c) {
            $completed[(int) $c['module_id']] = $c;
        }
        foreach ($programmes as $k => $p) {
            $mods = [];
            foreach ($this->modules($iid, (int) $p['programme_id']) as $m) {
                $c = $completed[(int) $m['id']] ?? null;
                if ($m['status'] === 'retired' && $c === null) {
                    continue;
                }
                $mods[] = ['name' => $m['name'], 'code' => $m['code'], 'required' => (int) $m['required'] === 1, 'uuid' => $m['uuid'],
                    'completed_at' => $c['completed_at'] ?? null, 'percent' => $c['percent'] ?? null, 'method' => $c['method'] ?? null];
            }
            $required = array_filter($mods, static fn(array $m): bool => $m['required']);
            $programmes[$k]['modules'] = $mods;
            $programmes[$k]['eligible'] = $p['outcome'] === 'active' && $required !== [] && array_filter($required, static fn(array $m): bool => $m['completed_at'] === null) === [];
        }
        $sittings = $this->db->all("SELECT s.uuid, s.attempt, s.status, s.percent, s.passed, s.submitted_at, a.title, a.type, a.results_published_at, m.name AS module_name
                                    FROM assessment_sessions s JOIN assessments a ON a.id = s.assessment_id LEFT JOIN programme_modules m ON m.id = a.module_id
                                    WHERE s.institution_id = ? AND s.membership_id = ? AND s.status <> 'in_progress'
                                    ORDER BY s.submitted_at", [$iid, $mid]);
        $results = [];
        foreach ($sittings as $s) {
            $final = $s['results_published_at'] !== null;
            if (!$final && !$includeProvisional) {
                continue;
            }
            $results[] = ['title' => $s['title'], 'module' => $s['module_name'], 'attempt' => (int) $s['attempt'], 'date' => $s['submitted_at'],
                'percent' => $s['percent'] !== null ? (float) $s['percent'] : null, 'passed' => $s['passed'] === null ? null : (int) $s['passed'] === 1,
                'final' => $final, 'uuid' => $s['uuid']];
        }
        $rec = $this->db->one('SELECT s.*, p.name AS programme_name FROM institution_students s LEFT JOIN institution_programmes p ON p.id = s.programme_id WHERE s.membership_id = ?', [$mid]);
        return [
            'institution' => ['name' => $i['name'], 'uuid' => $i['uuid']],
            'student' => ['name' => Access::displayName($student), 'number' => $rec['student_number'] ?? null, 'standing' => $rec['status'] ?? 'active',
                'enrolled_on' => $rec['enrolled_on'] ?? null, 'joined_at' => $student['joined_at'] ?? null],
            'programmes' => array_map(static fn(array $p): array => ['name' => $p['name'], 'code' => $p['code'], 'started_on' => $p['started_on'], 'ended_on' => $p['ended_on'],
                'outcome' => $p['outcome'], 'eligible' => $p['eligible'], 'modules' => $p['modules'], 'uuid' => $p['programme_uuid']], $programmes),
            'results' => $results,
            'provisional' => $includeProvisional,
        ];
    }

    /** Mark the student's current programme completed (and their standing completed). @param array<string,mixed> $i @param array<string,mixed> $student @param array<string,mixed> $user */
    public function completeProgramme(array $i, array $student, array $user, bool $override): void
    {
        $iid = (int) $i['id'];
        $rec = $this->record($i, $student, false);
        $current = null;
        foreach ($rec['programmes'] as $p) {
            if ($p['outcome'] === 'active') {
                $current = $p;
            }
        }
        if ($current === null) {
            throw new RuntimeException('The student is not on a programme.');
        }
        if (!$current['eligible'] && !$override) {
            throw new RuntimeException('Not every required module of ' . $current['name'] . ' is completed. Tick the box to record completion anyway.');
        }
        $this->db->run("UPDATE institution_students SET status = 'completed', updated_at = ? WHERE membership_id = ? AND institution_id = ?", [Database::now(), (int) $student['id'], $iid]);
        $this->db->run("UPDATE programme_enrolments SET outcome = 'completed', ended_on = ?, recorded_by = ?, updated_at = ? WHERE institution_id = ? AND membership_id = ? AND outcome = 'active'",
            [gmdate('Y-m-d'), (int) $user['id'], Database::now(), $iid, (int) $student['id']]);
        $this->audit->record('institution.programme_completed', ['institution' => $i['name'], 'student' => $student['username'], 'programme' => $current['name'],
            'override' => !$current['eligible']], 'institution', (string) $i['uuid']);
    }

    // Transcripts ----------------------------------------------------------------------------

    /** @param array<string,mixed> $i @param array<string,mixed> $student @param array<string,mixed> $user @return string the transcript's uuid */
    public function issueTranscript(array $i, array $student, array $user, bool $includeProvisional): string
    {
        $snapshot = $this->record($i, $student, $includeProvisional);
        $uuid = Ids::uuid();
        $this->db->transaction(function (Database $db) use ($uuid, $i, $student, $snapshot, $includeProvisional, $user): void {
            $db->run('INSERT INTO transcripts (uuid, institution_id, membership_id, snapshot, includes_provisional, issued_by, issued_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$uuid, (int) $i['id'], (int) $student['id'], json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $includeProvisional ? 1 : 0,
                    (int) $user['id'], Database::now()]);
            $id = $db->lastId();
            $db->run('UPDATE transcripts SET number = ? WHERE id = ?', [sprintf('TR-%s-%06d', gmdate('Y'), $id), $id]);
        });
        $this->audit->record('institution.transcript_issued', ['institution' => $i['name'], 'student' => $student['username'], 'provisional' => $includeProvisional],
            'institution', (string) $i['uuid']);
        return $uuid;
    }

    /** @return list<array<string,mixed>> a student's transcripts, newest first */
    public function transcripts(int $iid, int $membershipId): array
    {
        return $this->db->all('SELECT t.id, t.uuid, t.number, t.includes_provisional, t.status, t.issued_at, t.withdrawn_at, t.withdrawn_reason, u.username, u.display_name
                               FROM transcripts t LEFT JOIN users u ON u.id = t.issued_by WHERE t.institution_id = ? AND t.membership_id = ? ORDER BY t.id DESC', [$iid, $membershipId]);
    }

    /** @return array<string,mixed>|null with its snapshot decoded */
    public function transcript(int $iid, string $uuid): ?array
    {
        $t = Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM transcripts WHERE institution_id = ? AND uuid = ?', [$iid, $uuid]) : null;
        if ($t !== null) {
            $t['record'] = (array) json_decode((string) $t['snapshot'], true);
        }
        return $t;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $t */
    public function withdrawTranscript(array $i, array $t, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($t['status'] !== 'issued') {
            throw new RuntimeException('That transcript has been withdrawn already.');
        }
        if ($reason === '') {
            throw new RuntimeException('Say why it is withdrawn. The reason is kept with it.');
        }
        $this->db->run("UPDATE transcripts SET status = 'withdrawn', withdrawn_at = ?, withdrawn_reason = ? WHERE id = ?", [Database::now(), $reason, (int) $t['id']]);
        $this->audit->record('institution.transcript_withdrawn', ['institution' => $i['name'], 'number' => $t['number']], 'institution', (string) $i['uuid']);
    }

    // Students under 18 ----------------------------------------------------------------------

    /** @param array<string,mixed> $i @param array<string,mixed> $student @param array<string,mixed> $user */
    public function saveGuardian(array $i, array $student, string $dob, string $name, string $contact, bool $consent, array $user): void
    {
        $dob = trim($dob);
        if ($dob !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) !== 1 || !checkdate((int) substr($dob, 5, 2), (int) substr($dob, 8, 2), (int) substr($dob, 0, 4)) || $dob > gmdate('Y-m-d'))) {
            throw new RuntimeException('Give the date of birth as a date in the past.');
        }
        $name = mb_substr(trim($name), 0, 120);
        $contact = mb_substr(trim($contact), 0, 190);
        $this->db->run('INSERT INTO institution_students (membership_id, institution_id, status, updated_at) VALUES (?, ?, \'active\', ?) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
            [(int) $student['id'], (int) $i['id'], Database::now()]);
        $this->db->run('UPDATE institution_students SET date_of_birth = ?, guardian_name = ?, guardian_contact = ?,
                               guardian_consent_at = IF(?, COALESCE(guardian_consent_at, ?), NULL), guardian_consent_by = IF(?, COALESCE(guardian_consent_by, ?), NULL)
                        WHERE membership_id = ? AND institution_id = ?',
            [$dob !== '' ? $dob : null, $name !== '' ? $name : null, $contact !== '' ? $contact : null, $consent ? 1 : 0, Database::now(), $consent ? 1 : 0, (int) $user['id'],
                (int) $student['id'], (int) $i['id']]);
        $this->audit->record('institution.guardian_saved', ['institution' => $i['name'], 'student' => $student['username'], 'consent' => $consent], 'institution', (string) $i['uuid']);
    }

    /** Whether a date of birth makes the student under 18 today. */
    public static function minor(?string $dob): bool
    {
        return $dob !== null && $dob !== '' && $dob > gmdate('Y-m-d', strtotime('-18 years'));
    }
}
