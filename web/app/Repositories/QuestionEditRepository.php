<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Question wording, choices and pictures corrected by hand, per exam question. Like answer
 * corrections they are applied over the collected data when it is shown or turned into
 * documents; the data itself is not changed. A field left null is shown as collected.
 */
final class QuestionEditRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array<string,array{text:?string,choices:?array<string,string>,question_images:?list<string>,answer_images:?list<string>,updated_at:string,user_id:?int}>
     *         the exam's edits, keyed by question key
     */
    public function forExam(int $examId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM question_edits WHERE exam_id = ? ORDER BY question_key', [$examId]) as $row) {
            $out[(string) $row['question_key']] = self::decode($row);
        }
        return $out;
    }

    /**
     * Store the changed fields of one question; null fields are shown as collected. With every
     * field null the edit is removed.
     *
     * @param array<string,string>|null $choices @param list<string>|null $questionImages @param list<string>|null $answerImages
     */
    public function save(int $examId, string $key, ?string $text, ?array $choices, ?array $questionImages, ?array $answerImages, ?int $userId): void
    {
        if ($text === null && $choices === null && $questionImages === null && $answerImages === null) {
            $this->delete($examId, $key);
            return;
        }
        $json = static fn(?array $v): ?string => $v === null ? null : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now = Database::now();
        $this->db->run(
            'INSERT INTO question_edits (exam_id, question_key, text, choices, question_images, answer_images, user_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE text = VALUES(text), choices = VALUES(choices), question_images = VALUES(question_images),
                 answer_images = VALUES(answer_images), user_id = VALUES(user_id), updated_at = VALUES(updated_at)',
            [$examId, $key, $text, $json($choices), $json($questionImages), $json($answerImages), $userId, $now, $now]
        );
    }

    public function delete(int $examId, string $key): void
    {
        $this->db->run('DELETE FROM question_edits WHERE exam_id = ? AND question_key = ?', [$examId, $key]);
    }

    /** @return list<string> every picture address the exam's edits use, uploaded ones included */
    public function imageUrls(int $examId): array
    {
        $urls = [];
        foreach ($this->forExam($examId) as $edit) {
            foreach (array_merge($edit['question_images'] ?? [], $edit['answer_images'] ?? []) as $u) {
                $urls[$u] = true;
            }
        }
        return array_keys($urls);
    }

    /** @return list<string> every picture address any exam's edits use, so image cleanup keeps them */
    public function allImageUrls(): array
    {
        $urls = [];
        foreach ($this->db->all('SELECT * FROM question_edits WHERE question_images IS NOT NULL OR answer_images IS NOT NULL') as $row) {
            $edit = self::decode($row);
            foreach (array_merge($edit['question_images'] ?? [], $edit['answer_images'] ?? []) as $u) {
                $urls[$u] = true;
            }
        }
        return array_keys($urls);
    }

    /** @param array<string,mixed> $row */
    private static function decode(array $row): array
    {
        $list = static function (mixed $json): ?array {
            $v = $json === null ? null : json_decode((string) $json, true);
            return is_array($v) ? array_values(array_filter(array_map('strval', $v), static fn(string $u): bool => $u !== '')) : null;
        };
        $choices = $row['choices'] === null ? null : json_decode((string) $row['choices'], true);
        return [
            'text' => $row['text'] === null ? null : (string) $row['text'],
            'choices' => is_array($choices) ? array_map('strval', $choices) : null,
            'question_images' => $list($row['question_images']),
            'answer_images' => $list($row['answer_images']),
            'updated_at' => (string) $row['updated_at'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
        ];
    }
}
