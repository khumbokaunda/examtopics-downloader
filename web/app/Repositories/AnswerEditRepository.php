<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Answers corrected or confirmed by hand, per exam question. They are applied over the
 * collected data when it is shown or turned into documents; the data itself is not changed.
 */
final class AnswerEditRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,array<string,mixed>> the exam's edits, keyed by question key */
    public function forExam(int $examId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM answer_edits WHERE exam_id = ? ORDER BY question_key', [$examId]) as $row) {
            $out[(string) $row['question_key']] = $row;
        }
        return $out;
    }

    public function save(int $examId, string $key, string $answer, ?string $note, ?int $userId): void
    {
        $now = Database::now();
        $this->db->run(
            'INSERT INTO answer_edits (exam_id, question_key, answer, note, user_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE answer = VALUES(answer), note = VALUES(note), user_id = VALUES(user_id),
                 updated_at = VALUES(updated_at)',
            [$examId, $key, $answer, $note, $userId, $now, $now]
        );
    }

    public function delete(int $examId, string $key): void
    {
        $this->db->run('DELETE FROM answer_edits WHERE exam_id = ? AND question_key = ?', [$examId, $key]);
    }

    /**
     * A short fingerprint of the exam's edits, recorded with each document so one made
     * before a correction can be rebuilt. Null when there are none.
     */
    public function fingerprint(int $examId): ?string
    {
        $edits = $this->forExam($examId);
        if ($edits === []) {
            return null;
        }
        $parts = array_map(static fn(array $e) => $e['question_key'] . '=' . $e['answer'] . ':' . (string) $e['note'], $edits);
        return substr(hash('sha256', implode("\n", $parts)), 0, 16);
    }
}
