<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Documents\QuestionView;

/**
 * Search across the whole library (question_index): each exam's questions as they are shown,
 * corrections included. The background check writes an exam's rows whenever it works that exam
 * out (IntegrityChecks), so the index follows new versions and corrected wording by itself.
 *
 * A search matches like the reader's: every word must appear, anywhere in the question or its
 * choices, ignoring case.
 */
final class SearchIndex
{
    /** At most this many questions are listed for one search. */
    public const LIMIT = 200;

    public function __construct(private Database $db)
    {
    }

    /**
     * Replace an exam's rows with these questions (normalized, corrections applied).
     *
     * @param list<array<string,mixed>> $questions
     */
    public function replace(int $examId, array $questions): void
    {
        $this->db->run('DELETE FROM question_index WHERE exam_id = ?', [$examId]);
        $seen = [];
        foreach (array_chunk($questions, 500) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $q) {
                $key = QuestionView::key($q);
                if (isset($seen[$key])) {
                    continue; // a dataset can hold a question twice; the first one is kept
                }
                $seen[$key] = true;
                $rows[] = '(?, ?, ?, ?)';
                array_push($params, $examId, $key, (int) ($q['number'] ?? 0), self::body($q));
            }
            if ($rows !== []) {
                $this->db->run('INSERT INTO question_index (exam_id, question_key, number, body) VALUES ' . implode(', ', $rows), $params);
            }
        }
    }

    public function forget(int $examId): void
    {
        $this->db->run('DELETE FROM question_index WHERE exam_id = ?', [$examId]);
    }

    /** The words of a search: up to six, of at least two letters each. @return list<string> */
    public static function words(string $query): array
    {
        $words = preg_split('/\s+/u', trim(mb_strtolower($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_slice(array_values(array_unique(array_filter($words, static fn(string $w): bool => mb_strlen($w) >= 2))), 0, 6);
    }

    /**
     * Questions containing every word, with their exams, in exam then question order.
     *
     * @return array{rows:list<array<string,mixed>>,more:bool}
     */
    public function search(string $query): array
    {
        $words = self::words($query);
        if ($words === []) {
            return ['rows' => [], 'more' => false];
        }
        $where = implode(' AND ', array_fill(0, count($words), 'i.body LIKE ?'));
        $params = array_map(static fn(string $w): string => '%' . addcslashes($w, '%_\\\\') . '%', $words);
        $rows = $this->db->all(
            "SELECT i.exam_id, i.question_key, i.number, i.body, e.code, e.name, p.slug AS provider_slug, p.name AS provider_name
             FROM question_index i JOIN exams e ON e.id = i.exam_id JOIN providers p ON p.id = e.provider_id
             WHERE $where ORDER BY p.slug, e.code, i.number LIMIT " . (self::LIMIT + 1),
            $params
        );
        return ['rows' => array_slice($rows, 0, self::LIMIT), 'more' => count($rows) > self::LIMIT];
    }

    /** How many exams have rows. */
    public function examCount(): int
    {
        return (int) $this->db->value('SELECT COUNT(DISTINCT exam_id) FROM question_index');
    }

    /**
     * A short piece of the text around the first word found, escaped, with every word marked.
     *
     * @param list<string> $words
     */
    public static function snippet(string $body, array $words, int $around = 90): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $body));
        $at = null;
        foreach ($words as $w) {
            $pos = mb_stripos($flat, $w);
            if ($pos !== false && ($at === null || $pos < $at)) {
                $at = $pos;
            }
        }
        $start = max(0, (int) $at - $around);
        $piece = mb_substr($flat, $start, $around * 2 + 40);
        $html = htmlspecialchars($piece, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        foreach ($words as $w) {
            $quoted = preg_quote(htmlspecialchars($w, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'), '/');
            $html = (string) preg_replace('/(' . $quoted . ')(?![^<]*>)/iu', '<mark>$1</mark>', $html);
        }
        return ($start > 0 ? '… ' : '') . $html . ($start + mb_strlen($piece) < mb_strlen($flat) ? ' …' : '');
    }

    /** @param array<string,mixed> $q */
    private static function body(array $q): string
    {
        $out = (string) ($q['text'] ?? '');
        foreach ((array) ($q['choices'] ?? []) as $c) {
            if (is_array($c)) {
                $out .= "\n" . (string) ($c['letter'] ?? '') . '. ' . (string) ($c['text'] ?? '');
            }
        }
        return $out;
    }
}
