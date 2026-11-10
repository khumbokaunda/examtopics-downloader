<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use RuntimeException;

/**
 * A course's materials (files and links under headings) and its assignments: instructions, a
 * deadline with a late rule, the kinds of file accepted, learners' submissions, and marking
 * with an optional rubric.
 *
 * Files are kept outside the web root, under storage/institution_documents/<institution>/
 * coursework/ (backups include it), named by a random id, and accepted by what their content
 * is, not by their name. A learner may submit again until the deadline (or after it, marked
 * late, when late work is accepted); each submission supersedes the one before, which is kept.
 * Marks are saved as drafts and shown to learners only when the assignment's marks are
 * released; a changed mark supersedes the one before.
 */
final class Coursework
{
    /** What an assignment can accept, and what each kind of file may be. */
    public const KINDS = [
        'pdf' => ['label' => 'PDF', 'mimes' => ['application/pdf'], 'ext' => ['pdf']],
        'word' => ['label' => 'Word documents', 'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'], 'ext' => ['docx', 'doc']],
        'spreadsheet' => ['label' => 'Spreadsheets', 'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv'], 'ext' => ['xlsx', 'xls', 'csv']],
        'slides' => ['label' => 'Presentations', 'mimes' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.ms-powerpoint'], 'ext' => ['pptx', 'ppt']],
        'image' => ['label' => 'Pictures (JPEG, PNG)', 'mimes' => ['image/jpeg', 'image/png'], 'ext' => ['jpg', 'jpeg', 'png']],
        'text' => ['label' => 'Plain text', 'mimes' => ['text/plain'], 'ext' => ['txt']],
        'zip' => ['label' => 'ZIP archives', 'mimes' => ['application/zip'], 'ext' => ['zip']],
    ];
    /** What a material file may be: any kind above. */
    public const MATERIAL_MB = 20;
    public const STATUSES = ['draft' => 'Draft', 'published' => 'Open', 'closed' => 'Closed'];
    public const LATE = ['accept' => 'Accept it, marked late', 'refuse' => 'Refuse it'];

    public function __construct(private Database $db, private AuditLog $audit, private Notifier $notifier, private string $storage)
    {
    }

    // Files ----------------------------------------------------------------------------------

    /** @param array<string,mixed> $i */
    public function path(array $i, string $uuid): string
    {
        return $this->storage . '/institution_documents/' . $i['uuid'] . '/coursework/' . $uuid;
    }

    /**
     * Check an upload against the kinds allowed, and store it.
     *
     * @param array<string,mixed> $i @param array{name:string,tmp_name:string,size:int,error:int} $file @param list<string> $kinds
     * @return array{uuid:string,filename:string,mime:string,size:int,sha256:string}
     */
    private function store(array $i, array $file, array $kinds, int $maxMb): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK || !is_file($file['tmp_name'])) {
            throw new RuntimeException(in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'A file is too large.' : 'Choose a file to upload.');
        }
        $size = (int) filesize($file['tmp_name']);
        if ($size < 1 || $size > $maxMb * 1048576) {
            throw new RuntimeException('A file can be at most ' . $maxMb . ' MB.');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ok = false;
        foreach ($kinds as $k) {
            $kind = self::KINDS[$k] ?? null;
            if ($kind === null || !in_array($ext, $kind['ext'], true)) {
                continue;
            }
            // Office files are zip archives inside, and some systems report them so.
            if (in_array($mime, $kind['mimes'], true) || (in_array($k, ['word', 'spreadsheet', 'slides'], true) && in_array($mime, ['application/zip', 'application/octet-stream'], true)
                && str_ends_with($ext, 'x')) || ($k === 'spreadsheet' && $ext === 'csv' && $mime === 'text/plain')) {
                $ok = true;
                $mime = $kind['mimes'][array_search($ext, $kind['ext'], true)] ?? $mime;
                break;
            }
        }
        if (!$ok) {
            throw new RuntimeException('"' . mb_substr((string) $file['name'], 0, 80) . '" is not one of the kinds accepted: '
                . implode(', ', array_map(static fn(string $k): string => self::KINDS[$k]['label'] ?? $k, $kinds)) . '.');
        }
        $uuid = Ids::uuid();
        $dest = $this->path($i, $uuid);
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) {
            throw new RuntimeException('The file could not be stored. Tell an administrator.');
        }
        if (!copy($file['tmp_name'], $dest)) {
            throw new RuntimeException('The file could not be stored. Tell an administrator.');
        }
        $name = mb_substr(trim((string) preg_replace('/[^\pL\pN ._()-]+/u', '_', basename((string) $file['name']))), 0, 180);
        return ['uuid' => $uuid, 'filename' => $name !== '' ? $name : 'file.' . $ext, 'mime' => $mime, 'size' => $size, 'sha256' => (string) hash_file('sha256', $dest)];
    }

    // Materials ------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function materials(int $iid, int $courseId): array
    {
        return $this->db->all('SELECT * FROM course_materials WHERE institution_id = ? AND course_id = ? AND removed_at IS NULL ORDER BY heading, position, id', [$iid, $courseId]);
    }

    /** @return array<string,mixed>|null */
    public function material(int $iid, int $courseId, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM course_materials WHERE institution_id = ? AND course_id = ? AND uuid = ? AND removed_at IS NULL', [$iid, $courseId, $uuid]) : null;
    }

    /**
     * Add a file or a link under a heading. Video is a link, never an upload.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $c @param array<string,mixed> $in heading, title, url
     * @param array{name:string,tmp_name:string,size:int,error:int}|null $file
     */
    public function addMaterial(array $i, array $m, array $c, array $in, ?array $file): void
    {
        $heading = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['heading'] ?? '')));
        if (mb_strlen($heading) > 120) {
            throw new RuntimeException('A heading has at most 120 characters.');
        }
        $title = InstitutionStructure::cleanName((string) ($in['title'] ?? ''), 160);
        $url = trim((string) ($in['url'] ?? ''));
        $now = Database::now();
        $pos = (int) $this->db->value('SELECT COALESCE(MAX(position), 0) + 1 FROM course_materials WHERE course_id = ?', [(int) $c['id']]);
        if ($file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $this->store($i, $file, array_keys(self::KINDS), self::MATERIAL_MB);
            $this->db->run('INSERT INTO course_materials (uuid, institution_id, course_id, heading, title, kind, filename, mime, size, sha256, position, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, \'file\', ?, ?, ?, ?, ?, ?, ?)',
                [$f['uuid'], (int) $i['id'], (int) $c['id'], $heading, $title, $f['filename'], $f['mime'], $f['size'], $f['sha256'], $pos, (int) $m['id'], $now]);
        } elseif ($url !== '') {
            if (preg_match('#^https?://[^\s/]+\.[^\s]+$#i', $url) !== 1 || mb_strlen($url) > 1000) {
                throw new RuntimeException('Give the full address of the link, starting with https://.');
            }
            $this->db->run('INSERT INTO course_materials (uuid, institution_id, course_id, heading, title, kind, url, position, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, \'link\', ?, ?, ?, ?)', [Ids::uuid(), (int) $i['id'], (int) $c['id'], $heading, $title, $url, $pos, (int) $m['id'], $now]);
        } else {
            throw new RuntimeException('Choose a file, or give a link.');
        }
        $this->record('institution.material_added', $i, ['course' => $c['title'], 'title' => $title]);
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $c @param array<string,mixed> $mat */
    public function removeMaterial(array $i, array $c, array $mat): void
    {
        $this->db->run('UPDATE course_materials SET removed_at = ? WHERE id = ?', [Database::now(), (int) $mat['id']]);
        $this->record('institution.material_removed', $i, ['course' => $c['title'], 'title' => $mat['title']]);
    }

    // Assignments ----------------------------------------------------------------------------

    /** @return list<array<string,mixed>> with how many have handed in and been marked */
    public function assignments(int $iid, int $courseId, bool $publishedOnly = false): array
    {
        return $this->db->all("SELECT a.*,
                (SELECT COUNT(*) FROM assignment_submissions s WHERE s.assignment_id = a.id AND s.superseded_at IS NULL) AS submitted,
                (SELECT COUNT(*) FROM assignment_marks k WHERE k.assignment_id = a.id AND k.superseded_at IS NULL AND k.marks IS NOT NULL) AS marked
            FROM assignments a WHERE a.institution_id = ? AND a.course_id = ?" . ($publishedOnly ? " AND a.status <> 'draft'" : '') . '
            ORDER BY a.due_at IS NULL, a.due_at, a.title', [$iid, $courseId]);
    }

    /** @return array<string,mixed>|null */
    public function assignment(int $iid, int $courseId, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM assignments WHERE institution_id = ? AND course_id = ? AND uuid = ?', [$iid, $courseId, $uuid]) : null;
    }

    /**
     * Create or change an assignment. Times come in the person's timezone and are kept in UTC.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $c @param array<string,mixed>|null $a @param array<string,mixed> $in
     * @return string its uuid
     */
    public function save(array $i, array $m, array $c, ?array $a, array $in, string $timezone): string
    {
        $title = InstitutionStructure::cleanName((string) ($in['title'] ?? ''), 160);
        $instructions = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['instructions'] ?? '')));
        $errors = [];
        if (mb_strlen($instructions) > 10000) {
            $errors[] = 'The instructions have at most 10,000 characters.';
        }
        $due = null;
        if (trim((string) ($in['due_at'] ?? '')) !== '') {
            try {
                $local = new \DateTimeImmutable((string) $in['due_at'], new \DateTimeZone($timezone));
                $due = $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                $errors[] = 'Give the deadline as a date and time.';
            }
        }
        $late = (string) ($in['late_policy'] ?? 'accept');
        if (!isset(self::LATE[$late])) {
            $errors[] = 'Choose what happens to late work.';
        }
        $maxRaw = trim((string) ($in['max_marks'] ?? ''));
        if (!is_numeric($maxRaw) || (float) $maxRaw <= 0 || (float) $maxRaw > 100000) {
            $errors[] = 'The assignment is marked out of a number above 0.';
        }
        $kinds = array_values(array_intersect(array_keys(self::KINDS), array_map('strval', (array) ($in['file_kinds'] ?? []))));
        if ($kinds === []) {
            $errors[] = 'Choose at least one kind of file to accept.';
        }
        $files = (int) ($in['max_files'] ?? 1);
        $mb = (int) ($in['max_mb'] ?? 10);
        if ($files < 1 || $files > 10 || $mb < 1 || $mb > 50) {
            $errors[] = 'Up to 10 files of up to 50 MB each.';
        }
        if ($a !== null && $a['released_at'] === null && $maxRaw !== '' && is_numeric($maxRaw)
            && (int) $this->db->value('SELECT COUNT(*) FROM assignment_marks WHERE assignment_id = ? AND superseded_at IS NULL AND marks > ?', [(int) $a['id'], (float) $maxRaw]) > 0) {
            $errors[] = 'Some marks are above ' . Gradebook::fmt((float) $maxRaw) . '.';
        }
        if ($this->criteria((int) $i['id'], (int) ($a['id'] ?? 0)) !== [] && is_numeric($maxRaw) && abs($this->criteriaTotal((int) $i['id'], (int) $a['id']) - (float) $maxRaw) > 0.001) {
            $errors[] = 'With a rubric, the mark is the sum of its criteria (' . Gradebook::fmt($this->criteriaTotal((int) $i['id'], (int) $a['id'])) . ').';
        }
        if ($errors !== []) {
            throw new RuntimeException('Nothing was saved. ' . implode(' ', $errors));
        }
        $vals = [$title, $instructions !== '' ? $instructions : null, $due, $late, round((float) $maxRaw, 2), implode(',', $kinds), $files, $mb];
        $now = Database::now();
        if ($a === null) {
            $uuid = Ids::uuid();
            $this->db->run('INSERT INTO assignments (uuid, institution_id, course_id, title, instructions, due_at, late_policy, max_marks, file_kinds, max_files, max_mb, created_by, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$uuid, (int) $i['id'], (int) $c['id'], ...$vals, (int) $m['id'], $now, $now]);
            $this->record('institution.assignment_created', $i, ['course' => $c['title'], 'assignment' => $title]);
            return $uuid;
        }
        $this->db->run('UPDATE assignments SET title = ?, instructions = ?, due_at = ?, late_policy = ?, max_marks = ?, file_kinds = ?, max_files = ?, max_mb = ?, updated_at = ? WHERE id = ?',
            [...$vals, $now, (int) $a['id']]);
        $this->record('institution.assignment_changed', $i, ['course' => $c['title'], 'assignment' => $title]);
        return (string) $a['uuid'];
    }

    /** Open it to learners (they are told), close it, or open it again. @param array<string,mixed> $i @param array<string,mixed> $c @param array<string,mixed> $a */
    public function setStatus(array $i, array $c, array $a, string $to): void
    {
        $allowed = ['published' => ['draft', 'closed'], 'closed' => ['published']];
        if (!in_array($a['status'], $allowed[$to] ?? [], true)) {
            throw new RuntimeException('That cannot be done now.');
        }
        $this->db->run('UPDATE assignments SET status = ?, updated_at = ? WHERE id = ?', [$to, Database::now(), (int) $a['id']]);
        $this->record($to === 'published' ? 'institution.assignment_opened' : 'institution.assignment_closed', $i, ['course' => $c['title'], 'assignment' => $a['title']]);
        if ($to === 'published' && $a['status'] === 'draft') {
            $this->tell($i, $c, 'New assignment: ' . $a['title'], $c['title'] . ': ' . $a['title'] . ($a['due_at'] !== null ? ', due ' . substr((string) $a['due_at'], 0, 16) . ' UTC' : '') . '.',
                $this->link($i, $c, $a), 'assignment-open:' . $a['uuid']);
        }
    }

    // Rubric ---------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function criteria(int $iid, int $assignmentId): array
    {
        return $assignmentId === 0 ? [] : $this->db->all('SELECT * FROM assignment_criteria WHERE institution_id = ? AND assignment_id = ? ORDER BY position, id', [$iid, $assignmentId]);
    }

    private function criteriaTotal(int $iid, int $assignmentId): float
    {
        return array_sum(array_map(static fn(array $c): float => (float) $c['max_marks'], $this->criteria($iid, $assignmentId)));
    }

    /**
     * Set the rubric: criteria with marks that add up to the assignment's mark. Only before any
     * marking, so no mark is left against criteria that changed.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $c @param array<string,mixed> $a @param list<array{title:string,description:string,max_marks:string}> $rows
     */
    public function setRubric(array $i, array $c, array $a, array $rows): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM assignment_marks WHERE assignment_id = ?', [(int) $a['id']]) > 0) {
            throw new RuntimeException('Marking has started, so the rubric stays as it is.');
        }
        $clean = [];
        foreach ($rows as $r) {
            $t = trim((string) preg_replace('/\s+/u', ' ', (string) ($r['title'] ?? '')));
            if ($t === '') {
                continue;
            }
            $max = trim((string) ($r['max_marks'] ?? ''));
            if (mb_strlen($t) > 160 || !is_numeric($max) || (float) $max <= 0) {
                throw new RuntimeException('Each criterion has a name (up to 160 characters) and marks above 0.');
            }
            $clean[] = [$t, mb_substr(trim((string) ($r['description'] ?? '')), 0, 500), round((float) $max, 2)];
        }
        if (count($clean) > 20) {
            throw new RuntimeException('A rubric has at most 20 criteria.');
        }
        $this->db->transaction(function (Database $db) use ($i, $a, $clean): void {
            $db->run('DELETE FROM assignment_criteria WHERE institution_id = ? AND assignment_id = ?', [(int) $i['id'], (int) $a['id']]);
            foreach ($clean as $n => [$t, $d, $max]) {
                $db->run('INSERT INTO assignment_criteria (institution_id, assignment_id, title, description, max_marks, position) VALUES (?, ?, ?, ?, ?, ?)',
                    [(int) $i['id'], (int) $a['id'], $t, $d !== '' ? $d : null, $max, $n + 1]);
            }
            if ($clean !== []) {
                $db->run('UPDATE assignments SET max_marks = ?, updated_at = ? WHERE id = ?', [array_sum(array_column($clean, 2)), Database::now(), (int) $a['id']]);
            }
        });
        $this->record('institution.assignment_rubric_set', $i, ['course' => $c['title'], 'assignment' => $a['title'], 'criteria' => count($clean)]);
    }

    // Submissions ----------------------------------------------------------------------------

    /** @return array<string,mixed>|null a learner's current submission, with its files */
    public function submissionOf(int $iid, int $assignmentId, int $membershipId): ?array
    {
        $s = $this->db->one('SELECT * FROM assignment_submissions WHERE institution_id = ? AND assignment_id = ? AND membership_id = ? AND superseded_at IS NULL',
            [$iid, $assignmentId, $membershipId]);
        return $s === null ? null : $s + ['files' => $this->db->all('SELECT * FROM submission_files WHERE submission_id = ? ORDER BY id', [(int) $s['id']])];
    }

    /** @return int how many submissions the learner has made, current or not */
    public function attempts(int $iid, int $assignmentId, int $membershipId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM assignment_submissions WHERE institution_id = ? AND assignment_id = ? AND membership_id = ?', [$iid, $assignmentId, $membershipId]);
    }

    /**
     * Hand in (or hand in again). The server's clock decides whether it is late.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m the learner @param array<string,mixed> $c @param array<string,mixed> $a
     * @param list<array{name:string,tmp_name:string,size:int,error:int}> $files
     */
    public function submit(array $i, array $m, array $c, array $a, array $files, string $note): bool
    {
        if ($a['status'] !== 'published') {
            throw new RuntimeException($a['status'] === 'closed' ? 'This assignment is closed.' : 'This assignment is not open yet.');
        }
        $now = Database::now();
        $late = $a['due_at'] !== null && $now > $a['due_at'];
        if ($late && $a['late_policy'] === 'refuse') {
            throw new RuntimeException('The deadline has passed, and late work is not accepted.');
        }
        if ($a['released_at'] !== null) {
            throw new RuntimeException('The marks are out, so nothing more can be handed in.');
        }
        $files = array_values(array_filter($files, static fn(array $f): bool => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if ($files === []) {
            throw new RuntimeException('Choose the file to hand in.');
        }
        if (count($files) > (int) $a['max_files']) {
            throw new RuntimeException('Hand in at most ' . (int) $a['max_files'] . ' file' . ((int) $a['max_files'] === 1 ? '' : 's') . '.');
        }
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
        if (mb_strlen($note) > 2000) {
            throw new RuntimeException('The note has at most 2,000 characters.');
        }
        $stored = [];
        foreach ($files as $f) {
            $stored[] = $this->store($i, $f, explode(',', (string) $a['file_kinds']), (int) $a['max_mb']);
        }
        $uuid = Ids::uuid();
        $this->db->transaction(function (Database $db) use ($i, $m, $a, $stored, $note, $late, $now, $uuid): void {
            $db->run('UPDATE assignment_submissions SET superseded_at = ? WHERE institution_id = ? AND assignment_id = ? AND membership_id = ? AND superseded_at IS NULL',
                [$now, (int) $i['id'], (int) $a['id'], (int) $m['id']]);
            $db->run('INSERT INTO assignment_submissions (uuid, institution_id, assignment_id, membership_id, note, late, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$uuid, (int) $i['id'], (int) $a['id'], (int) $m['id'], $note !== '' ? $note : null, $late ? 1 : 0, $now]);
            $sid = $db->lastId();
            foreach ($stored as $f) {
                $db->run('INSERT INTO submission_files (uuid, institution_id, submission_id, filename, mime, size, sha256) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$f['uuid'], (int) $i['id'], $sid, $f['filename'], $f['mime'], $f['size'], $f['sha256']]);
            }
        });
        $this->record('institution.assignment_submitted', $i, ['course' => $c['title'], 'assignment' => $a['title'], 'student' => Access::displayName($m), 'late' => $late]);
        return $late;
    }

    /** @return array<string,mixed>|null a submitted file of this assignment, with who handed it in */
    public function file(int $iid, int $assignmentId, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT f.*, s.membership_id FROM submission_files f JOIN assignment_submissions s ON s.id = f.submission_id
            WHERE f.institution_id = ? AND s.assignment_id = ? AND f.uuid = ?', [$iid, $assignmentId, $uuid]) : null;
    }

    // Marking --------------------------------------------------------------------------------

    /**
     * The learners with their current submission and mark, for the marking list.
     *
     * @return list<array<string,mixed>>
     */
    public function roster(int $iid, array $c, array $a): array
    {
        $rows = $this->db->all("SELECT m.id AS membership_id, m.uuid AS membership_uuid, u.username, u.display_name, st.student_number,
                    s.uuid AS submission_uuid, s.submitted_at, s.late, k.marks, k.feedback, k.entered_at AS marked_at
                FROM class_enrolments e JOIN institution_memberships m ON m.id = e.membership_id AND m.status = 'active' JOIN users u ON u.id = m.user_id
                LEFT JOIN institution_students st ON st.membership_id = m.id
                LEFT JOIN assignment_submissions s ON s.assignment_id = ? AND s.membership_id = m.id AND s.superseded_at IS NULL
                LEFT JOIN assignment_marks k ON k.assignment_id = ? AND k.membership_id = m.id AND k.superseded_at IS NULL
                WHERE e.institution_id = ? AND e.class_id = ? AND e.status = 'active' ORDER BY u.display_name, u.username",
            [(int) $a['id'], (int) $a['id'], $iid, (int) $c['class_id']]);
        return $rows;
    }

    /** @return array<string,mixed>|null the current mark, criteria decoded */
    public function markOf(int $iid, int $assignmentId, int $membershipId): ?array
    {
        $k = $this->db->one('SELECT * FROM assignment_marks WHERE institution_id = ? AND assignment_id = ? AND membership_id = ? AND superseded_at IS NULL',
            [$iid, $assignmentId, $membershipId]);
        if ($k !== null) {
            $k['criteria'] = is_array($d = json_decode((string) $k['criteria'], true)) ? $d : [];
        }
        return $k;
    }

    /**
     * Save a learner's mark and feedback (a draft until the marks are released). With a rubric
     * the mark is the sum of its criteria.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m the marker @param array<string,mixed> $c @param array<string,mixed> $a @param array<string,mixed> $learner
     * @param array<string,string> $criteria criterion id => marks
     */
    public function mark(array $i, array $m, array $c, array $a, array $learner, string $marks, array $criteria, string $feedback): void
    {
        if ($c['grades_final_at'] !== null) {
            throw new RuntimeException('The course grades are final. Make them provisional again to change marks.');
        }
        $rubric = $this->criteria((int) $i['id'], (int) $a['id']);
        $byCriterion = [];
        if ($rubric !== []) {
            $total = 0.0;
            $any = false;
            foreach ($rubric as $cr) {
                $raw = trim((string) ($criteria[(string) $cr['id']] ?? ''));
                if ($raw === '') {
                    continue;
                }
                if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > (float) $cr['max_marks']) {
                    throw new RuntimeException($cr['title'] . ': give a mark from 0 to ' . Gradebook::fmt((float) $cr['max_marks']) . '.');
                }
                $byCriterion[(string) $cr['id']] = round((float) $raw, 2);
                $total += (float) $raw;
                $any = true;
            }
            $value = $any ? round($total, 2) : null;
        } else {
            $raw = trim($marks);
            if ($raw !== '' && (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > (float) $a['max_marks'])) {
                throw new RuntimeException('Give a mark from 0 to ' . Gradebook::fmt((float) $a['max_marks']) . '.');
            }
            $value = $raw !== '' ? round((float) $raw, 2) : null;
        }
        $feedback = trim(str_replace(["\r\n", "\r"], "\n", $feedback));
        if (mb_strlen($feedback) > 5000) {
            throw new RuntimeException('Feedback has at most 5,000 characters.');
        }
        $now = Database::now();
        $this->db->transaction(function (Database $db) use ($i, $m, $a, $learner, $value, $byCriterion, $feedback, $now): void {
            $db->run('UPDATE assignment_marks SET superseded_at = ? WHERE institution_id = ? AND assignment_id = ? AND membership_id = ? AND superseded_at IS NULL',
                [$now, (int) $i['id'], (int) $a['id'], (int) $learner['id']]);
            $db->run('INSERT INTO assignment_marks (institution_id, assignment_id, membership_id, marks, criteria, feedback, entered_by, entered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [(int) $i['id'], (int) $a['id'], (int) $learner['id'], $value, $byCriterion !== [] ? json_encode($byCriterion) : null, $feedback !== '' ? $feedback : null, (int) $m['id'], $now]);
        });
        $this->record('institution.assignment_marked', $i, ['course' => $c['title'], 'assignment' => $a['title'], 'student' => Access::displayName($learner)]);
    }

    /** Show the marks and feedback to the learners, and tell them. @param array<string,mixed> $i @param array<string,mixed> $c @param array<string,mixed> $a */
    public function release(array $i, array $c, array $a): int
    {
        if ($a['released_at'] !== null) {
            throw new RuntimeException('The marks are already out.');
        }
        $this->db->run('UPDATE assignments SET released_at = ?, status = ? WHERE id = ?', [Database::now(), $a['status'] === 'draft' ? 'draft' : 'closed', (int) $a['id']]);
        $this->record('institution.assignment_released', $i, ['course' => $c['title'], 'assignment' => $a['title']]);
        return $this->tell($i, $c, 'Marked: ' . $a['title'], 'Your mark and feedback for ' . $a['title'] . ' in ' . $c['title'] . ' are out.', $this->link($i, $c, $a), 'assignment-marks:' . $a['uuid']);
    }

    // For the timeline -------------------------------------------------------------------------

    /** @return list<array<string,mixed>> open assignments due for a learner, with whether they handed in */
    public function dueFor(int $userId): array
    {
        return $this->db->all("SELECT a.uuid, a.title, a.due_at, c.uuid AS course_uuid, c.title AS course_title, i.uuid AS institution_uuid, i.name AS institution_name, i.short_name AS institution_short,
                    (SELECT 1 FROM assignment_submissions s WHERE s.assignment_id = a.id AND s.membership_id = m.id AND s.superseded_at IS NULL) AS handed_in
                FROM institution_memberships m JOIN institutions i ON i.id = m.institution_id AND i.status = 'verified' AND i.deleted_at IS NULL
                JOIN courses c ON c.institution_id = m.institution_id AND c.status = 'active'
                JOIN class_enrolments e ON e.class_id = c.class_id AND e.membership_id = m.id AND e.status = 'active'
                JOIN assignments a ON a.course_id = c.id AND a.status = 'published'
                WHERE m.user_id = ? AND m.status = 'active' AND m.role = 'student' AND a.due_at IS NOT NULL AND a.due_at > ?
                ORDER BY a.due_at LIMIT 50", [$userId, Database::now()]);
    }

    // Helpers --------------------------------------------------------------------------------

    /** @param array<string,mixed> $i @param array<string,mixed> $c @param array<string,mixed> $a */
    private function link(array $i, array $c, array $a): string
    {
        return '/institutions/' . $i['uuid'] . '/courses/' . $c['uuid'] . '/assignments/' . $a['uuid'];
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $c */
    private function tell(array $i, array $c, string $title, string $body, string $link, string $event): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT u.* FROM class_enrolments e JOIN institution_memberships m ON m.id = e.membership_id AND m.status = 'active'
                                 JOIN users u ON u.id = m.user_id AND u.status = 'active' WHERE e.institution_id = ? AND e.class_id = ? AND e.status = 'active'",
            [(int) $i['id'], (int) $c['class_id']]) as $u) {
            $this->notifier->notify($u, 'institution', $title, $body, $link, null, $event);
            $n++;
        }
        return $n;
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $details */
    private function record(string $action, array $i, array $details): void
    {
        $this->audit->record($action, ['institution' => (string) $i['name']] + $details, 'institution', (string) $i['uuid']);
    }
}
