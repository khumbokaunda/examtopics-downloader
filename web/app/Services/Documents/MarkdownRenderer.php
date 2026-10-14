<?php

declare(strict_types=1);

namespace App\Services\Documents;

/**
 * Markdown from the canonical dataset. Source text is escaped so a question that contains
 * "#", "*", "|", "<script>" or a leading "1." cannot change the document's structure.
 */
final class MarkdownRenderer
{
    /**
     * @param array<string,mixed> $dataset
     * @param array{provider:string,exam:string,exam_code:string,version:string,collected_at:string,status:string} $meta
     */
    public function render(array $dataset, array $meta, Variant $variant): string
    {
        $questions = (array) ($dataset['questions'] ?? []);
        $n = count($questions);
        $out = [];
        $out[] = '# ' . self::inline($meta['provider'] . ' ' . $meta['exam'] . ': ' . $variant->label());
        $out[] = '';
        $out[] = '| | |';
        $out[] = '|---|---|';
        $out[] = '| Provider | ' . self::cell($meta['provider']) . ' |';
        $out[] = '| Exam | ' . self::cell($meta['exam'] . ($meta['exam'] !== $meta['exam_code'] ? ' (' . $meta['exam_code'] . ')' : '')) . ' |';
        $out[] = '| Collected | ' . self::cell($meta['collected_at']) . ' |';
        $out[] = '| Questions | ' . $n . ($meta['status'] === 'partial' ? ' (partial collection)' : '') . ' |';
        $out[] = '| Source | ' . self::cell(($dataset['source'] ?? '') === 'github-cache' ? 'Public cached ExamTopics dataset' : 'ExamTopics discussion pages') . ' |';
        $out[] = '| Version | ' . self::cell($meta['version']) . ' |';
        $out[] = '';
        if ($meta['status'] === 'partial') {
            $out[] = '> **Partial collection:** some questions could not be fetched, so this document is incomplete.';
            $out[] = '';
        }
        if (!$variant->showAnswers()) {
            $out[] = $variant->answerKey()
                ? '> Practice mode: answers are listed in the answer key at the end.'
                : '> Practice mode: answers, explanations and discussions are omitted.';
            $out[] = '';
        }

        if ($n > 20) {
            $out[] = '## Contents';
            $out[] = '';
            for ($i = 1; $i <= $n; $i += 50) {
                $end = min($n, $i + 49);
                $out[] = '- [Questions ' . $i . '–' . $end . '](#question-' . $i . ')';
            }
            if ($variant->answerKey()) {
                $out[] = '- [Answer key](#answer-key)';
            }
            $out[] = '';
        }

        foreach ($questions as $q) {
            if (!is_array($q)) {
                continue;
            }
            $out[] = '---';
            $out[] = '';
            $out[] = '## Question ' . (int) $q['number'];
            $out[] = '';
            $meta2 = QuestionView::meta($q);
            if ($meta2 !== '') {
                $out[] = '*' . self::inline($meta2) . '*';
                $out[] = '';
            }
            $images = QuestionView::questionImages($q);
            foreach (QuestionView::questionSegments($q) as $seg) {
                $out[] = isset($seg['text']) ? self::block($seg['text']) : self::image($images[$seg['image']], 'Question image ' . ($seg['image'] + 1));
                $out[] = '';
            }
            foreach ((array) ($q['choices'] ?? []) as $c) {
                if (is_array($c)) {
                    $out[] = '- **' . self::inline((string) ($c['letter'] ?? '')) . '.** ' . self::inline((string) ($c['text'] ?? ''));
                    foreach ((array) ($c['images'] ?? []) as $img) {
                        $out[] = '  ' . self::image((string) $img, 'Choice ' . (string) ($c['letter'] ?? ''));
                    }
                }
            }
            if (($q['choices'] ?? []) !== []) {
                $out[] = '';
            }

            if ($variant->showAnswers()) {
                $answer = QuestionView::answerLine($q);
                $out[] = $answer !== null ? '**Answer:** ' . self::inline($answer) : '**Answer:** not available';
                $votes = QuestionView::votesLine($q);
                if ($votes !== null) {
                    $out[] = '';
                    $out[] = 'Community votes: ' . self::inline($votes);
                }
                if (($q['edit']['note'] ?? '') !== '') {
                    $out[] = '';
                    $out[] = '**Your note:** ' . self::inline((string) $q['edit']['note']);
                }
                $out[] = '';
                foreach ((array) ($q['answer_images'] ?? []) as $i => $img) {
                    $out[] = self::image((string) $img, 'Answer image ' . ($i + 1));
                    $out[] = '';
                }
                if (trim((string) ($q['explanation'] ?? '')) !== '') {
                    $out[] = '**Explanation**';
                    $out[] = '';
                    $out[] = self::block((string) $q['explanation']);
                    $out[] = '';
                }
                if ($variant->showComments()) {
                    $comments = QuestionView::comments($q);
                    if ($comments !== []) {
                        $out[] = '### Discussion';
                        $out[] = '';
                        foreach ($comments as $c) {
                            $head = array_filter([
                                $c['author'] !== '' ? '**' . self::inline($c['author']) . '**' : null,
                                $c['timestamp'] !== '' ? self::inline($c['timestamp']) : null,
                                $c['selected'] !== '' ? 'selected ' . $c['selected'] : null,
                                $c['upvotes'] !== null ? $c['upvotes'] . ' upvote' . ($c['upvotes'] === 1 ? '' : 's') : null,
                            ]);
                            $quote = $c['reply'] ? '> > ' : '> ';
                            if ($head !== []) {
                                $out[] = $quote . implode(' · ', $head) . '  ';
                            }
                            foreach (explode("\n", self::block($c['content'])) as $line) {
                                $out[] = rtrim($quote . $line);
                            }
                            $out[] = '';
                        }
                    }
                }
            }
        }

        if ($variant->answerKey()) {
            $out[] = '---';
            $out[] = '';
            $out[] = '## Answer key';
            $out[] = '';
            $out[] = '| Question | Answer |';
            $out[] = '|---:|---|';
            foreach ($questions as $q) {
                if (is_array($q)) {
                    $a = trim((string) ($q['answer'] ?? ''));
                    $out[] = '| ' . (int) $q['number'] . ' | ' . ($a === '' ? 'n/a' : self::cell($a)) . ' |';
                }
            }
            $out[] = '';
        }
        return implode("\n", $out) . "\n";
    }

    /** Escape inline text (no line breaks). */
    public static function inline(string $s): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);
        return preg_replace('/([\\\\`*_\[\]<>|~])/', '\\\\$1', $s) ?? $s;
    }

    /** Escape a multi-line block, keeping its line breaks as hard breaks. */
    public static function block(string $s): string
    {
        $lines = [];
        foreach (explode("\n", str_replace("\r", '', trim($s))) as $line) {
            $line = self::inline($line);
            // Neutralise line starts Markdown would treat as structure.
            if (preg_match('/^(\s*)([#+\-=]|\d+[.)])/', $line, $m) === 1) {
                $line = $m[1] . '\\' . ltrim($line);
            }
            $lines[] = $line;
        }
        // Two trailing spaces = hard line break; blank lines stay paragraph breaks.
        $out = [];
        foreach ($lines as $i => $line) {
            $next = $lines[$i + 1] ?? null;
            $out[] = ($line !== '' && $next !== null && $next !== '') ? $line . '  ' : $line;
        }
        return implode("\n", $out);
    }

    private static function cell(string $s): string
    {
        return self::inline($s);
    }

    private static function image(string $url, string $alt): string
    {
        $safe = QuestionView::safeImageUrl($url);
        if ($safe === null) {
            return '*[' . self::inline($alt) . ': ' . self::inline($url) . ']*';
        }
        return '![' . self::inline($alt) . '](<' . str_replace(['<', '>', ' '], ['%3C', '%3E', '%20'], $safe) . '>)';
    }
}
