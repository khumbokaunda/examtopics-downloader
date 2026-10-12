<?php

declare(strict_types=1);

namespace App\Services\Documents;

/**
 * Read-side helpers over a canonical question record, shared by the Markdown, PDF and web
 * renderers so they present the same facts the same way.
 */
final class QuestionView
{
    /** @param array<string,mixed> $q */
    public static function answerLine(array $q): ?string
    {
        $a = trim((string) ($q['answer'] ?? ''));
        if ($a === '') {
            return null;
        }
        $source = match ($q['answer_source'] ?? '') {
            'suggested' => 'suggested by ExamTopics',
            'community' => 'most-voted community answer; ExamTopics gave no suggested answer',
            default => '',
        };
        return $a . ($source !== '' ? ' (' . $source . ')' : '');
    }

    /** @param array<string,mixed> $q */
    public static function votesLine(array $q): ?string
    {
        $votes = array_filter((array) ($q['community_votes'] ?? []), 'is_array');
        if ($votes === []) {
            return null;
        }
        $total = array_sum(array_map(static fn($v) => (int) ($v['count'] ?? 0), $votes));
        $parts = [];
        foreach ($votes as $v) {
            $n = (int) ($v['count'] ?? 0);
            $pct = $total > 0 ? ' (' . round($n * 100 / $total) . '%)' : '';
            $parts[] = (string) ($v['answer'] ?? '?') . ': ' . $n . $pct . (!empty($v['most_voted']) ? ' – most voted' : '');
        }
        // On the 2026 pages ExamTopics shows no tally: the tool counts the comments' selected
        // answers (once each, plus once per upvote), which is how ExamTopics counts votes.
        return implode(', ', $parts) . (!empty($q['votes_from_comments']) ? ' (counted from the comments)' : '');
    }

    /** @param array<string,mixed> $q */
    public static function meta(array $q): string
    {
        $bits = [];
        if (!empty($q['topic'])) {
            $bits[] = 'Topic ' . (int) $q['topic'];
        }
        if (!empty($q['question_number'])) {
            $bits[] = 'Question #' . (int) $q['question_number'];
        }
        return implode(' · ', $bits);
    }

    /**
     * Comments as uniform records: structured comments when present, otherwise one block
     * holding the unstructured comment text.
     *
     * @param array<string,mixed> $q
     * @return list<array{author:string,timestamp:string,upvotes:?int,content:string,selected:string,reply:bool}>
     */
    public static function comments(array $q): array
    {
        $out = [];
        foreach ((array) ($q['comments'] ?? []) as $c) {
            if (is_array($c) && trim((string) ($c['content'] ?? '')) !== '') {
                $selected = (string) ($c['selected_answer'] ?? '');
                $out[] = [
                    'author' => (string) ($c['author'] ?? ''),
                    'timestamp' => (string) ($c['timestamp'] ?? ''),
                    'upvotes' => isset($c['upvotes']) ? (int) $c['upvotes'] : null,
                    'content' => (string) $c['content'],
                    'selected' => preg_match('/^[A-Z]{1,10}$/', $selected) === 1 ? $selected : '',
                    'reply' => (string) ($c['parent_id'] ?? '') !== '',
                ];
            }
        }
        $text = trim((string) ($q['comments_text'] ?? ''));
        if ($out === [] && $text !== '') {
            $out[] = ['author' => '', 'timestamp' => '', 'upvotes' => null, 'content' => $text, 'selected' => '', 'reply' => false];
        }
        return $out;
    }

    /** Only https URLs are rendered as images; anything else is shown as text. */
    public static function safeImageUrl(string $url): ?string
    {
        return preg_match('#^https://[^\s"\'<>]+$#i', $url) === 1 ? $url : null;
    }
}
