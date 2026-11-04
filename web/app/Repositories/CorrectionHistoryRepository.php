<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Every change to a question's corrections (correction_history): what its answer correction
 * ("answer") or question edit ("question") was before and after, and who changed it. Written by
 * the repositories that save corrections, so every way of changing one is recorded.
 */
final class CorrectionHistoryRepository
{
    public const KINDS = ['answer', 'question'];

    public function __construct(private Database $db)
    {
    }

    /**
     * Record a change; nothing when nothing changed.
     *
     * @param array<string,mixed>|null $before @param array<string,mixed>|null $after
     */
    public function record(int $examId, string $key, string $kind, ?array $before, ?array $after, ?int $userId): void
    {
        if ($before === $after) {
            return;
        }
        $json = static fn(?array $v): ?string => $v === null ? null : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->db->run('INSERT INTO correction_history (exam_id, question_key, kind, before_state, after_state, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$examId, $key, $kind, $json($before), $json($after), $userId, Database::now()]);
    }

    /**
     * A question's changes, newest first, with who made them.
     *
     * @return list<array<string,mixed>> before and after decoded
     */
    public function forQuestion(int $examId, string $key): array
    {
        return array_map([self::class, 'decode'], $this->db->all(
            'SELECT h.*, u.username, u.display_name FROM correction_history h LEFT JOIN users u ON u.id = h.user_id
             WHERE h.exam_id = ? AND h.question_key = ? ORDER BY h.id DESC', [$examId, $key]));
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->one('SELECT * FROM correction_history WHERE id = ?', [$id]);
        return $row === null ? null : self::decode($row);
    }

    /** @return array<string,int> how many changes each question of the exam has */
    public function counts(int $examId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT question_key, COUNT(*) AS n FROM correction_history WHERE exam_id = ? GROUP BY question_key', [$examId]) as $row) {
            $out[(string) $row['question_key']] = (int) $row['n'];
        }
        return $out;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decode(array $row): array
    {
        foreach (['before_state', 'after_state'] as $col) {
            $v = $row[$col] === null ? null : json_decode((string) $row[$col], true);
            $row[$col] = is_array($v) ? $v : null;
        }
        return $row;
    }
}
