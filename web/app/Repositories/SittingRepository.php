<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Ids;

/**
 * Sittings of institution assessments: who may sit what, the sittings themselves, their stored
 * forms, saved answers and results. Reads that start from a person go through their active
 * student memberships, so nobody reaches an assessment of an institution they do not study at.
 */
final class SittingRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Published or closed assessments assigned to this person as a student, through a class they
     * are in now or by name, with how many attempts they have used and any sitting in progress.
     *
     * @return list<array<string,mixed>>
     */
    public function assignedTo(int $userId, ?string $institutionUuid = null, ?string $assessmentUuid = null): array
    {
        $sql = "SELECT a.*, i.uuid AS institution_uuid, i.name AS institution_name, i.short_name AS institution_short, m.id AS membership_id,
                       (SELECT COUNT(*) FROM assessment_sessions s WHERE s.assessment_id = a.id AND s.membership_id = m.id) AS attempts_used,
                       (SELECT s.uuid FROM assessment_sessions s WHERE s.assessment_id = a.id AND s.membership_id = m.id AND s.status = 'in_progress' LIMIT 1) AS open_session,
                       (SELECT COUNT(*) FROM assessment_items it WHERE it.assessment_id = a.id)
                         + (SELECT COALESCE(SUM(b.question_count), 0) FROM assessment_blueprints b WHERE b.assessment_id = a.id) AS question_count
                FROM institution_memberships m
                JOIN institutions i ON i.id = m.institution_id AND i.status = 'verified'
                JOIN assessments a ON a.institution_id = m.institution_id AND a.status IN ('published', 'closed')
                WHERE m.user_id = ? AND m.status = 'active' AND m.role = 'student'
                  AND NOT EXISTS (SELECT 1 FROM institution_students st WHERE st.membership_id = m.id AND st.status <> 'active')
                  AND EXISTS (SELECT 1 FROM assessment_assignments x WHERE x.assessment_id = a.id AND (x.membership_id = m.id
                       OR x.class_id IN (SELECT e.class_id FROM class_enrolments e WHERE e.membership_id = m.id AND e.status = 'active')))";
        $args = [$userId];
        if ($institutionUuid !== null) {
            $sql .= ' AND i.uuid = ?';
            $args[] = $institutionUuid;
        }
        if ($assessmentUuid !== null) {
            $sql .= ' AND a.uuid = ?';
            $args[] = $assessmentUuid;
        }
        return $this->db->all($sql . ' ORDER BY COALESCE(a.closes_at, \'9999-12-31\'), a.title', $args);
    }

    /** The class an assessment reached this student through (one they are in now), if any. */
    public function assignedClass(int $iid, int $assessmentId, int $membershipId): ?int
    {
        $v = $this->db->value("SELECT x.class_id FROM assessment_assignments x JOIN class_enrolments e ON e.class_id = x.class_id
                               WHERE x.institution_id = ? AND x.assessment_id = ? AND e.membership_id = ? AND e.status = 'active' ORDER BY x.id LIMIT 1",
            [$iid, $assessmentId, $membershipId]);
        return $v === null ? null : (int) $v;
    }

    /**
     * Start a sitting with its form. False when that attempt number was taken meanwhile (the
     * same student starting twice at once).
     *
     * @param list<array{version:array<string,mixed>,choice_order:list<string>}> $form
     */
    public function create(int $iid, int $assessmentId, int $membershipId, ?int $classId, int $attempt, int $seed, string $endsAt, array $form, string $monitoring = 'basic'): ?string
    {
        $uuid = Ids::uuid();
        $now = Database::now();
        $max = array_sum(array_map(static fn(array $f): float => (float) $f['version']['marks'], $form));
        try {
            $this->db->transaction(function (Database $db) use ($uuid, $iid, $assessmentId, $membershipId, $classId, $attempt, $seed, $now, $endsAt, $max, $form, $monitoring): void {
                $db->run('INSERT INTO assessment_sessions (uuid, institution_id, assessment_id, membership_id, class_id, attempt, seed, monitoring, status, terms_accepted_at,
                                                          started_at, ends_at, max_score) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'in_progress\', ?, ?, ?, ?)',
                    [$uuid, $iid, $assessmentId, $membershipId, $classId, $attempt, $seed, $monitoring, $now, $now, $endsAt, number_format($max, 2, '.', '')]);
                $id = $db->lastId();
                foreach ($form as $n => $f) {
                    $db->run('INSERT INTO assessment_session_questions (session_id, institution_id, position, question_version_id, choice_order, marks) VALUES (?, ?, ?, ?, ?, ?)',
                        [$id, $iid, $n + 1, (int) $f['version']['id'], implode('', $f['choice_order']), $f['version']['marks']]);
                }
            });
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
        return $uuid;
    }

    /** @return array<string,mixed>|null a sitting with its assessment's settings and the student's account */
    public function session(string $uuid): ?array
    {
        if (!Ids::isUuid($uuid)) {
            return null;
        }
        return $this->db->one("SELECT s.*, a.uuid AS assessment_uuid, a.title, a.instructions, a.duration_minutes, a.pass_percent, a.negative_marking,
                                      a.review_policy, a.results_published_at, a.type AS assessment_type, a.monitoring AS assessment_monitoring, i.uuid AS institution_uuid, i.name AS institution_name,
                                      m.user_id, u.username, u.display_name
                               FROM assessment_sessions s JOIN assessments a ON a.id = s.assessment_id JOIN institutions i ON i.id = s.institution_id
                               JOIN institution_memberships m ON m.id = s.membership_id JOIN users u ON u.id = m.user_id
                               WHERE s.uuid = ?", [$uuid]);
    }

    /** @return list<array<string,mixed>> the sitting's questions in order, with their versions */
    public function questions(int $sessionId): array
    {
        return $this->db->all('SELECT q.*, v.type, v.text, v.choices, v.images, v.partial_credit, v.explanation, v.reference, v.topic_id, v.question_id
                               FROM assessment_session_questions q JOIN question_versions v ON v.id = q.question_version_id
                               WHERE q.session_id = ? ORDER BY q.position', [$sessionId]);
    }

    /** @return array<int,array<string,mixed>> saved answers by position */
    public function responses(int $sessionId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM assessment_responses WHERE session_id = ?', [$sessionId]) as $r) {
            $out[(int) $r['position']] = $r;
        }
        return $out;
    }

    /**
     * Save an answer unless a newer one is already stored. Every save that is kept is also added
     * to the history.
     *
     * @return bool whether it was kept
     */
    public function saveResponse(int $iid, int $sessionId, int $position, string $answer, bool $flagged, int $seq): bool
    {
        // A room of candidates saves at the same moment. Locking a row that does not exist yet
        // (SELECT ... FOR UPDATE) takes a gap lock, and two candidates whose rows sit side by side
        // in the index then block each other's INSERT: a deadlock. So the first save of a question
        // is a plain INSERT (no gap lock), and only an existing row is locked. A deadlock that can
        // still happen (the same question saved twice at once) is tried again.
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->db->transaction(function (Database $db) use ($iid, $sessionId, $position, $answer, $flagged, $seq): bool {
                    $now = Database::now();
                    $history = static fn() => $db->run('INSERT INTO assessment_response_history (session_id, institution_id, position, answer, flagged, seq, received_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$sessionId, $iid, $position, $answer, $flagged ? 1 : 0, $seq, $now]);
                    // Inserted (1 row), or already there and left as it is (0 rows, and now locked).
                    $inserted = $db->run('INSERT INTO assessment_responses (session_id, institution_id, position, answer, flagged, seq, saved_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                                          ON DUPLICATE KEY UPDATE session_id = session_id',
                        [$sessionId, $iid, $position, $answer, $flagged ? 1 : 0, $seq, $now])->rowCount() === 1;
                    if ($inserted) {
                        $history();
                        return true;
                    }
                    $current = $db->one('SELECT seq, answer, flagged FROM assessment_responses WHERE session_id = ? AND position = ? FOR UPDATE', [$sessionId, $position])
                        ?? throw new \RuntimeException('The answer could not be stored.');
                    if ((int) $current['seq'] >= $seq) {
                        return false;
                    }
                    $db->run('UPDATE assessment_responses SET answer = ?, flagged = ?, seq = ?, saved_at = ? WHERE session_id = ? AND position = ?',
                        [$answer, $flagged ? 1 : 0, $seq, $now, $sessionId, $position]);
                    if ($current['answer'] !== $answer || (int) $current['flagged'] !== ($flagged ? 1 : 0)) {
                        $history();
                    }
                    return true;
                });
            } catch (\PDOException $e) {
                if ($attempt >= 3 || !self::retriable($e)) {
                    throw $e;
                }
                usleep(random_int(5_000, 40_000) * $attempt);
            }
        }
    }

    /** A deadlock or a lock wait that ran out: the work can simply be done again. */
    public static function retriable(\PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true) || (string) $e->getCode() === '40001';
    }

    public function maxSeq(int $sessionId): int
    {
        return (int) $this->db->value('SELECT COALESCE(MAX(seq), 0) FROM assessment_responses WHERE session_id = ?', [$sessionId]);
    }

    /**
     * Hand a sitting in with its grades, once: false when it was already handed in.
     *
     * @param array<int,array{awarded:float,correct:bool}> $grades by position
     */
    public function finish(int $sessionId, string $status, float $score, ?float $percent, ?bool $passed, array $grades, ?string $marking = null): bool
    {
        return $this->db->transaction(function (Database $db) use ($sessionId, $status, $score, $percent, $passed, $grades, $marking): bool {
            $state = $db->value('SELECT status FROM assessment_sessions WHERE id = ? FOR UPDATE', [$sessionId]);
            if ($state !== 'in_progress') {
                return false;
            }
            foreach ($grades as $pos => $g) {
                $db->run('UPDATE assessment_session_questions SET awarded = ?, correct = ? WHERE session_id = ? AND position = ?',
                    [$g['awarded'] === null ? null : number_format((float) $g['awarded'], 2, '.', ''), $g['correct'] === null ? null : ($g['correct'] ? 1 : 0), $sessionId, $pos]);
            }
            $db->run('UPDATE assessment_sessions SET status = ?, submitted_at = ?, score = ?, percent = ?, passed = ?, marking = ? WHERE id = ?',
                [$status, Database::now(), number_format($score, 2, '.', ''), $percent === null ? null : number_format($percent, 2, '.', ''),
                    $passed === null ? null : ($passed ? 1 : 0), $marking, $sessionId]);
            return true;
        });
    }

    /** @param array<int,array{0:float,1:?string,2:float}> $changes position => [marks, feedback, out of] */
    public function markWritten(int $sessionId, int $markerId, array $changes): void
    {
        $this->db->transaction(function (Database $db) use ($sessionId, $markerId, $changes): void {
            foreach ($changes as $pos => [$marks, $feedback, $of]) {
                $db->run('UPDATE assessment_session_questions SET awarded = ?, correct = ?, marker_feedback = ?, marked_at = ?, marked_by = ? WHERE session_id = ? AND position = ?',
                    [number_format($marks, 2, '.', ''), $marks >= $of ? 1 : 0, $feedback, Database::now(), $markerId, $sessionId, $pos]);
            }
        });
    }

    public function setScore(int $sessionId, float $score, ?float $percent, ?bool $passed, string $marking): void
    {
        $this->db->run('UPDATE assessment_sessions SET score = ?, percent = ?, passed = ?, marking = ? WHERE id = ?',
            [number_format($score, 2, '.', ''), $percent === null ? null : number_format($percent, 2, '.', ''), $passed === null ? null : ($passed ? 1 : 0), $marking, $sessionId]);
    }

    /** @return list<array<string,mixed>> an assessment's handed-in sittings with written answers, awaiting first */
    public function forMarking(int $iid, int $assessmentId): array
    {
        return $this->db->all("SELECT s.uuid, s.attempt, s.submitted_at, s.marking, s.score, s.percent, u.username, u.display_name, st.student_number,
                    (SELECT COUNT(*) FROM assessment_session_questions q JOIN question_versions v ON v.id = q.question_version_id
                       JOIN assessment_responses r ON r.session_id = q.session_id AND r.position = q.position
                      WHERE q.session_id = s.id AND v.type = 'essay' AND r.answer <> '' AND q.awarded IS NULL) AS to_mark
                FROM assessment_sessions s JOIN institution_memberships m ON m.id = s.membership_id JOIN users u ON u.id = m.user_id
                LEFT JOIN institution_students st ON st.membership_id = m.id
                WHERE s.institution_id = ? AND s.assessment_id = ? AND s.marking IS NOT NULL ORDER BY s.marking = 'awaiting' DESC, u.display_name, u.username, s.attempt",
            [$iid, $assessmentId]);
    }

    /** @return list<string> uuids of sittings still open after their end */
    public function overdue(): array
    {
        return array_map(static fn(array $r): string => (string) $r['uuid'],
            $this->db->all("SELECT uuid FROM assessment_sessions WHERE status = 'in_progress' AND paused_at IS NULL AND ends_at <= ? ORDER BY ends_at LIMIT 1000", [Database::now()]));
    }

    /** @return list<array<string,mixed>> an assessment's sittings, newest first, with the students' names and classes */
    public function forAssessment(int $iid, int $assessmentId): array
    {
        return $this->db->all("SELECT s.*, m.uuid AS membership_uuid, u.username, u.display_name, st.student_number, c.name AS class_name, c.intake,
                                      (SELECT COUNT(*) FROM assessment_responses r WHERE r.session_id = s.id AND r.answer <> '') AS answered,
                                      (SELECT COUNT(*) FROM assessment_session_questions q WHERE q.session_id = s.id) AS question_count
                               FROM assessment_sessions s JOIN institution_memberships m ON m.id = s.membership_id JOIN users u ON u.id = m.user_id
                               LEFT JOIN institution_students st ON st.membership_id = m.id LEFT JOIN institution_classes c ON c.id = s.class_id
                               WHERE s.institution_id = ? AND s.assessment_id = ? ORDER BY s.started_at DESC", [$iid, $assessmentId]);
    }

    /** @return array<string,mixed>|null a sitting of this assessment */
    public function sessionOf(int $iid, int $assessmentId, string $uuid): ?array
    {
        if (!Ids::isUuid($uuid)) {
            return null;
        }
        return $this->db->one('SELECT s.*, u.username, u.display_name, st.student_number FROM assessment_sessions s
                               JOIN institution_memberships m ON m.id = s.membership_id JOIN users u ON u.id = m.user_id
                               LEFT JOIN institution_students st ON st.membership_id = m.id
                               WHERE s.institution_id = ? AND s.assessment_id = ? AND s.uuid = ?', [$iid, $assessmentId, $uuid]);
    }

    public function countFor(int $iid, int $assessmentId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM assessment_sessions WHERE institution_id = ? AND assessment_id = ?', [$iid, $assessmentId]);
    }

    /** @return list<array<string,mixed>> a person's sittings across institutions, newest first */
    public function history(int $userId): array
    {
        return $this->db->all("SELECT s.*, a.uuid AS assessment_uuid, a.title, a.results_published_at, i.uuid AS institution_uuid, i.name AS institution_name
                               FROM assessment_sessions s JOIN institution_memberships m ON m.id = s.membership_id
                               JOIN assessments a ON a.id = s.assessment_id JOIN institutions i ON i.id = s.institution_id
                               WHERE m.user_id = ? ORDER BY s.started_at DESC LIMIT 500", [$userId]);
    }
}
