<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Documents\QuestionView;

/**
 * Finds the same question in another edition of an exam. ExamTopics often carries a question
 * over to the next edition word for word, or with small changes (a fixed typo, a renamed
 * service), so a question matches either exactly (the same wording and choices, ignoring case,
 * spacing and punctuation) or nearly (most of its words in common, the closest one winning).
 *
 * Answers are carried by the wording of their choices, never by letter alone: an edition can list
 * the choices in another order, and an answer moved by letter would then be wrong.
 */
final class EditionMatcher
{
    /** Words in common, of all the words of both, for a near match. */
    public const NEAR = 0.85;

    /** @var array<string,string> exact signature => key */
    private array $exact = [];
    /** @var array<string,array<string,true>> key => its words */
    private array $words = [];
    /** @var array<string,list<string>> word => keys of the questions that have it */
    private array $postings = [];
    /** @var array<string,array<string,mixed>> */
    private array $questions = [];

    /** @param list<array<string,mixed>> $questions an edition's questions, normalized */
    public function __construct(array $questions)
    {
        foreach ($questions as $q) {
            $key = QuestionView::key($q);
            if (isset($this->questions[$key])) {
                continue;
            }
            $this->questions[$key] = $q;
            $this->exact[self::signature($q)] ??= $key;
            $this->words[$key] = self::wordsOf($q);
            foreach ($this->words[$key] as $w => $_) {
                if (strlen((string) $w) >= 4) {
                    $this->postings[$w][] = $key;
                }
            }
        }
    }

    /**
     * The question of this edition that $q is, if any.
     *
     * @param array<string,mixed> $q
     * @return array{key:string,question:array<string,mixed>,exact:bool,score:float}|null
     */
    public function match(array $q): ?array
    {
        $exact = $this->exact[self::signature($q)] ?? null;
        if ($exact !== null) {
            return ['key' => $exact, 'question' => $this->questions[$exact], 'exact' => true, 'score' => 1.0];
        }
        $mine = self::wordsOf($q);
        // Candidates share a long word; the rarest words narrow it down quickly.
        $shared = [];
        $long = array_values(array_filter(array_map('strval', array_keys($mine)), fn(string $w): bool => strlen($w) >= 4 && isset($this->postings[$w])));
        usort($long, fn(string $a, string $b): int => count($this->postings[$a]) <=> count($this->postings[$b]));
        foreach (array_slice($long, 0, 8) as $w) {
            foreach ($this->postings[$w] as $key) {
                $shared[$key] = ($shared[$key] ?? 0) + 1;
            }
        }
        arsort($shared);
        $best = null;
        foreach (array_slice(array_map('strval', array_keys($shared)), 0, 25) as $key) {
            $theirs = $this->words[$key];
            $common = count(array_intersect_key($mine, $theirs));
            $score = $common / max(1, count($mine) + count($theirs) - $common);
            if ($score >= self::NEAR && ($best === null || $score > $best['score'])) {
                $best = ['key' => (string) $key, 'question' => $this->questions[$key], 'exact' => false, 'score' => $score];
            }
        }
        return $best;
    }

    /**
     * Answer letters of $from, as letters of $to: each letter's choice found by its wording.
     * Null when any of them is not among $to's choices.
     *
     * @param array<string,mixed> $from @param array<string,mixed> $to
     */
    public static function mapLetters(string $letters, array $from, array $to): ?string
    {
        $theirs = [];
        foreach ((array) ($to['choices'] ?? []) as $c) {
            if (is_array($c) && (string) ($c['letter'] ?? '') !== '') {
                $theirs[self::normal((string) ($c['text'] ?? ''))] ??= (string) $c['letter'];
            }
        }
        $mine = [];
        foreach ((array) ($from['choices'] ?? []) as $c) {
            if (is_array($c)) {
                $mine[(string) ($c['letter'] ?? '')] = self::normal((string) ($c['text'] ?? ''));
            }
        }
        $out = '';
        foreach (str_split($letters) as $l) {
            $text = $mine[$l] ?? null;
            if ($text === null || $text === '' || !isset($theirs[$text])) {
                return null;
            }
            $out .= $theirs[$text];
        }
        return QuestionView::letters($out);
    }

    /** Whether two questions read the same, choices included (ignoring case, spacing and punctuation). */
    public static function same(array $a, array $b): bool
    {
        return self::signature($a) === self::signature($b);
    }

    /** Lower case, words only. */
    public static function normal(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    /** @param array<string,mixed> $q */
    private static function signature(array $q): string
    {
        $parts = [self::normal((string) ($q['text'] ?? ''))];
        foreach ((array) ($q['choices'] ?? []) as $c) {
            if (is_array($c)) {
                $parts[] = (string) ($c['letter'] ?? '') . ' ' . self::normal((string) ($c['text'] ?? ''));
            }
        }
        return md5(implode("\n", $parts));
    }

    /** @param array<string,mixed> $q @return array<string,true> */
    private static function wordsOf(array $q): array
    {
        $text = (string) ($q['text'] ?? '');
        foreach ((array) ($q['choices'] ?? []) as $c) {
            if (is_array($c)) {
                $text .= ' ' . (string) ($c['text'] ?? '');
            }
        }
        $words = preg_split('/\s+/', self::normal($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_fill_keys($words, true);
    }
}
