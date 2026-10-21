<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Each person's study progress: the answers they checked in practice mode (graded on the
 * server, the latest one per question kept with a count of attempts and wrong answers), the
 * page they last opened in each exam, and the exams they saved to their own shelf.
 *
 * Questions are identified by QuestionView::key, so progress carries over when an exam is
 * collected again. "Mistakes" are the questions whose latest answer was wrong: answering one
 * right takes it off the list.
 */
final class StudyProgress
{
    public function __construct(private Database $db)
    {
    }

    /** Record a checked answer. $picked is the letters chosen, e.g. "BE". */
    public function record(int $userId, int $examId, string $key, int $number, string $picked, bool $correct): void
    {
        $now = Database::now();
        $this->db->run(
            'INSERT INTO study_answers (user_id, exam_id, question_key, number, picked, correct, attempts, wrong_count, first_at, last_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE number = VALUES(number), picked = VALUES(picked), correct = VALUES(correct),
                 attempts = attempts + 1, wrong_count = wrong_count + VALUES(wrong_count), last_at = VALUES(last_at)',
            [$userId, $examId, $key, $number, $picked, $correct ? 1 : 0, $correct ? 0 : 1, $now, $now]
        );
    }

    /** Remember the page a person opened, to continue from. */
    public function remember(int $userId, int $examId, string $mode, int $page): void
    {
        $this->db->run(
            'INSERT INTO study_places (user_id, exam_id, mode, page, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), page = VALUES(page), updated_at = VALUES(updated_at)',
            [$userId, $examId, $mode === 'practice' ? 'practice' : 'study', max(1, $page), Database::now()]
        );
    }

    /**
     * Totals per exam for one person.
     *
     * @return array<int,array{answered:int,correct:int,mistakes:int,last_at:?string}> keyed by exam id
     */
    public function byExam(int $userId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT exam_id, COUNT(*) AS answered, SUM(correct) AS correct, MAX(last_at) AS last_at
                                 FROM study_answers WHERE user_id = ? GROUP BY exam_id', [$userId]) as $r) {
            $out[(int) $r['exam_id']] = ['answered' => (int) $r['answered'], 'correct' => (int) $r['correct'],
                'mistakes' => (int) $r['answered'] - (int) $r['correct'], 'last_at' => $r['last_at']];
        }
        return $out;
    }

    /** @return array{answered:int,correct:int,mistakes:int,last_at:?string} one person's totals for one exam */
    public function forExam(int $userId, int $examId): array
    {
        return $this->byExam($userId)[$examId] ?? ['answered' => 0, 'correct' => 0, 'mistakes' => 0, 'last_at' => null];
    }

    /**
     * The exams a person studied most recently, with where they were.
     *
     * @return list<array{exam_id:int,mode:string,page:int,updated_at:string}>
     */
    public function recent(int $userId, int $limit = 6): array
    {
        return array_map(static fn(array $r): array => ['exam_id' => (int) $r['exam_id'], 'mode' => (string) $r['mode'],
            'page' => (int) $r['page'], 'updated_at' => (string) $r['updated_at']],
            $this->db->all('SELECT exam_id, mode, page, updated_at FROM study_places WHERE user_id = ?
                            ORDER BY updated_at DESC, exam_id DESC LIMIT ' . max(1, min(50, $limit)), [$userId]));
    }

    /** @return array{mode:string,page:int,updated_at:string}|null */
    public function placeOf(int $userId, int $examId): ?array
    {
        $r = $this->db->one('SELECT mode, page, updated_at FROM study_places WHERE user_id = ? AND exam_id = ?', [$userId, $examId]);
        return $r === null ? null : ['mode' => (string) $r['mode'], 'page' => (int) $r['page'], 'updated_at' => (string) $r['updated_at']];
    }

    /** @return list<string> the keys of the questions whose latest answer was wrong */
    public function mistakes(int $userId, int $examId): array
    {
        return array_map('strval', array_column($this->db->all(
            'SELECT question_key FROM study_answers WHERE user_id = ? AND exam_id = ? AND correct = 0', [$userId, $examId]), 'question_key'));
    }

    /** Forget a person's answers and place in one exam. Returns how many answers were removed. */
    public function reset(int $userId, int $examId): int
    {
        $n = $this->db->run('DELETE FROM study_answers WHERE user_id = ? AND exam_id = ?', [$userId, $examId])->rowCount();
        $this->db->run('DELETE FROM study_places WHERE user_id = ? AND exam_id = ?', [$userId, $examId]);
        return $n;
    }

    /** Save an exam to the person's shelf, or take it off. Returns whether it is now saved. */
    public function toggleSaved(int $userId, int $examId): bool
    {
        if ($this->db->run('DELETE FROM saved_exams WHERE user_id = ? AND exam_id = ?', [$userId, $examId])->rowCount() > 0) {
            return false;
        }
        $this->db->run('INSERT IGNORE INTO saved_exams (user_id, exam_id, saved_at) VALUES (?, ?, ?)', [$userId, $examId, Database::now()]);
        return true;
    }

    /** @return array<int,string> exam id => when it was saved, newest first */
    public function saved(int $userId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT exam_id, saved_at FROM saved_exams WHERE user_id = ? ORDER BY saved_at DESC, exam_id DESC', [$userId]) as $r) {
            $out[(int) $r['exam_id']] = (string) $r['saved_at'];
        }
        return $out;
    }

    /**
     * Everyone's totals, for administrators: every account, those who studied most recently
     * first (last_at is null for someone who never has).
     *
     * @return list<array<string,mixed>> user rows with exams, answered, correct and last_at
     */
    public function people(): array
    {
        return $this->db->all(
            'SELECT u.id, u.username, u.display_name, u.role, u.status,
                    COALESCE(a.exams, 0) AS exams, COALESCE(a.answered, 0) AS answered, COALESCE(a.correct, 0) AS correct,
                    NULLIF(GREATEST(COALESCE(a.last_at, \'1000-01-01 00:00:00\'), COALESCE(p.last_at, \'1000-01-01 00:00:00\')),
                           \'1000-01-01 00:00:00\') AS last_at
             FROM users u
             LEFT JOIN (SELECT user_id, COUNT(DISTINCT exam_id) AS exams, COUNT(*) AS answered, SUM(correct) AS correct, MAX(last_at) AS last_at
                        FROM study_answers GROUP BY user_id) a ON a.user_id = u.id
             LEFT JOIN (SELECT user_id, MAX(updated_at) AS last_at FROM study_places GROUP BY user_id) p ON p.user_id = u.id
             ORDER BY last_at DESC, u.username'
        );
    }

    /**
     * One line about a person's progress in an exam, as the reader shows it.
     *
     * @param array{answered:int,correct:int} $totals
     */
    public static function line(array $totals, int $questions): string
    {
        if ($totals['answered'] === 0) {
            return 'This exam: none answered yet';
        }
        $answered = $questions > 0 ? min($totals['answered'], $questions) : $totals['answered'];
        return 'This exam: ' . number_format($answered) . ($questions > 0 ? ' of ' . number_format($questions) : '') . ' answered, '
            . self::percent($totals['correct'], $totals['answered']) . '% right';
    }

    /** A percentage, rounded, or null when nothing was answered. */
    public static function percent(int $part, int $whole): ?int
    {
        return $whole > 0 ? (int) round(100 * min($part, $whole) / $whole) : null;
    }
}
