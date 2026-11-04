<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Problems with questions reported by members (question_reports), open until dealt with. */
final class QuestionReportRepository
{
    /** What a report can be about, as the form offers it. */
    public const KINDS = [
        'answer' => 'The answer is wrong',
        'wording' => 'The wording or a choice is wrong',
        'picture' => 'A picture is missing or broken',
        'other' => 'Something else',
    ];

    public function __construct(private Database $db)
    {
    }

    public function add(int $examId, string $key, int $userId, string $kind, string $body): int
    {
        $this->db->run('INSERT INTO question_reports (exam_id, question_key, user_id, kind, body, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$examId, $key, $userId, $kind, $body, Database::now()]);
        return $this->db->lastId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM question_reports WHERE id = ?', [$id]);
    }

    /**
     * The exam's open reports, oldest first, with who sent them.
     *
     * @return array<string,list<array<string,mixed>>> by question key
     */
    public function open(int $examId): array
    {
        $out = [];
        foreach ($this->db->all(
            "SELECT r.*, u.username, u.display_name FROM question_reports r LEFT JOIN users u ON u.id = r.user_id
             WHERE r.exam_id = ? AND r.status = 'open' ORDER BY r.id",
            [$examId]
        ) as $row) {
            $out[(string) $row['question_key']][] = $row;
        }
        return $out;
    }

    /** @return array<int,int> open reports per exam id */
    public function openCounts(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT exam_id, COUNT(*) AS n FROM question_reports WHERE status = 'open' GROUP BY exam_id") as $row) {
            $out[(int) $row['exam_id']] = (int) $row['n'];
        }
        return $out;
    }

    /** Mark every open report of a question dealt with. @return int how many */
    public function resolveQuestion(int $examId, string $key, ?int $userId): int
    {
        return $this->db->run("UPDATE question_reports SET status = 'resolved', resolved_by = ?, resolved_at = ?
            WHERE exam_id = ? AND question_key = ? AND status = 'open'", [$userId, Database::now(), $examId, $key])->rowCount();
    }
}
