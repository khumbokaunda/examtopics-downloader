<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use RuntimeException;

/**
 * A course's gradebook. Each grade item has a weight. An online item is an assessment: a
 * learner's mark is their best handed-in attempt, counted once the assessment's results are
 * published (out of 100, its percent). An offline item has a maximum mark and marks typed or
 * uploaded by staff, shown to learners once the item is released.
 *
 * The course grade is the weighted average of the items a learner has a mark for, while it is
 * provisional. When staff make the grades final, an item with no mark counts as zero. Staff may
 * override a learner's course grade, with a reason that is kept.
 *
 * Nothing is changed in place: a new mark or override supersedes the previous one, which is
 * kept with who wrote it and when.
 */
final class Gradebook
{
    public const MAX_ITEMS = 50;
    public const MAX_ROWS = 2000;

    public function __construct(private Database $db, private AuditLog $audit, private Notifier $notifier)
    {
    }

    // Items ----------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> the course's items, in order, with their assessment's state */
    public function items(int $iid, int $courseId): array
    {
        return $this->db->all('SELECT g.*, a.uuid AS assessment_uuid, a.title AS assessment_title, a.status AS assessment_status, a.results_published_at
                               FROM grade_items g LEFT JOIN assessments a ON a.id = g.assessment_id
                               WHERE g.institution_id = ? AND g.course_id = ? AND g.removed_at IS NULL ORDER BY g.position, g.id', [$iid, $courseId]);
    }

    /** @return array<string,mixed>|null */
    public function item(int $iid, int $courseId, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT g.*, a.uuid AS assessment_uuid, a.results_published_at FROM grade_items g LEFT JOIN assessments a ON a.id = g.assessment_id
            WHERE g.institution_id = ? AND g.course_id = ? AND g.uuid = ? AND g.removed_at IS NULL', [$iid, $courseId, $uuid]) : null;
    }

    /**
     * Add an item: an assessment of the course (online), or one marked by hand (offline).
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $c @param array<string,mixed> $in kind, assessment, title, max_marks, weight
     */
    public function addItem(array $i, array $m, array $c, array $in): void
    {
        $iid = (int) $i['id'];
        if (count($this->items($iid, (int) $c['id'])) >= self::MAX_ITEMS) {
            throw new RuntimeException('A course has at most ' . self::MAX_ITEMS . ' grade items.');
        }
        $weight = self::weight((string) ($in['weight'] ?? ''));
        $assessmentId = null;
        if (($in['kind'] ?? '') === 'online') {
            $a = Ids::isUuid((string) ($in['assessment'] ?? '')) ? $this->db->one("SELECT * FROM assessments WHERE institution_id = ? AND uuid = ? AND course_id = ? AND status <> 'archived'",
                [$iid, (string) $in['assessment'], (int) $c['id']]) : null;
            if ($a === null) {
                throw new RuntimeException('Choose one of the course\'s assessments.');
            }
            if ($this->db->value('SELECT 1 FROM grade_items WHERE course_id = ? AND assessment_id = ? AND removed_at IS NULL', [(int) $c['id'], (int) $a['id']]) !== null) {
                throw new RuntimeException($a['title'] . ' is already in the gradebook.');
            }
            $assessmentId = (int) $a['id'];
            $title = (string) $a['title'];
            $max = 100.0;
        } else {
            $title = InstitutionStructure::cleanName((string) ($in['title'] ?? ''), 160);
            $max = self::number((string) ($in['max_marks'] ?? ''), 'The maximum mark', 0.01, 100000);
        }
        $now = Database::now();
        $pos = (int) $this->db->value('SELECT COALESCE(MAX(position), 0) + 1 FROM grade_items WHERE course_id = ?', [(int) $c['id']]);
        $this->db->run('INSERT INTO grade_items (uuid, institution_id, course_id, assessment_id, title, max_marks, weight, position, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [Ids::uuid(), $iid, (int) $c['id'], $assessmentId, $title, $max, $weight, $pos, (int) $m['id'], $now, $now]);
        $this->record('institution.grade_item_added', $i, ['course' => $c['title'], 'item' => $title, 'weight' => $weight]);
    }

    /** Change an item's weight (and, offline, its title and maximum mark). @param array<string,mixed> $i @param array<string,mixed> $c @param array<string,mixed> $g @param array<string,mixed> $in */
    public function saveItem(array $i, array $c, array $g, array $in): void
    {
        $weight = self::weight((string) ($in['weight'] ?? ''));
        $title = $g['assessment_id'] === null ? InstitutionStructure::cleanName((string) ($in['title'] ?? ''), 160) : (string) $g['title'];
        $max = $g['assessment_id'] === null ? self::number((string) ($in['max_marks'] ?? ''), 'The maximum mark', 0.01, 100000) : 100.0;
        if ($g['assessment_id'] === null && $max < (float) $g['max_marks']) {
            $over = (int) $this->db->value('SELECT COUNT(*) FROM grade_marks WHERE item_id = ? AND superseded_at IS NULL AND marks > ?', [(int) $g['id'], $max]);
            if ($over > 0) {
                throw new RuntimeException($over . ' marks are above ' . self::fmt($max) . '. Change them first.');
            }
        }
        $this->db->run('UPDATE grade_items SET title = ?, max_marks = ?, weight = ?, updated_at = ? WHERE id = ? AND institution_id = ?',
            [$title, $max, $weight, Database::now(), (int) $g['id'], (int) $i['id']]);
        $this->record('institution.grade_item_changed', $i, ['course' => $c['title'], 'item' => $title, 'weight' => $weight]);
    }

    /** Take an item out of the gradebook. Its marks are kept. @param array<string,mixed> $i @param array<string,mixed> $c @param array<string,mixed> $g */
    public function removeItem(array $i, array $c, array $g): void
    {
        $this->db->run('UPDATE grade_items SET removed_at = ? WHERE id = ? AND institution_id = ?', [Database::now(), (int) $g['id'], (int) $i['id']]);
        $this->record('institution.grade_item_removed', $i, ['course' => $c['title'], 'item' => $g['title']]);
    }

    // Marks ----------------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> current offline marks of an item by membership id */
    public function marks(int $iid, int $itemId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM grade_marks WHERE institution_id = ? AND item_id = ? AND superseded_at IS NULL', [$iid, $itemId]) as $r) {
            $out[(int) $r['membership_id']] = $r;
        }
        return $out;
    }

    /**
     * Enter marks for an offline item. Only learners whose mark or feedback changed get a new
     * row. Every value is checked before anything is written.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $c @param array<string,mixed> $g
     * @param array<int,array{marks:string,feedback:string}> $entries by membership id
     * @return int how many changed
     */
    public function enter(array $i, array $m, array $c, array $g, array $entries, string $how = 'typed'): int
    {
        if ($g['assessment_id'] !== null) {
            throw new RuntimeException('An online item\'s marks come from its assessment.');
        }
        $this->editable($c);
        $iid = (int) $i['id'];
        $learners = array_flip(array_map(static fn(array $l): int => (int) $l['membership_id'], $this->learners($iid, (int) $c['class_id'])));
        $current = $this->marks($iid, (int) $g['id']);
        $changes = [];
        $errors = [];
        foreach ($entries as $mid => $e) {
            if (!isset($learners[(int) $mid])) {
                continue;
            }
            $raw = trim((string) $e['marks']);
            $marks = null;
            if ($raw !== '') {
                if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > (float) $g['max_marks']) {
                    $errors[] = ($e['name'] ?? 'A mark') . ': give a mark from 0 to ' . self::fmt((float) $g['max_marks']) . '.';
                    continue;
                }
                $marks = round((float) $raw, 2);
            }
            $feedback = trim(str_replace(["\r\n", "\r"], "\n", (string) $e['feedback']));
            if (mb_strlen($feedback) > 2000) {
                $errors[] = ($e['name'] ?? 'Feedback') . ': feedback has at most 2,000 characters.';
                continue;
            }
            $was = $current[(int) $mid] ?? null;
            $same = $was !== null ? ($was['marks'] === null ? $marks === null : $marks !== null && abs((float) $was['marks'] - $marks) < 0.001) && (string) $was['feedback'] === $feedback
                : $marks === null && $feedback === '';
            if (!$same) {
                $changes[(int) $mid] = [$marks, $feedback !== '' ? $feedback : null, $was];
            }
        }
        if ($errors !== []) {
            throw new RuntimeException('Nothing was saved. ' . implode(' ', array_slice($errors, 0, 10)) . (count($errors) > 10 ? ' And ' . (count($errors) - 10) . ' more.' : ''));
        }
        $now = Database::now();
        $this->db->transaction(function (Database $db) use ($changes, $iid, $g, $m, $now): void {
            foreach ($changes as $mid => [$marks, $feedback, $was]) {
                if ($was !== null) {
                    $db->run('UPDATE grade_marks SET superseded_at = ? WHERE id = ?', [$now, (int) $was['id']]);
                }
                $db->run('INSERT INTO grade_marks (institution_id, item_id, membership_id, marks, feedback, entered_by, entered_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$iid, (int) $g['id'], $mid, $marks, $feedback, (int) $m['id'], $now]);
            }
        });
        if ($changes !== []) {
            $this->record('institution.grade_marks_entered', $i, ['course' => $c['title'], 'item' => $g['title'], 'count' => count($changes), 'how' => $how]);
        }
        return count($changes);
    }

    /**
     * Read marks from a CSV with the columns student (a student number, email or username),
     * marks and, optionally, feedback.
     *
     * @param array<string,mixed> $c @return array<int,array{marks:string,feedback:string,name:string}> by membership id
     */
    public function parseMarks(int $iid, array $c, string $csv): array
    {
        if (strlen($csv) > 1048576) {
            throw new RuntimeException('The file is larger than 1 MB.');
        }
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        $first = (string) (preg_split('/\r\n|\r|\n/', $csv)[0] ?? '');
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = array_map(static fn($h): string => strtolower(trim((string) $h)), (array) fgetcsv($stream, 0, $delimiter, '"', ''));
        $col = static fn(array $names): ?int => ($k = array_values(array_intersect($names, $header))) !== [] ? (int) array_search($k[0], $header, true) : null;
        $who = $col(['student', 'student_number', 'number', 'email', 'username']);
        $marksCol = $col(['marks', 'mark', 'score']);
        $feedbackCol = $col(['feedback', 'comment', 'comments']);
        if ($who === null || $marksCol === null) {
            throw new RuntimeException('The first row must name the columns: "student" (a student number, email or username) and "marks", and "feedback" if you like.');
        }
        $index = [];
        foreach ($this->learners($iid, (int) $c['class_id']) as $l) {
            foreach ([(string) $l['student_number'], strtolower((string) $l['email']), strtolower((string) $l['username'])] as $key) {
                if ($key !== '') {
                    $index[strtolower($key)] = $l;
                }
            }
        }
        $out = [];
        $errors = [];
        $line = 1;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || trim(implode('', array_map('strval', $cells))) === '') {
                continue;
            }
            if ($line > self::MAX_ROWS + 1) {
                throw new RuntimeException('The file has more than ' . self::MAX_ROWS . ' rows.');
            }
            $key = strtolower(trim((string) ($cells[$who] ?? '')));
            $l = $index[$key] ?? null;
            if ($l === null) {
                $errors[] = 'Line ' . $line . ': "' . mb_substr($key, 0, 60) . '" is not a learner in this course.';
                continue;
            }
            if (isset($out[(int) $l['membership_id']])) {
                $errors[] = 'Line ' . $line . ': ' . Access::displayName($l) . ' is in the file twice.';
                continue;
            }
            $out[(int) $l['membership_id']] = ['marks' => trim((string) ($cells[$marksCol] ?? '')), 'feedback' => $feedbackCol !== null ? (string) ($cells[$feedbackCol] ?? '') : '',
                'name' => 'Line ' . $line . ' (' . Access::displayName($l) . ')'];
        }
        fclose($stream);
        if ($errors !== []) {
            throw new RuntimeException('Nothing was saved. ' . implode(' ', array_slice($errors, 0, 10)) . (count($errors) > 10 ? ' And ' . (count($errors) - 10) . ' more.' : ''));
        }
        if ($out === []) {
            throw new RuntimeException('The file has no marks in it.');
        }
        return $out;
    }

    /** Show an offline item's marks to the learners, and tell them. @param array<string,mixed> $i @param array<string,mixed> $c @param array<string,mixed> $g */
    public function release(array $i, array $c, array $g): int
    {
        if ($g['assessment_id'] !== null || $g['released_at'] !== null) {
            throw new RuntimeException('Those marks are already shown.');
        }
        $this->db->run('UPDATE grade_items SET released_at = ? WHERE id = ? AND institution_id = ?', [Database::now(), (int) $g['id'], (int) $i['id']]);
        $this->record('institution.grade_marks_released', $i, ['course' => $c['title'], 'item' => $g['title']]);
        return $this->tell($i, $c, 'Marks for ' . $g['title'], 'Your mark for ' . $g['title'] . ' in ' . $c['title'] . ' is out.', 'grades-item:' . $g['uuid']);
    }

    // Overrides and final grades ---------------------------------------------------------------

    /** @return array<int,array<string,mixed>> current overrides by membership id */
    public function overrides(int $iid, int $courseId): array
    {
        $out = [];
        foreach ($this->db->all("SELECT o.*, COALESCE(NULLIF(u.display_name, ''), u.username) AS set_by_name FROM grade_overrides o
                                 LEFT JOIN institution_memberships m ON m.id = o.set_by LEFT JOIN users u ON u.id = m.user_id
                                 WHERE o.institution_id = ? AND o.course_id = ? AND o.superseded_at IS NULL AND o.percent IS NOT NULL", [$iid, $courseId]) as $r) {
            $out[(int) $r['membership_id']] = $r;
        }
        return $out;
    }

    /**
     * Set (or, with an empty percent, take away) a learner's course grade by hand.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $c @param array<string,mixed> $learner a membership
     */
    public function override(array $i, array $m, array $c, array $learner, string $percent, string $reason): void
    {
        $this->editable($c);
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Give the reason (up to 500 characters). It is kept with the grade.');
        }
        $value = trim($percent) === '' ? null : self::number($percent, 'The grade', 0, 100);
        $iid = (int) $i['id'];
        $now = Database::now();
        $this->db->transaction(function (Database $db) use ($iid, $c, $learner, $value, $reason, $m, $now): void {
            $db->run('UPDATE grade_overrides SET superseded_at = ? WHERE institution_id = ? AND course_id = ? AND membership_id = ? AND superseded_at IS NULL',
                [$now, $iid, (int) $c['id'], (int) $learner['id']]);
            $db->run('INSERT INTO grade_overrides (institution_id, course_id, membership_id, percent, reason, set_by, set_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$iid, (int) $c['id'], (int) $learner['id'], $value, $reason, (int) $m['id'], $now]);
        });
        $this->record($value === null ? 'institution.grade_override_removed' : 'institution.grade_override_set', $i,
            ['course' => $c['title'], 'student' => Access::displayName($learner), 'percent' => $value, 'reason' => $reason]);
    }

    /** Make the course grades final (unmarked items then count as zero), or provisional again. @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $c */
    public function setFinal(array $i, array $m, array $c, bool $final): int
    {
        if ($final === ($c['grades_final_at'] !== null)) {
            throw new RuntimeException($final ? 'The grades are already final.' : 'The grades are not final.');
        }
        if ($final && $this->items((int) $i['id'], (int) $c['id']) === []) {
            throw new RuntimeException('Add the grade items first.');
        }
        $this->db->run('UPDATE courses SET grades_final_at = ?, grades_final_by = ? WHERE id = ? AND institution_id = ?',
            [$final ? Database::now() : null, $final ? (int) $m['id'] : null, (int) $c['id'], (int) $i['id']]);
        $this->record($final ? 'institution.grades_finalised' : 'institution.grades_reopened', $i, ['course' => $c['title']]);
        return $final ? $this->tell($i, $c, 'Final grades: ' . $c['title'], 'Your final grade for ' . $c['title'] . ' is out.', 'grades-final:' . $c['uuid'] . ':' . time()) : 0;
    }

    // The book -------------------------------------------------------------------------------

    /**
     * Every learner's marks and course grade. For staff ($learnerView false) every mark counts;
     * for a learner only what has been released to them (offline items released, assessments
     * whose results are published).
     *
     * @param array<string,mixed> $c
     * @return array{items:list<array<string,mixed>>,rows:list<array<string,mixed>>,final:bool,weight:float}
     */
    public function book(int $iid, array $c, bool $learnerView = false, ?int $onlyMembership = null): array
    {
        $final = $c['grades_final_at'] !== null;
        $items = $this->items($iid, (int) $c['id']);
        $learners = $this->learners($iid, (int) $c['class_id']);
        if ($onlyMembership !== null) {
            $learners = array_values(array_filter($learners, static fn(array $l): bool => (int) $l['membership_id'] === $onlyMembership));
        }
        $marks = [];
        foreach ($items as $g) {
            if ($g['assessment_id'] !== null) {
                // Best handed-in attempt; staff see it before results are published, learners after.
                foreach ($this->db->all("SELECT membership_id, MAX(percent) AS best FROM assessment_sessions WHERE institution_id = ? AND assessment_id = ?
                                         AND status <> 'in_progress' AND percent IS NOT NULL GROUP BY membership_id", [$iid, (int) $g['assessment_id']]) as $r) {
                    $marks[(int) $g['id']][(int) $r['membership_id']] = ['percent' => (float) $r['best'], 'marks' => (float) $r['best'], 'feedback' => null,
                        'visible' => $g['results_published_at'] !== null];
                }
            } else {
                foreach ($this->marks($iid, (int) $g['id']) as $mid => $r) {
                    if ($r['marks'] !== null || $r['feedback'] !== null) {
                        $marks[(int) $g['id']][$mid] = ['percent' => $r['marks'] !== null ? (float) $r['marks'] / (float) $g['max_marks'] * 100 : null,
                            'marks' => $r['marks'] !== null ? (float) $r['marks'] : null, 'feedback' => $r['feedback'], 'visible' => $g['released_at'] !== null];
                    }
                }
            }
        }
        $overrides = $this->overrides($iid, (int) $c['id']);
        $rows = [];
        foreach ($learners as $l) {
            $mid = (int) $l['membership_id'];
            $cells = [];
            $sum = 0.0;
            $weights = 0.0;
            $counted = 0;
            foreach ($items as $g) {
                $cell = $marks[(int) $g['id']][$mid] ?? null;
                if ($cell !== null && $learnerView && !$cell['visible']) {
                    $cell = null;
                }
                $cells[(int) $g['id']] = $cell;
                $w = (float) $g['weight'];
                if ($cell !== null && $cell['percent'] !== null) {
                    $sum += $w * $cell['percent'];
                    $weights += $w;
                    $counted++;
                } elseif ($final && !$learnerView) {
                    $weights += $w; // final: no mark counts as zero
                } elseif ($final && $learnerView && $this->releasedFor($g)) {
                    $weights += $w;
                }
            }
            $computed = $weights > 0 ? round($sum / $weights, 2) : null;
            $override = $overrides[$mid] ?? null;
            $grade = $override !== null ? (float) $override['percent'] : $computed;
            $pass = $c['pass_percent'] !== null && $grade !== null ? $grade >= (float) $c['pass_percent'] : null;
            $rows[] = $l + ['cells' => $cells, 'computed' => $computed, 'override' => $override, 'grade' => $grade, 'passed' => $pass, 'counted' => $counted];
        }
        return ['items' => $items, 'rows' => $rows, 'final' => $final, 'weight' => array_sum(array_map(static fn(array $g): float => (float) $g['weight'], $items))];
    }

    /** The book as CSV. @param array{items:list<array<string,mixed>>,rows:list<array<string,mixed>>,final:bool} $book */
    public static function csv(array $book): string
    {
        $safe = static fn(mixed $v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v;
        $out = fopen('php://temp', 'r+');
        $head = ['name', 'username', 'student_number'];
        foreach ($book['items'] as $g) {
            $head[] = $g['title'] . ' (' . ($g['assessment_id'] !== null ? '%' : 'out of ' . self::fmt((float) $g['max_marks'])) . ', weight ' . self::fmt((float) $g['weight']) . ')';
        }
        array_push($head, 'computed_percent', 'override_percent', 'override_reason', 'course_percent', 'passed', 'status');
        fputcsv($out, array_map($safe, $head), ',', '"', '');
        foreach ($book['rows'] as $r) {
            $line = [Access::displayName($r), $r['username'], $r['student_number'] ?? ''];
            foreach ($book['items'] as $g) {
                $cell = $r['cells'][(int) $g['id']] ?? null;
                $line[] = $cell !== null && $cell['marks'] !== null ? self::fmt((float) $cell['marks']) : '';
            }
            array_push($line, $r['computed'] !== null ? self::fmt($r['computed']) : '', $r['override'] !== null ? self::fmt((float) $r['override']['percent']) : '',
                $r['override']['reason'] ?? '', $r['grade'] !== null ? self::fmt($r['grade']) : '', $r['passed'] === null ? '' : ($r['passed'] ? 'yes' : 'no'),
                $book['final'] ? 'final' : 'provisional');
            fputcsv($out, array_map($safe, $line), ',', '"', '');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /** @return list<array<string,mixed>> the course's learners (the class's active students), by name */
    public function learners(int $iid, int $classId): array
    {
        return $this->db->all("SELECT m.id AS membership_id, m.uuid AS membership_uuid, u.username, u.display_name, u.email, s.student_number
                               FROM class_enrolments e JOIN institution_memberships m ON m.id = e.membership_id AND m.status = 'active'
                               JOIN users u ON u.id = m.user_id LEFT JOIN institution_students s ON s.membership_id = m.id
                               WHERE e.institution_id = ? AND e.class_id = ? AND e.status = 'active' ORDER BY u.display_name, u.username", [$iid, $classId]);
    }

    public static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /** @param array<string,mixed> $g */
    private function releasedFor(array $g): bool
    {
        return $g['assessment_id'] !== null ? $g['results_published_at'] !== null : $g['released_at'] !== null;
    }

    /** @param array<string,mixed> $c */
    private function editable(array $c): void
    {
        if ($c['grades_final_at'] !== null) {
            throw new RuntimeException('The grades are final. Make them provisional again to change them.');
        }
    }

    private static function weight(string $raw): float
    {
        return self::number($raw, 'The weight', 0.01, 1000);
    }

    private static function number(string $raw, string $what, float $min, float $max): float
    {
        $raw = trim($raw);
        if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
            throw new RuntimeException($what . ' is a number from ' . self::fmt($min) . ' to ' . self::fmt($max) . '.');
        }
        return round((float) $raw, 2);
    }

    /** Tell the course's learners. @param array<string,mixed> $i @param array<string,mixed> $c */
    private function tell(array $i, array $c, string $title, string $body, string $event): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT u.* FROM class_enrolments e JOIN institution_memberships m ON m.id = e.membership_id AND m.status = 'active'
                                 JOIN users u ON u.id = m.user_id AND u.status = 'active' WHERE e.institution_id = ? AND e.class_id = ? AND e.status = 'active'",
            [(int) $i['id'], (int) $c['class_id']]) as $u) {
            $this->notifier->notify($u, 'institution', $title, $body, '/institutions/' . $i['uuid'] . '/courses/' . $c['uuid'] . '?tab=grades', null, $event);
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
