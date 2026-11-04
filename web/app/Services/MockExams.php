<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use App\Services\Documents\QuestionView;

/**
 * Timed mock exams: a random set of an exam's questions, answered without seeing any answers,
 * then graded on the server against the answers the reader shows (corrections included).
 *
 * Only questions whose answer is a set of letters can be graded, so only those are asked. Picks
 * are saved as they are made; the time limit is the server's (ends_at), with a short grace for
 * the page's own submit when time runs out. An attempt left open past its time is finished
 * with what was saved the next time it is opened. Finished answers count towards the person's
 * study progress, like answers checked in practice mode.
 */
final class MockExams
{
    /** Seconds after the time limit in which the page's own submit is still accepted. */
    public const GRACE = 60;
    public const SIZES = [10, 20, 30, 40, 50, 65, 100];
    public const MAX_MINUTES = 600;

    public function __construct(private Database $db, private Corrections $corrections, private StudyProgress $progress)
    {
    }

    /** @param list<array<string,mixed>> $questions with corrections applied @return list<array<string,mixed>> the ones that can be graded */
    public static function gradable(array $questions): array
    {
        return array_values(array_filter($questions, static fn(array $q): bool => self::answerOf($q) !== ''
            && count(QuestionView::choiceLetters($q)) >= 2));
    }

    /** The answer letters, sorted ("BE"), or '' when the answer is not a set of letters. */
    public static function answerOf(array $q): string
    {
        $a = trim((string) ($q['answer'] ?? ''));
        return preg_match('/^[A-Z]+$/', $a) === 1 ? QuestionView::letters($a) : '';
    }

    /**
     * Start an attempt with up to $count of these questions, in random order.
     *
     * @param list<string> $keys question keys to choose from
     * @return array<string,mixed> the mock_exams row
     */
    public function start(int $userId, int $examId, int $versionId, array $keys, int $count, int $minutes): array
    {
        shuffle($keys); // not for secrets, but plenty for choosing questions
        $keys = array_slice(array_values($keys), 0, max(1, $count));
        $minutes = max(0, min(self::MAX_MINUTES, $minutes));
        $now = time();
        $uuid = Ids::uuid();
        $this->db->run(
            'INSERT INTO mock_exams (uuid, user_id, exam_id, version_id, question_keys, minutes, started_at, ends_at, total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uuid, $userId, $examId, $versionId, json_encode($keys), $minutes, gmdate('Y-m-d H:i:s', $now),
                $minutes > 0 ? gmdate('Y-m-d H:i:s', $now + $minutes * 60) : null, count($keys)]
        );
        return (array) $this->find($uuid);
    }

    /** @return array<string,mixed>|null with keys, picks, flags and results decoded */
    public function find(string $uuid): ?array
    {
        if (!Ids::isUuid($uuid)) {
            return null;
        }
        $row = $this->db->one('SELECT * FROM mock_exams WHERE uuid = ?', [$uuid]);
        return $row === null ? null : self::decode($row);
    }

    /** The person's unfinished attempt at an exam, if any. @return array<string,mixed>|null */
    public function open(int $userId, int $examId): ?array
    {
        $row = $this->db->one('SELECT * FROM mock_exams WHERE user_id = ? AND exam_id = ? AND finished_at IS NULL ORDER BY id DESC LIMIT 1', [$userId, $examId]);
        return $row === null ? null : self::decode($row);
    }

    /**
     * The person's finished attempts, newest first, with their exams: of one exam, or of all.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $userId, ?int $examId = null, int $limit = 20): array
    {
        $rows = $this->db->all(
            'SELECT m.*, e.code, e.name, p.slug AS provider_slug, p.name AS provider_name FROM mock_exams m
             JOIN exams e ON e.id = m.exam_id JOIN providers p ON p.id = e.provider_id
             WHERE m.user_id = ? AND m.finished_at IS NOT NULL' . ($examId !== null ? ' AND m.exam_id = ?' : '') . '
             ORDER BY m.finished_at DESC, m.id DESC LIMIT ' . max(1, $limit),
            $examId !== null ? [$userId, $examId] : [$userId]
        );
        return array_map([self::class, 'decode'], $rows);
    }

    /** Seconds left, or null when untimed. Never below 0. */
    public static function secondsLeft(array $mock): ?int
    {
        return $mock['ends_at'] === null ? null : max(0, (int) strtotime($mock['ends_at'] . ' UTC') - time());
    }

    /** Whether the time is up (past the grace too, when $grace is set). */
    public static function timeUp(array $mock, bool $grace = false): bool
    {
        return $mock['ends_at'] !== null && time() > (int) strtotime($mock['ends_at'] . ' UTC') + ($grace ? self::GRACE : 0);
    }

    /** Save one question's pick and whether it is marked to look at again. */
    public function save(array $mock, string $key, string $letters, bool $flagged): void
    {
        $picks = $mock['picks'];
        $flags = array_values(array_diff($mock['flags'], [$key]));
        $letters = QuestionView::letters($letters);
        if ($letters === '') {
            unset($picks[$key]);
        } else {
            $picks[$key] = $letters;
        }
        if ($flagged) {
            $flags[] = $key;
        }
        $this->db->run('UPDATE mock_exams SET picks = ?, flags = ? WHERE id = ? AND finished_at IS NULL',
            [json_encode((object) $picks), json_encode($flags), (int) $mock['id']]);
    }

    /**
     * Grade and close an attempt. $picks replaces what was saved (the page's own submit); null
     * grades what was saved. Answered questions are added to the person's study progress.
     *
     * @param array<string,string>|null $picks question key => letters
     * @param list<array<string,mixed>> $questions the version's questions, corrections applied
     * @return array<string,mixed> the finished attempt
     */
    public function finish(array $mock, ?array $picks, array $questions, string $day): array
    {
        if ($mock['finished_at'] !== null) {
            return $mock;
        }
        $picks ??= $mock['picks'];
        $byKey = [];
        foreach ($questions as $q) {
            $byKey[QuestionView::key($q)] = $q;
        }
        $results = [];
        $right = 0;
        foreach ($mock['keys'] as $key) {
            $q = $byKey[$key] ?? null;
            $answer = $q !== null ? self::answerOf($q) : '';
            $picked = QuestionView::letters((string) ($picks[$key] ?? ''));
            $ok = $picked !== '' && $answer !== '' && $picked === $answer;
            $right += $ok ? 1 : 0;
            $results[$key] = ['number' => $q !== null ? (int) $q['number'] : null, 'picked' => $picked, 'answer' => $answer, 'right' => $ok];
            if ($q !== null && $picked !== '' && $answer !== '') {
                $this->progress->record((int) $mock['user_id'], (int) $mock['exam_id'], $key, (int) $q['number'], $picked, $ok, $day);
            }
        }
        $this->db->run('UPDATE mock_exams SET picks = ?, finished_at = ?, right_count = ?, results = ? WHERE id = ? AND finished_at IS NULL',
            [json_encode((object) $picks), Database::now(), $right, json_encode((object) $results), (int) $mock['id']]);
        return (array) $this->find((string) $mock['uuid']);
    }

    /** Forget an unfinished attempt (the person gave up without grading it). */
    public function discard(array $mock): void
    {
        $this->db->run('DELETE FROM mock_exams WHERE id = ? AND finished_at IS NULL', [(int) $mock['id']]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decode(array $row): array
    {
        $json = static fn(mixed $v): array => is_array($d = json_decode((string) ($v ?? ''), true)) ? $d : [];
        $row['keys'] = array_values(array_map('strval', $json($row['question_keys'])));
        $row['picks'] = array_map('strval', $json($row['picks']));
        $row['flags'] = array_values(array_map('strval', $json($row['flags'])));
        $row['results'] = $json($row['results']);
        return $row;
    }
}
