<?php

declare(strict_types=1);

namespace App\Services\Documents;

/**
 * Read-side helpers over a canonical question record, shared by the Markdown, PDF and web
 * renderers so they present the same facts the same way.
 */
final class QuestionView
{
    /**
     * A question as every renderer should see it. Collections made before the 2026 page
     * layout was understood kept the choices inside the question text ("A." on one line,
     * its text on the next) and picked up the page's sign-in box ("Forgot my password").
     * The choices are split back out and that line is dropped, so old data shows properly.
     *
     * @param array<string,mixed> $q
     * @return array<string,mixed>
     */
    public static function normalize(array $q): array
    {
        $lines = preg_split('/\R/', (string) ($q['text'] ?? '')) ?: [];
        $lines = array_filter($lines, static fn(string $l) => strcasecmp(trim($l), 'Forgot my password') !== 0);
        $text = trim(implode("\n", $lines));
        if (array_filter((array) ($q['choices'] ?? []), 'is_array') === []) {
            [$stem, $choices] = self::splitChoices($text);
            if ($choices !== []) {
                $q['choices'] = $choices;
                $text = $stem;
            }
        }
        $q['text'] = $text;
        // No answer and no vote tally (older collections of the 2026 pages): count the
        // community vote from the comments' selected answers, as the collection tool now does.
        if (trim((string) ($q['answer'] ?? '')) === '' && array_filter((array) ($q['community_votes'] ?? []), 'is_array') === []) {
            $votes = self::votesFrom(self::comments($q));
            if ($votes !== []) {
                $q['community_votes'] = $votes;
                $q['votes_from_comments'] = true;
                $q['answer'] = $votes[0]['answer'];
                $q['answer_source'] = 'community';
            }
        }
        return $q;
    }

    /**
     * Lines "A." to "Z." (in order, with the text on the same or following lines) after the
     * stem become choices; at least two are needed. Mirrors the collection tool's parser.
     *
     * @return array{0:string,1:list<array{letter:string,text:string}>}
     */
    private static function splitChoices(string $text): array
    {
        $lines = explode("\n", $text);
        $start = null;
        foreach ($lines as $i => $l) {
            if (preg_match('/^A\.(\s|$)/', trim($l)) === 1) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return [$text, []];
        }
        $choices = [];
        $next = 'A';
        foreach (array_slice($lines, $start) as $l) {
            $l = trim($l);
            if (preg_match('/^([A-Z])\.\s*(.*)$/', $l, $m) === 1 && $m[1] === $next) {
                $choices[] = ['letter' => $m[1], 'text' => $m[2]];
                $next = chr(ord($next) + 1);
                continue;
            }
            if ($l === '' || $choices === []) {
                continue;
            }
            $last = count($choices) - 1;
            $choices[$last]['text'] = $choices[$last]['text'] === '' ? $l : $choices[$last]['text'] . "\n" . $l;
        }
        if (count($choices) < 2) {
            return [$text, []];
        }
        return [trim(implode("\n", array_slice($lines, 0, $start))), $choices];
    }

    /**
     * Question text as paragraphs: blank lines separate them, single line breaks (exhibit
     * output, configuration) are kept inside a paragraph.
     *
     * @return list<string> escaped HTML, one entry per paragraph
     */
    public static function paragraphs(string $text): array
    {
        $out = [];
        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $para) {
            if (trim($para) !== '') {
                $out[] = nl2br(htmlspecialchars(trim($para), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
            }
        }
        return $out;
    }

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
     * @return list<array{author:string,timestamp:string,upvotes:?int,content:string,selected:string,reply:bool,badge:string}>
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
            $out = self::legacyComments($text)
                ?? [['author' => '', 'timestamp' => '', 'upvotes' => null, 'content' => $text, 'selected' => '', 'reply' => false, 'badge' => '']];
        }
        return array_map(static fn(array $c) => $c + ['badge' => ''], $out);
    }

    /**
     * Comment text kept as one block by the older parser, in the page's own order:
     *   author / ["Highly Voted" | "Most Recent"] 1 year, 2 months ago / text, which may start
     *   with "Selected Answer: C" / "upvoted 3 times" / "Submit Cancel" (the reply form) / "..."
     * Null when the text does not follow that pattern, so it is shown as it is.
     *
     * @return list<array{author:string,timestamp:string,upvotes:?int,content:string,selected:string,reply:bool,badge:string}>|null
     */
    private static function legacyComments(string $text): ?array
    {
        $blocks = preg_split('/^\s*Submit\s+Cancel\s*$/mi', $text) ?: [];
        $out = [];
        $unread = 0;
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $block) ?: []),
                static fn(string $l) => $l !== '' && !in_array($l, ['...', '…', 'Reply', 'Report', 'Comment'], true)));
            if ($lines === []) {
                continue;
            }
            $at = null;
            foreach (array_slice($lines, 0, 4, true) as $i => $l) {
                if (preg_match('/\bago$/i', $l) === 1) {
                    $at = $i;
                    break;
                }
            }
            if ($at === null || $at === 0) {
                // A stray line or two (page furniture) is skipped; a real comment that does
                // not fit means the text is not in this format.
                $unread += count($lines) > 2 ? 1 : 0;
                continue;
            }
            $badge = '';
            $stamp = (string) preg_replace_callback('/^(Highly Voted|Most Recent)\s*/i', static function (array $m) use (&$badge): string {
                $badge = ucwords(strtolower($m[1]));
                return '';
            }, $lines[$at]);
            $upvotes = null;
            $body = [];
            foreach (array_slice($lines, $at + 1) as $l) {
                if (preg_match('/^upvoted (\d+) times?$/i', $l, $m) === 1) {
                    $upvotes = (int) $m[1];
                } elseif (!in_array(strtolower($l), ['highly voted', 'most recent'], true)) {
                    $body[] = $l;
                }
            }
            $content = implode("\n", $body);
            $selected = '';
            if (preg_match('/^Selected Answer:\s*([A-Z]{1,10})\b\s*/', $content, $m) === 1) {
                $selected = $m[1];
                $content = trim(substr($content, strlen($m[0])));
            }
            if ($content === '' && $selected === '') {
                continue;
            }
            $out[] = ['author' => $lines[$at - 1], 'timestamp' => trim($stamp), 'upvotes' => $upvotes ?? 0,
                'content' => $content, 'selected' => $selected, 'reply' => false, 'badge' => $badge];
        }
        return $out === [] || $unread > 0 ? null : $out;
    }

    /**
     * The community vote counted from the comments, as ExamTopics counts it: each comment's
     * selected answer once, plus once per upvote. Most votes first.
     *
     * @param list<array{selected:string,upvotes:?int}> $comments
     * @return list<array{answer:string,count:int,most_voted:bool}>
     */
    private static function votesFrom(array $comments): array
    {
        $tally = [];
        foreach ($comments as $c) {
            if ($c['selected'] !== '') {
                $tally[$c['selected']] = ($tally[$c['selected']] ?? 0) + 1 + max(0, (int) $c['upvotes']);
            }
        }
        arsort($tally);
        $votes = [];
        foreach ($tally as $answer => $count) {
            $votes[] = ['answer' => (string) $answer, 'count' => $count, 'most_voted' => $votes === []];
        }
        return $votes;
    }

    /** Only https URLs are rendered as images; anything else is shown as text. */
    public static function safeImageUrl(string $url): ?string
    {
        return preg_match('#^https://[^\s"\'<>]+$#i', $url) === 1 ? $url : null;
    }
}
