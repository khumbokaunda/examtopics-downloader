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
        $lines = preg_split('/\R/', self::cleanText((string) ($q['text'] ?? ''))) ?: [];
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
        foreach ((array) ($q['choices'] ?? []) as $i => $c) {
            if (is_array($c)) {
                $q['choices'][$i]['text'] = self::cleanText((string) ($c['text'] ?? ''));
            }
        }
        if (isset($q['explanation'])) {
            $q['explanation'] = self::cleanText((string) $q['explanation']);
        }
        $q = self::pictureChoices($q);
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
     * Scraped text as it should read:
     * - Character references left in it are decoded ("&amp;" to "&", "&#39;" to an
     *   apostrophe), also when they were encoded twice.
     * - Text that was decoded twice ("â€™" for an apostrophe, "Ã©" for "é") is repaired,
     *   run by run, so correct accented or non-Latin text is never touched.
     * - Non-breaking spaces become spaces, and runs of blank lines become one.
     */
    public static function cleanText(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $s = (string) preg_replace_callback(self::MOJIBAKE, static function (array $m): string {
            $bytes = '';
            foreach (mb_str_split($m[0]) as $ch) {
                $cp = mb_ord($ch);
                // Latin-1 and the C1 controls (bytes Windows-1252 leaves undefined) are the
                // byte itself; the rest are Windows-1252's own characters for 0x80 to 0x9F.
                $byte = $cp <= 0xFF ? $cp : (self::CP1252[$cp] ?? null);
                if ($byte === null) {
                    return $m[0];
                }
                $bytes .= chr($byte);
            }
            return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $m[0];
        }, $s);
        for ($i = 0; $i < 2 && preg_match('/&(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $s) === 1; $i++) {
            $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $s = str_replace(["\u{00A0}", "\r\n", "\r"], [' ', "\n", "\n"], $s);
        $s = (string) preg_replace('/[ \t]+\n/', "\n", $s);
        return trim((string) preg_replace('/\n{3,}/', "\n\n", $s));
    }

    /**
     * A UTF-8 character's bytes read as Windows-1252: a lead byte (Â to ô) followed by one to
     * three bytes 0x80 to 0xBF, each shown as its Windows-1252 character.
     */
    private const MOJIBAKE = '/[\x{00C2}-\x{00F4}][\x{0080}-\x{00BF}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}'
        . '\x{02C6}\x{02DC}\x{2013}\x{2014}\x{2018}-\x{201A}\x{201C}-\x{201E}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}]{1,3}/u';

    /** Windows-1252's characters for bytes 0x80 to 0x9F. */
    private const CP1252 = [0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86,
        0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E,
        0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
        0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F];

    /**
     * Options that are pictures, from collections that kept every image in one list: when
     * every choice is empty and there are at least as many question images as choices, the
     * last images are the choices' pictures, in order.
     *
     * @param array<string,mixed> $q
     * @return array<string,mixed>
     */
    private static function pictureChoices(array $q): array
    {
        $choices = array_values(array_filter((array) ($q['choices'] ?? []), 'is_array'));
        $images = array_values(array_filter((array) ($q['question_images'] ?? []), 'is_string'));
        $n = count($choices);
        if ($n < 2 || count($images) < $n) {
            return $q;
        }
        foreach ($choices as $c) {
            if (trim((string) ($c['text'] ?? '')) !== '' || ((array) ($c['images'] ?? [])) !== []) {
                return $q;
            }
        }
        $own = array_slice($images, count($images) - $n);
        foreach ($choices as $i => $c) {
            $choices[$i]['images'] = [$own[$i]];
        }
        $q['choices'] = $choices;
        $q['question_images'] = array_slice($images, 0, count($images) - $n);
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

    /**
     * Questions answered by placing items on a picture rather than picking letters:
     * "drag-drop" (Select and Place) or "hotspot" (Hot Area). Null for ordinary questions.
     *
     * @param array<string,mixed> $q
     */
    public static function kind(array $q): ?string
    {
        $text = (string) ($q['text'] ?? '');
        return match (true) {
            preg_match('/^\s*DRAG\s+DROP\b|Select and Place:/i', $text) === 1 => 'drag-drop',
            preg_match('/^\s*HOTSPOT\b|Hot Area:/i', $text) === 1 => 'hotspot',
            default => null,
        };
    }

    /** What to say when such a question has no answer: the answer is a picture. */
    public static function pictureAnswerNote(array $q): ?string
    {
        $kind = self::kind($q);
        if ($kind === null || trim((string) ($q['answer'] ?? '')) !== '' || ($q['answer_images'] ?? []) !== []) {
            return null;
        }
        return ($kind === 'drag-drop' ? 'Drag and drop' : 'Hot area') . ' question: the answer is a picture that ExamTopics does not show on'
            . ' discussion pages. The discussion usually says how people placed the items.';
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
                    'author' => self::cleanText((string) ($c['author'] ?? '')),
                    'timestamp' => (string) ($c['timestamp'] ?? ''),
                    'upvotes' => isset($c['upvotes']) ? (int) $c['upvotes'] : null,
                    'content' => self::cleanText((string) $c['content']),
                    'selected' => preg_match('/^[A-Z]{1,10}$/', $selected) === 1 ? $selected : '',
                    'reply' => (string) ($c['parent_id'] ?? '') !== '',
                ];
            }
        }
        $text = self::cleanText((string) ($q['comments_text'] ?? ''));
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
