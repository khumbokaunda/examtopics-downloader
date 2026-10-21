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
 *
 * Each day is logged too (study_days, in the person's own timezone): answers checked, how many
 * were right, and pages opened. A day with any of those counts towards the streak.
 */
final class StudyProgress
{
    public function __construct(private Database $db)
    {
    }

    /** Record a checked answer. $picked is the letters chosen, e.g. "BE"; $day is the person's today (Y-m-d). */
    public function record(int $userId, int $examId, string $key, int $number, string $picked, bool $correct, string $day): void
    {
        $now = Database::now();
        $this->db->run(
            'INSERT INTO study_answers (user_id, exam_id, question_key, number, picked, correct, attempts, wrong_count, first_at, last_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE number = VALUES(number), picked = VALUES(picked), correct = VALUES(correct),
                 attempts = attempts + 1, wrong_count = wrong_count + VALUES(wrong_count), last_at = VALUES(last_at)',
            [$userId, $examId, $key, $number, $picked, $correct ? 1 : 0, $correct ? 0 : 1, $now, $now]
        );
        $this->db->run(
            'INSERT INTO study_days (user_id, day, answered, correct, pages) VALUES (?, ?, 1, ?, 0)
             ON DUPLICATE KEY UPDATE answered = answered + 1, correct = correct + VALUES(correct)',
            [$userId, $day, $correct ? 1 : 0]
        );
    }

    /** Remember the page a person opened, to continue from, and count it on their day ($day: Y-m-d). */
    public function remember(int $userId, int $examId, string $mode, int $page, string $day): void
    {
        $this->db->run(
            'INSERT INTO study_places (user_id, exam_id, mode, page, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), page = VALUES(page), updated_at = VALUES(updated_at)',
            [$userId, $examId, $mode === 'practice' ? 'practice' : 'study', max(1, $page), Database::now()]
        );
        $this->db->run('INSERT INTO study_days (user_id, day, answered, correct, pages) VALUES (?, ?, 0, 0, 1)
                        ON DUPLICATE KEY UPDATE pages = pages + 1', [$userId, $day]);
    }

    /**
     * One person's days from $from to $to (Y-m-d, both included), every day present, oldest
     * first; a day with nothing done is zeros.
     *
     * @return array<string,array{answered:int,correct:int,pages:int}>
     */
    public function days(int $userId, string $from, string $to): array
    {
        $out = [];
        for ($d = new \DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $out[$d->format('Y-m-d')] = ['answered' => 0, 'correct' => 0, 'pages' => 0];
        }
        foreach ($this->db->all('SELECT day, answered, correct, pages FROM study_days WHERE user_id = ? AND day BETWEEN ? AND ?', [$userId, $from, $to]) as $r) {
            $out[(string) $r['day']] = ['answered' => (int) $r['answered'], 'correct' => (int) $r['correct'], 'pages' => (int) $r['pages']];
        }
        return $out;
    }

    /**
     * The streak: days in a row with something studied. It is still alive when the last day
     * was yesterday (there is time left today); it is broken by a whole day missed.
     *
     * @return array{current:int,best:int,today:bool}
     */
    public function streak(int $userId, string $today): array
    {
        $days = array_map('strval', array_column($this->db->all(
            'SELECT day FROM study_days WHERE user_id = ? AND (answered > 0 OR pages > 0) AND day <= ? ORDER BY day', [$userId, $today]), 'day'));
        return self::countStreak($days, $today);
    }

    /**
     * @param list<string> $days active days, Y-m-d, oldest first
     * @return array{current:int,best:int,today:bool}
     */
    public static function countStreak(array $days, string $today): array
    {
        $best = 0;
        $run = 0;
        $previous = null;
        foreach ($days as $day) {
            $run = $previous !== null && (new \DateTimeImmutable($previous))->modify('+1 day')->format('Y-m-d') === $day ? $run + 1 : 1;
            $best = max($best, $run);
            $previous = $day;
        }
        $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $current = $previous === $today || $previous === $yesterday ? $run : 0;
        return ['current' => $current, 'best' => $best, 'today' => $previous === $today];
    }

    /**
     * Who studied since $from (Y-m-d), most answers first, for administrators.
     *
     * @return list<array<string,mixed>> user rows with answered, correct and days (active days)
     */
    public function activeSince(string $from, int $limit = 5): array
    {
        return $this->db->all(
            'SELECT u.id, u.username, u.display_name, SUM(d.answered) AS answered, SUM(d.correct) AS correct, COUNT(*) AS days
             FROM study_days d JOIN users u ON u.id = d.user_id
             WHERE d.day >= ? AND (d.answered > 0 OR d.pages > 0)
             GROUP BY u.id, u.username, u.display_name
             ORDER BY answered DESC, days DESC, u.username LIMIT ' . max(1, min(100, $limit)), [$from]);
    }

    /** @return array<string,true> the providers (slugs) of the exams a person studied or saved */
    public function providerSlugs(int $userId): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT DISTINCT p.slug FROM exams e JOIN providers p ON p.id = e.provider_id
             WHERE e.id IN (SELECT exam_id FROM study_places WHERE user_id = ?
                            UNION SELECT exam_id FROM study_answers WHERE user_id = ?
                            UNION SELECT exam_id FROM saved_exams WHERE user_id = ?)', [$userId, $userId, $userId]) as $r) {
            $out[(string) $r['slug']] = true;
        }
        return $out;
    }

    /** How many people studied since $from (Y-m-d). */
    public function activeCount(string $from): int
    {
        return (int) $this->db->value('SELECT COUNT(DISTINCT user_id) FROM study_days WHERE day >= ? AND (answered > 0 OR pages > 0)', [$from]);
    }

    /**
     * Exams with mistakes to go over, most first.
     *
     * @return array<int,int> exam id => mistakes
     */
    public function mistakeCounts(int $userId, int $limit = 4): array
    {
        $out = [];
        foreach ($this->db->all('SELECT exam_id, COUNT(*) AS n FROM study_answers WHERE user_id = ? AND correct = 0
                                 GROUP BY exam_id ORDER BY n DESC, MAX(last_at) DESC LIMIT ' . max(1, min(50, $limit)), [$userId]) as $r) {
            $out[(int) $r['exam_id']] = (int) $r['n'];
        }
        return $out;
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
