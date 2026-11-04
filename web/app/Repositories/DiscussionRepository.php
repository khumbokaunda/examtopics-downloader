<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Members' own discussion of exam questions (question_comments). A comment may suggest an
 * answer; each person's latest suggestion for a question is their vote.
 */
final class DiscussionRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Comments on some questions of an exam, oldest first, with their authors' names.
     *
     * @param list<string> $keys question keys
     * @return array<string,list<array<string,mixed>>> by question key
     */
    public function forQuestions(int $examId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $out = [];
        foreach ($this->db->all(
            "SELECT c.*, u.username, u.display_name, u.uuid AS user_uuid FROM question_comments c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.exam_id = ? AND c.question_key IN ($marks) ORDER BY c.id",
            array_merge([$examId], array_values($keys))
        ) as $row) {
            $out[(string) $row['question_key']][] = $row;
        }
        return $out;
    }

    /** @return array<string,int> how many comments each question of the exam has */
    public function counts(int $examId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT question_key, COUNT(*) AS n FROM question_comments WHERE exam_id = ? GROUP BY question_key', [$examId]) as $row) {
            $out[(string) $row['question_key']] = (int) $row['n'];
        }
        return $out;
    }

    public function add(int $examId, string $key, int $userId, string $body, ?string $answer): int
    {
        $this->db->run('INSERT INTO question_comments (exam_id, question_key, user_id, body, answer, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$examId, $key, $userId, $body, $answer, Database::now()]);
        return $this->db->lastId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM question_comments WHERE id = ?', [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM question_comments WHERE id = ?', [$id]);
    }

    /**
     * Members' votes on the exam's questions: each person's latest suggested answer, counted.
     * The answer with most votes comes first; a tie keeps the one suggested first.
     *
     * @return array<string,list<array{answer:string,count:int}>> by question key
     */
    public function votes(int $examId): array
    {
        $latest = [];
        foreach ($this->db->all('SELECT question_key, user_id, answer FROM question_comments
                WHERE exam_id = ? AND answer IS NOT NULL AND user_id IS NOT NULL ORDER BY id', [$examId]) as $row) {
            $latest[(string) $row['question_key']][(int) $row['user_id']] = (string) $row['answer'];
        }
        $out = [];
        foreach ($latest as $key => $byUser) {
            $tally = array_count_values(array_values($byUser));
            $order = array_flip(array_values(array_unique(array_values($byUser))));
            uksort($tally, static fn($a, $b): int => [$tally[$b], $order[$a]] <=> [$tally[$a], $order[$b]]);
            $out[(string) $key] = array_map(static fn($answer, $count) => ['answer' => (string) $answer, 'count' => (int) $count], array_keys($tally), $tally);
        }
        return $out;
    }

    /** Changes whenever the exam's votes do (a suggestion added or a comment deleted). */
    public function votesMark(int $examId): string
    {
        $row = $this->db->one('SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS last, COALESCE(SUM(id), 0) AS total FROM question_comments
            WHERE exam_id = ? AND answer IS NOT NULL AND user_id IS NOT NULL', [$examId]);
        return (int) $row['n'] === 0 ? '' : substr(md5($row['n'] . '/' . $row['last'] . '/' . $row['total']), 0, 8);
    }
}
