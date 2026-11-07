<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Ids;

/**
 * Question banks, their topics and editors, questions and their versions. Every method takes
 * the institution's id and scopes by it; the composite foreign keys of migration 029 back that
 * up. Question versions are only ever inserted, never updated or deleted here.
 */
final class QuestionBankRepository
{
    public function __construct(private Database $db)
    {
    }

    // Banks ----------------------------------------------------------------------------------

    /**
     * Banks with their question counts per status. With $member, only the banks that person
     * created or was added to.
     *
     * @return list<array<string,mixed>>
     */
    public function banks(int $iid, string $status = 'active', ?int $member = null): array
    {
        $sql = "SELECT b.*, p.name AS programme_name,
                       (SELECT COUNT(*) FROM bank_questions q WHERE q.bank_id = b.id AND q.status <> 'archived') AS question_count,
                       (SELECT COUNT(*) FROM bank_questions q WHERE q.bank_id = b.id AND q.published_version IS NOT NULL AND q.status <> 'archived') AS published_count,
                       (SELECT COUNT(*) FROM bank_questions q WHERE q.bank_id = b.id AND q.status = 'review') AS review_count,
                       COALESCE(NULLIF(u.display_name, ''), u.username) AS creator_name
                FROM question_banks b
                LEFT JOIN institution_programmes p ON p.id = b.programme_id
                LEFT JOIN institution_memberships cm ON cm.id = b.created_by LEFT JOIN users u ON u.id = cm.user_id
                WHERE b.institution_id = ?";
        $args = [$iid];
        if ($status !== '') {
            $sql .= ' AND b.status = ?';
            $args[] = $status;
        }
        if ($member !== null) {
            $sql .= ' AND (b.created_by = ? OR EXISTS (SELECT 1 FROM question_bank_editors e WHERE e.bank_id = b.id AND e.membership_id = ?))';
            $args[] = $member;
            $args[] = $member;
        }
        return $this->db->all($sql . ' ORDER BY b.name', $args);
    }

    /** @return array<string,mixed>|null */
    public function bank(int $iid, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT b.*, p.uuid AS programme_uuid, p.name AS programme_name FROM question_banks b
            LEFT JOIN institution_programmes p ON p.id = b.programme_id WHERE b.institution_id = ? AND b.uuid = ?', [$iid, $uuid]) : null;
    }

    public function bankNameTaken(int $iid, string $name, int $exceptId = 0): bool
    {
        return $this->db->value('SELECT 1 FROM question_banks WHERE institution_id = ? AND name = ? AND id <> ?', [$iid, $name, $exceptId]) !== null;
    }

    /** @return string the bank's uuid */
    public function saveBank(int $iid, ?array $existing, string $name, ?string $subject, ?string $description, ?int $programmeId, ?int $creator): string
    {
        $now = Database::now();
        if ($existing === null) {
            $uuid = Ids::uuid();
            $this->db->run('INSERT INTO question_banks (uuid, institution_id, name, subject, description, programme_id, created_by, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$uuid, $iid, $name, $subject, $description, $programmeId, $creator, $now, $now]);
            return $uuid;
        }
        $this->db->run('UPDATE question_banks SET name = ?, subject = ?, description = ?, programme_id = ?, updated_at = ? WHERE institution_id = ? AND id = ?',
            [$name, $subject, $description, $programmeId, $now, $iid, (int) $existing['id']]);
        return (string) $existing['uuid'];
    }

    public function setBankStatus(int $iid, int $bankId, string $status): void
    {
        $this->db->run('UPDATE question_banks SET status = ?, updated_at = ? WHERE institution_id = ? AND id = ?', [$status, Database::now(), $iid, $bankId]);
    }

    public function isEditor(int $iid, int $bankId, int $membershipId): bool
    {
        return $this->db->value('SELECT 1 FROM question_bank_editors WHERE institution_id = ? AND bank_id = ? AND membership_id = ?', [$iid, $bankId, $membershipId]) !== null;
    }

    /** @return list<array<string,mixed>> the bank's editors (active memberships) */
    public function editors(int $iid, int $bankId): array
    {
        return $this->db->all("SELECT m.id, m.uuid, m.role, u.username, u.display_name FROM question_bank_editors e
                               JOIN institution_memberships m ON m.id = e.membership_id JOIN users u ON u.id = m.user_id
                               WHERE e.institution_id = ? AND e.bank_id = ? AND m.status = 'active' ORDER BY u.display_name, u.username", [$iid, $bankId]);
    }

    /** @param list<int> $membershipIds */
    public function setEditors(int $iid, int $bankId, array $membershipIds): void
    {
        $this->db->transaction(function (Database $db) use ($iid, $bankId, $membershipIds): void {
            $db->run('DELETE FROM question_bank_editors WHERE institution_id = ? AND bank_id = ?', [$iid, $bankId]);
            $now = Database::now();
            foreach (array_unique($membershipIds) as $m) {
                $db->run('INSERT INTO question_bank_editors (bank_id, membership_id, institution_id, added_at) VALUES (?, ?, ?, ?)', [$bankId, $m, $iid, $now]);
            }
        });
    }

    // Topics ---------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> topics, each followed by its subtopics, with question counts */
    public function topics(int $iid, int $bankId): array
    {
        $rows = $this->db->all("SELECT t.*, (SELECT COUNT(*) FROM bank_questions q JOIN question_versions v ON v.question_id = q.id AND v.version = q.latest_version
                                             WHERE v.topic_id = t.id AND q.status <> 'archived') AS question_count
                                FROM bank_topics t WHERE t.institution_id = ? AND t.bank_id = ? ORDER BY t.name", [$iid, $bankId]);
        $out = [];
        foreach ($rows as $r) {
            if ($r['parent_id'] === null) {
                $out[] = $r + ['depth' => 0];
                foreach ($rows as $c) {
                    if ((int) ($c['parent_id'] ?? 0) === (int) $r['id']) {
                        $out[] = $c + ['depth' => 1];
                    }
                }
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function topic(int $iid, int $bankId, string $uuid): ?array
    {
        return Ids::isUuid($uuid) ? $this->db->one('SELECT * FROM bank_topics WHERE institution_id = ? AND bank_id = ? AND uuid = ?', [$iid, $bankId, $uuid]) : null;
    }

    public function topicNameTaken(int $iid, int $bankId, ?int $parentId, string $name, int $exceptId = 0): bool
    {
        return $this->db->value('SELECT 1 FROM bank_topics WHERE institution_id = ? AND bank_id = ? AND parent_key = ? AND name = ? AND id <> ?',
            [$iid, $bankId, $parentId ?? 0, $name, $exceptId]) !== null;
    }

    public function addTopic(int $iid, int $bankId, ?int $parentId, string $name): void
    {
        $this->db->run('INSERT INTO bank_topics (uuid, institution_id, bank_id, parent_id, parent_key, name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Ids::uuid(), $iid, $bankId, $parentId, $parentId ?? 0, $name, Database::now()]);
    }

    public function renameTopic(int $iid, int $topicId, string $name): void
    {
        $this->db->run('UPDATE bank_topics SET name = ? WHERE institution_id = ? AND id = ?', [$name, $iid, $topicId]);
    }

    /** Whether any version of any question, or any subtopic, uses the topic. */
    public function topicUsed(int $iid, int $topicId): bool
    {
        return $this->db->value('SELECT 1 FROM question_versions WHERE institution_id = ? AND topic_id = ? LIMIT 1', [$iid, $topicId]) !== null
            || $this->db->value('SELECT 1 FROM bank_topics WHERE institution_id = ? AND parent_id = ? LIMIT 1', [$iid, $topicId]) !== null;
    }

    public function deleteTopic(int $iid, int $topicId): void
    {
        $this->db->run('DELETE FROM bank_topics WHERE institution_id = ? AND id = ?', [$iid, $topicId]);
    }

    // Questions ------------------------------------------------------------------------------

    /**
     * A bank's questions with their latest version, filtered.
     *
     * @param array{status?:string,topic?:int,difficulty?:string,type?:string,q?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function questions(int $iid, int $bankId, array $filters = []): array
    {
        $sql = 'SELECT q.*, v.type, v.text, v.marks, v.difficulty, v.topic_id, t.name AS topic_name
                FROM bank_questions q JOIN question_versions v ON v.question_id = q.id AND v.version = q.latest_version
                LEFT JOIN bank_topics t ON t.id = v.topic_id
                WHERE q.institution_id = ? AND q.bank_id = ?';
        $args = [$iid, $bankId];
        $status = (string) ($filters['status'] ?? '');
        if ($status === '') {
            $sql .= " AND q.status <> 'archived'";
        } else {
            $sql .= ' AND q.status = ?';
            $args[] = $status;
        }
        foreach (['difficulty' => 'v.difficulty', 'type' => 'v.type'] as $k => $col) {
            if ((string) ($filters[$k] ?? '') !== '') {
                $sql .= ' AND ' . $col . ' = ?';
                $args[] = $filters[$k];
            }
        }
        if ((int) ($filters['topic'] ?? 0) > 0) {
            $sql .= ' AND (v.topic_id = ? OR t.parent_id = ?)';
            $args[] = (int) $filters['topic'];
            $args[] = (int) $filters['topic'];
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $sql .= ' AND v.text LIKE ?';
            $args[] = '%' . addcslashes(trim((string) $filters['q']), '%_\\') . '%';
        }
        return $this->db->all($sql . ' ORDER BY q.id', $args);
    }

    /** @return array<string,mixed>|null a question of this bank, with its latest version */
    public function question(int $iid, int $bankId, string $uuid): ?array
    {
        if (!Ids::isUuid($uuid)) {
            return null;
        }
        return $this->db->one('SELECT q.*, v.id AS version_id, v.type, v.text, v.choices, v.images, v.marks, v.partial_credit, v.difficulty, v.topic_id,
                                      v.explanation, v.reference, t.name AS topic_name, t.uuid AS topic_uuid
                               FROM bank_questions q JOIN question_versions v ON v.question_id = q.id AND v.version = q.latest_version
                               LEFT JOIN bank_topics t ON t.id = v.topic_id
                               WHERE q.institution_id = ? AND q.bank_id = ? AND q.uuid = ?', [$iid, $bankId, $uuid]);
    }

    /** @return list<array<string,mixed>> every version of a question, newest first, with who wrote it */
    public function versions(int $iid, int $questionId): array
    {
        return $this->db->all('SELECT v.*, t.name AS topic_name, COALESCE(NULLIF(u.display_name, \'\'), u.username) AS author
                               FROM question_versions v LEFT JOIN bank_topics t ON t.id = v.topic_id
                               LEFT JOIN institution_memberships m ON m.id = v.created_by LEFT JOIN users u ON u.id = m.user_id
                               WHERE v.institution_id = ? AND v.question_id = ? ORDER BY v.version DESC', [$iid, $questionId]);
    }

    /** @return array<string,mixed>|null */
    public function version(int $iid, int $questionId, int $version): ?array
    {
        return $this->db->one('SELECT v.*, t.name AS topic_name FROM question_versions v LEFT JOIN bank_topics t ON t.id = v.topic_id
                               WHERE v.institution_id = ? AND v.question_id = ? AND v.version = ?', [$iid, $questionId, $version]);
    }

    /**
     * Write a question's next version (or its first, creating the question).
     *
     * @param array<string,mixed> $v type, text, choices (list), images (list), marks, partial_credit, difficulty, topic_id, explanation, reference
     * @return array{uuid:string,version:int}
     */
    public function writeVersion(int $iid, int $bankId, ?array $question, array $v, ?int $author): array
    {
        return $this->db->transaction(function (Database $db) use ($iid, $bankId, $question, $v, $author): array {
            $now = Database::now();
            if ($question === null) {
                $uuid = Ids::uuid();
                $db->run("INSERT INTO bank_questions (uuid, institution_id, bank_id, status, latest_version, created_by, created_at, updated_at)
                          VALUES (?, ?, ?, 'draft', 1, ?, ?, ?)", [$uuid, $iid, $bankId, $author, $now, $now]);
                $id = $db->lastId();
                $number = 1;
            } else {
                $uuid = (string) $question['uuid'];
                $id = (int) $question['id'];
                // Locked, so two saves at once cannot both write the same next number.
                $number = (int) $db->value('SELECT latest_version FROM bank_questions WHERE id = ? AND institution_id = ? FOR UPDATE', [$id, $iid]) + 1;
                $db->run("UPDATE bank_questions SET latest_version = ?, status = 'draft', updated_at = ? WHERE id = ? AND institution_id = ?", [$number, $now, $id, $iid]);
            }
            $db->run('INSERT INTO question_versions (institution_id, question_id, version, type, text, choices, images, marks, partial_credit, difficulty,
                                                     topic_id, explanation, reference, created_by, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$iid, $id, $number, $v['type'], $v['text'], json_encode($v['choices'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $v['images'] === [] ? null : json_encode($v['images'], JSON_UNESCAPED_SLASHES), $v['marks'], $v['partial_credit'] ? 1 : 0,
                    $v['difficulty'], $v['topic_id'], $v['explanation'], $v['reference'], $author, $now]);
            return ['uuid' => $uuid, 'version' => $number];
        });
    }

    public function setStatus(int $iid, int $questionId, string $status, ?int $publishedVersion = null, ?string $note = null): void
    {
        $sql = 'UPDATE bank_questions SET status = ?, review_note = ?, updated_at = ?';
        $args = [$status, $note, Database::now()];
        if ($publishedVersion !== null) {
            $sql .= ', published_version = ?';
            $args[] = $publishedVersion;
        }
        $this->db->run($sql . ' WHERE institution_id = ? AND id = ?', [...$args, $iid, $questionId]);
    }

    /**
     * Questions waiting for review, across the banks given (null: every bank).
     *
     * @return list<array<string,mixed>>
     */
    public function inReview(int $iid): array
    {
        return $this->db->all("SELECT q.*, b.uuid AS bank_uuid, b.name AS bank_name, v.text, v.type, v.marks,
                                      COALESCE(NULLIF(u.display_name, ''), u.username) AS author
                               FROM bank_questions q JOIN question_banks b ON b.id = q.bank_id
                               JOIN question_versions v ON v.question_id = q.id AND v.version = q.latest_version
                               LEFT JOIN institution_memberships m ON m.id = v.created_by LEFT JOIN users u ON u.id = m.user_id
                               WHERE q.institution_id = ? AND q.status = 'review' AND b.status = 'active' ORDER BY q.updated_at", [$iid]);
    }

    /** @return list<string> every picture address any question version uses, so image cleanup keeps them */
    public function allImageUrls(): array
    {
        $urls = [];
        foreach ($this->db->all('SELECT images FROM question_versions WHERE images IS NOT NULL') as $r) {
            foreach ((array) json_decode((string) $r['images'], true) as $u) {
                if (is_string($u)) {
                    $urls[$u] = true;
                }
            }
        }
        return array_keys($urls);
    }

    public function countInReview(int $iid): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM bank_questions q JOIN question_banks b ON b.id = q.bank_id WHERE q.institution_id = ? AND q.status = 'review' AND b.status = 'active'", [$iid]);
    }
}
