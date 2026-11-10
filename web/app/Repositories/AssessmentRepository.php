<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Ids;

/**
 * Assessments, their chosen questions, blueprints and assignments. Every method takes the
 * institution's id and scopes by it; composite foreign keys back that up.
 */
final class AssessmentRepository
{
    /** Settings columns a form may set. */
    public const SETTINGS = ['title', 'description', 'type', 'instructions', 'duration_minutes', 'pass_percent', 'max_attempts', 'opens_at', 'closes_at',
        'shuffle_questions', 'shuffle_choices', 'negative_marking', 'review_policy', 'monitoring'];

    public function __construct(private Database $db)
    {
    }

    /**
     * Assessments with how many questions and assignments each has. With $creator, only the ones
     * that person made.
     *
     * @return list<array<string,mixed>>
     */
    public function all(int $iid, string $status = '', ?int $creator = null): array
    {
        $sql = "SELECT a.*, (SELECT COUNT(*) FROM assessment_items i WHERE i.assessment_id = a.id) AS item_count,
                       (SELECT COALESCE(SUM(b.question_count), 0) FROM assessment_blueprints b WHERE b.assessment_id = a.id) AS drawn_count,
                       (SELECT COUNT(*) FROM assessment_assignments s WHERE s.assessment_id = a.id) AS assignment_count,
                       COALESCE(NULLIF(u.display_name, ''), u.username) AS creator_name
                FROM assessments a LEFT JOIN institution_memberships m ON m.id = a.created_by LEFT JOIN users u ON u.id = m.user_id
                WHERE a.institution_id = ?";
        $args = [$iid];
        if ($status !== '') {
            $sql .= ' AND a.status = ?';
            $args[] = $status;
        } else {
            $sql .= " AND a.status <> 'archived'";
        }
        if ($creator !== null) {
            $sql .= ' AND a.created_by = ?';
            $args[] = $creator;
        }
        return $this->db->all($sql . ' ORDER BY a.updated_at DESC', $args);
    }

    /** @return array<string,mixed>|null */
    public function find(int $iid, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM assessments WHERE institution_id = ? AND uuid = ?', [$iid, $uuid]) : null;
    }

    /** @return string the new assessment's uuid */
    public function create(int $iid, string $title, string $type, ?int $creator): string
    {
        $uuid = Ids::uuid();
        $now = Database::now();
        $this->db->run('INSERT INTO assessments (uuid, institution_id, title, type, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$uuid, $iid, $title, $type, $creator, $now, $now]);
        return $uuid;
    }

    /**
     * A new draft with the settings, chosen questions and blueprint of another assessment. Not
     * copied: when it opens and closes, who it is set for, its course, and the question versions
     * fixed at publishing (a copy is fixed again when it is published).
     *
     * @param array<string,mixed> $a the assessment to copy
     * @return string the copy's uuid
     */
    public function duplicate(int $iid, array $a, string $title, ?int $creator): string
    {
        return $this->db->transaction(function (Database $db) use ($iid, $a, $title, $creator): string {
            $uuid = Ids::uuid();
            $now = Database::now();
            $copied = array_values(array_diff(self::SETTINGS, ['title', 'opens_at', 'closes_at']));
            $db->run('INSERT INTO assessments (uuid, institution_id, module_id, title, ' . implode(', ', $copied) . ', created_by, created_at, updated_at)
                      VALUES (?, ?, ?, ?, ' . implode(', ', array_fill(0, count($copied), '?')) . ', ?, ?, ?)',
                [$uuid, $iid, $a['module_id'] ?? null, $title, ...array_map(static fn(string $c) => $a[$c], $copied), $creator, $now, $now]);
            $id = $db->lastId();
            $db->run('INSERT INTO assessment_items (institution_id, assessment_id, question_id, position)
                      SELECT institution_id, ?, question_id, position FROM assessment_items WHERE institution_id = ? AND assessment_id = ?', [$id, $iid, (int) $a['id']]);
            $db->run('INSERT INTO assessment_blueprints (institution_id, assessment_id, bank_id, topic_id, difficulty, question_count, position)
                      SELECT institution_id, ?, bank_id, topic_id, difficulty, question_count, position FROM assessment_blueprints WHERE institution_id = ? AND assessment_id = ?',
                [$id, $iid, (int) $a['id']]);
            return $uuid;
        });
    }

    /** @param array<string,mixed> $settings */
    public function saveSettings(int $iid, int $id, array $settings): void
    {
        $cols = array_intersect_key($settings, array_flip(self::SETTINGS));
        $this->db->run('UPDATE assessments SET ' . implode(', ', array_map(static fn(string $c): string => $c . ' = ?', array_keys($cols)))
            . ', updated_at = ? WHERE institution_id = ? AND id = ?', [...array_values($cols), Database::now(), $iid, $id]);
    }

    public function setStatus(int $iid, int $id, string $status, ?int $by = null): void
    {
        if ($status === 'published') {
            $this->db->run('UPDATE assessments SET status = ?, published_by = ?, published_at = ?, updated_at = ? WHERE institution_id = ? AND id = ?',
                [$status, $by, Database::now(), Database::now(), $iid, $id]);
            return;
        }
        $this->db->run('UPDATE assessments SET status = ?, updated_at = ? WHERE institution_id = ? AND id = ?', [$status, Database::now(), $iid, $id]);
    }

    public function touch(int $iid, int $id): void
    {
        $this->db->run('UPDATE assessments SET updated_at = ? WHERE institution_id = ? AND id = ?', [Database::now(), $iid, $id]);
    }

    // Chosen questions -----------------------------------------------------------------------

    /**
     * The chosen questions, in order, with the version they show: the fixed one once published,
     * else the question's published version (null when it has none).
     *
     * @return list<array<string,mixed>>
     */
    public function items(int $iid, int $assessmentId): array
    {
        return $this->db->all("SELECT i.*, q.uuid AS question_uuid, q.status AS question_status, q.published_version, b.uuid AS bank_uuid, b.name AS bank_name,
                                      v.id AS shown_version_id, v.version, v.type, v.text, v.marks, v.difficulty, v.topic_id, t.name AS topic_name
                               FROM assessment_items i JOIN bank_questions q ON q.id = i.question_id JOIN question_banks b ON b.id = q.bank_id
                               LEFT JOIN question_versions v ON v.id = COALESCE(i.question_version_id,
                                    (SELECT pv.id FROM question_versions pv WHERE pv.question_id = q.id AND pv.version = q.published_version))
                               LEFT JOIN bank_topics t ON t.id = v.topic_id
                               WHERE i.institution_id = ? AND i.assessment_id = ? ORDER BY i.position, i.id", [$iid, $assessmentId]);
    }

    /** @param list<int> $questionIds @return int how many were added */
    public function addItems(int $iid, int $assessmentId, array $questionIds): int
    {
        $next = (int) $this->db->value('SELECT COALESCE(MAX(position), 0) FROM assessment_items WHERE institution_id = ? AND assessment_id = ?', [$iid, $assessmentId]);
        $added = 0;
        foreach (array_unique($questionIds) as $q) {
            $added += $this->db->run('INSERT IGNORE INTO assessment_items (institution_id, assessment_id, question_id, position) VALUES (?, ?, ?, ?)',
                [$iid, $assessmentId, $q, ++$next])->rowCount();
        }
        return $added;
    }

    public function removeItem(int $iid, int $assessmentId, int $itemId): void
    {
        $this->db->run('DELETE FROM assessment_items WHERE institution_id = ? AND assessment_id = ? AND id = ?', [$iid, $assessmentId, $itemId]);
    }

    /** Move an item one place up or down. */
    public function moveItem(int $iid, int $assessmentId, int $itemId, int $direction): void
    {
        $items = $this->db->all('SELECT id, position FROM assessment_items WHERE institution_id = ? AND assessment_id = ? ORDER BY position, id', [$iid, $assessmentId]);
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $items);
        $at = array_search($itemId, $ids, true);
        $to = $at === false ? false : $at + ($direction < 0 ? -1 : 1);
        if ($at === false || $to < 0 || $to >= count($ids)) {
            return;
        }
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        foreach ($ids as $pos => $id) {
            $this->db->run('UPDATE assessment_items SET position = ? WHERE institution_id = ? AND id = ?', [$pos + 1, $iid, $id]);
        }
    }

    /** Fix every chosen question to the version published now. */
    public function pinItems(int $iid, int $assessmentId): void
    {
        $this->db->run('UPDATE assessment_items i JOIN bank_questions q ON q.id = i.question_id
                        JOIN question_versions v ON v.question_id = q.id AND v.version = q.published_version
                        SET i.question_version_id = v.id WHERE i.institution_id = ? AND i.assessment_id = ?', [$iid, $assessmentId]);
    }

    public function unpinItems(int $iid, int $assessmentId): void
    {
        $this->db->run('UPDATE assessment_items SET question_version_id = NULL WHERE institution_id = ? AND assessment_id = ?', [$iid, $assessmentId]);
    }

    // Blueprints -----------------------------------------------------------------------------

    /** @return list<array<string,mixed>> with the bank and topic names */
    public function blueprints(int $iid, int $assessmentId): array
    {
        return $this->db->all('SELECT r.*, b.uuid AS bank_uuid, b.name AS bank_name, t.name AS topic_name FROM assessment_blueprints r
                               JOIN question_banks b ON b.id = r.bank_id LEFT JOIN bank_topics t ON t.id = r.topic_id
                               WHERE r.institution_id = ? AND r.assessment_id = ? ORDER BY r.position, r.id', [$iid, $assessmentId]);
    }

    public function addBlueprint(int $iid, int $assessmentId, int $bankId, ?int $topicId, string $difficulty, int $count): void
    {
        $next = (int) $this->db->value('SELECT COALESCE(MAX(position), 0) FROM assessment_blueprints WHERE institution_id = ? AND assessment_id = ?', [$iid, $assessmentId]);
        $this->db->run('INSERT INTO assessment_blueprints (institution_id, assessment_id, bank_id, topic_id, difficulty, question_count, position) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$iid, $assessmentId, $bankId, $topicId, $difficulty, $count, $next + 1]);
    }

    public function removeBlueprint(int $iid, int $assessmentId, int $id): void
    {
        $this->db->run('DELETE FROM assessment_blueprints WHERE institution_id = ? AND assessment_id = ? AND id = ?', [$iid, $assessmentId, $id]);
    }

    /**
     * The published, not archived questions a blueprint row can draw from, with their published
     * version, leaving out the questions chosen one by one.
     *
     * @return list<array<string,mixed>> question_versions rows plus question_id
     */
    public function pool(int $iid, int $assessmentId, int $bankId, ?int $topicId, string $difficulty): array
    {
        $sql = "SELECT v.*, q.uuid AS question_uuid FROM bank_questions q
                JOIN question_versions v ON v.question_id = q.id AND v.version = q.published_version
                LEFT JOIN bank_topics t ON t.id = v.topic_id
                WHERE q.institution_id = ? AND q.bank_id = ? AND q.status <> 'archived' AND q.published_version IS NOT NULL
                  AND q.id NOT IN (SELECT question_id FROM assessment_items WHERE assessment_id = ?)";
        $args = [$iid, $bankId, $assessmentId];
        if ($topicId !== null) {
            $sql .= ' AND (v.topic_id = ? OR t.parent_id = ?)';
            $args[] = $topicId;
            $args[] = $topicId;
        }
        if ($difficulty !== '') {
            $sql .= ' AND v.difficulty = ?';
            $args[] = $difficulty;
        }
        return $this->db->all($sql . ' ORDER BY q.id', $args);
    }

    /** @return list<array<string,mixed>> question_versions rows by id, in the order given */
    public function versionsById(int $iid, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all('SELECT * FROM question_versions WHERE institution_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [$iid, ...$ids]);
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['id']] = $r;
        }
        return array_values(array_filter(array_map(static fn($id) => $by[(int) $id] ?? null, $ids)));
    }

    // Assignments ----------------------------------------------------------------------------

    /** @return list<array<string,mixed>> with the class or the student's name */
    public function assignments(int $iid, int $assessmentId): array
    {
        return $this->db->all("SELECT s.*, c.uuid AS class_uuid, c.name AS class_name, c.intake,
                                      (SELECT COUNT(*) FROM class_enrolments e WHERE e.class_id = c.id AND e.status = 'active') AS class_size,
                                      m.uuid AS membership_uuid, u.username, u.display_name
                               FROM assessment_assignments s LEFT JOIN institution_classes c ON c.id = s.class_id
                               LEFT JOIN institution_memberships m ON m.id = s.membership_id LEFT JOIN users u ON u.id = m.user_id
                               WHERE s.institution_id = ? AND s.assessment_id = ? ORDER BY s.class_id IS NULL, c.name, u.display_name, u.username", [$iid, $assessmentId]);
    }

    public function assign(int $iid, int $assessmentId, ?int $classId, ?int $membershipId, ?int $by): bool
    {
        $exists = $this->db->value('SELECT 1 FROM assessment_assignments WHERE institution_id = ? AND assessment_id = ? AND '
            . ($classId !== null ? 'class_id = ?' : 'membership_id = ?'), [$iid, $assessmentId, $classId ?? $membershipId]);
        if ($exists !== null) {
            return false;
        }
        $this->db->run('INSERT INTO assessment_assignments (institution_id, assessment_id, class_id, membership_id, assigned_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$iid, $assessmentId, $classId, $membershipId, $by, Database::now()]);
        return true;
    }

    /** @return array<string,mixed>|null */
    public function assignment(int $iid, int $assessmentId, int $id): ?array
    {
        return $this->db->one('SELECT * FROM assessment_assignments WHERE institution_id = ? AND assessment_id = ? AND id = ?', [$iid, $assessmentId, $id]);
    }

    public function unassign(int $iid, int $assessmentId, int $id): void
    {
        $this->db->run('DELETE FROM assessment_assignments WHERE institution_id = ? AND assessment_id = ? AND id = ?', [$iid, $assessmentId, $id]);
    }

    /** How many sittings an assessment has had (any status). */
    public function sittingCount(int $iid, int $assessmentId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM assessment_sessions WHERE institution_id = ? AND assessment_id = ?', [$iid, $assessmentId]);
    }

    /** How many different students the assignments reach today (class members now, plus named students). */
    public function candidateCount(int $iid, int $assessmentId): int
    {
        return (int) $this->db->value("SELECT COUNT(DISTINCT m.id) FROM institution_memberships m
            WHERE m.institution_id = ? AND m.status = 'active' AND m.role = 'student' AND (
                m.id IN (SELECT membership_id FROM assessment_assignments WHERE assessment_id = ? AND membership_id IS NOT NULL)
             OR m.id IN (SELECT e.membership_id FROM class_enrolments e JOIN assessment_assignments s ON s.class_id = e.class_id
                         WHERE s.assessment_id = ? AND e.status = 'active'))", [$iid, $assessmentId, $assessmentId]);
    }
}
