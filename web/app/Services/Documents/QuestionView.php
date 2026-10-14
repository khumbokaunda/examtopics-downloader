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
        // The public cached dataset marks where each question picture sits with "//IMG//", one
        // mark per picture, in order. The marks are kept as the places between text parts.
        if (str_contains($text, self::IMG_MARK)) {
            $q['text_parts'] = array_map('trim', explode(self::IMG_MARK, $text));
            $text = trim(implode("\n\n", array_filter($q['text_parts'], static fn(string $p) => $p !== '')));
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

    private const IMG_MARK = '//IMG//';

    /**
     * The question as text and pictures in order: a picture goes where the cached dataset
     * marked it, and pictures without a mark follow the text, as they always did.
     *
     * @param array<string,mixed> $q a normalized question
     * @return list<array{text:string}|array{image:int}> image: an index into questionImages()
     */
    public static function questionSegments(array $q): array
    {
        $count = count(self::questionImages($q));
        $parts = is_array($q['text_parts'] ?? null) ? array_values(array_map('strval', $q['text_parts'])) : [(string) ($q['text'] ?? '')];
        $out = [];
        $next = 0;
        foreach ($parts as $i => $part) {
            if (trim($part) !== '') {
                $out[] = ['text' => $part];
            }
            if ($i < count($parts) - 1 && $next < $count) {
                $out[] = ['image' => $next++];
            }
        }
        for (; $next < $count; $next++) {
            $out[] = ['image' => $next];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $q
     * @return list<string> the question's picture addresses
     */
    public static function questionImages(array $q): array
    {
        return array_values(array_filter(array_map('strval', (array) ($q['question_images'] ?? [])), static fn(string $u) => $u !== ''));
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
        $edit = is_array($q['edit'] ?? null) ? $q['edit'] : null;
        $source = match ($q['answer_source'] ?? '') {
            'suggested' => 'suggested by ExamTopics',
            'community' => 'most-voted community answer; ExamTopics gave no suggested answer',
            'edited' => 'corrected by you; ' . (($edit['collected'] ?? '') !== '' ? 'the collected answer was ' . $edit['collected'] : 'no answer was collected'),
            default => '',
        };
        if (($edit['kind'] ?? '') === 'checked') {
            $source .= ($source !== '' ? '; ' : '') . 'checked by you';
        }
        return $a . ($source !== '' ? ' (' . $source . ')' : '');
    }

    /** Pattern of question keys (see key()). */
    public const KEY_PATTERN = '/^(?:\d{1,4}-\d{1,6}|id-[A-Za-z0-9_-]{1,40}|n-\d{1,6})$/';

    /**
     * A question's identity across versions and sources: its topic and question number
     * (ExamTopics' own numbering), else its discussion ID, else its position. Corrections
     * made in the reader are stored under it.
     *
     * @param array<string,mixed> $q
     */
    public static function key(array $q): string
    {
        $n = (int) ($q['question_number'] ?? 0);
        if ($n > 0) {
            return max(1, (int) ($q['topic'] ?? 1)) . '-' . $n;
        }
        $id = (string) ($q['id'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) === 1) {
            return 'id-' . $id;
        }
        return 'n-' . (int) ($q['number'] ?? 0);
    }

    /**
     * Apply the corrections made in the reader (answer_edits rows keyed by question key).
     * What was collected stays in $q['edit'], so pages can say what was changed.
     *
     * @param list<array<string,mixed>> $questions normalized questions
     * @param array<string,array<string,mixed>> $edits
     * @return list<array<string,mixed>>
     */
    public static function applyEdits(array $questions, array $edits): array
    {
        if ($edits === []) {
            return $questions;
        }
        return array_map(static fn(array $q) => self::applyEdit($q, $edits[self::key($q)] ?? null), $questions);
    }

    /**
     * @param array<string,mixed> $q a normalized question
     * @param array<string,mixed>|null $edit
     * @return array<string,mixed>
     */
    public static function applyEdit(array $q, ?array $edit): array
    {
        if ($edit === null) {
            return $q;
        }
        $collected = trim((string) ($q['answer'] ?? ''));
        $answer = self::letters((string) ($edit['answer'] ?? ''));
        $kind = $answer === '' ? 'note' : ($answer === self::letters($collected) ? 'checked' : 'corrected');
        $q['edit'] = [
            'kind' => $kind,
            'answer' => $answer,
            'note' => trim((string) ($edit['note'] ?? '')),
            'collected' => $collected,
            'updated_at' => (string) ($edit['updated_at'] ?? ''),
        ];
        if ($kind === 'corrected') {
            $q['answer'] = $answer;
            $q['answer_source'] = 'edited';
        }
        return $q;
    }

    /**
     * Reasons to check a question's answer by hand. The collection tool reads ExamTopics'
     * suggested answer and the votes; it cannot follow what the comments argue, and votes
     * can be few or limited to one letter. Empty when nothing looks wrong, when the question
     * has no lettered choices, or when the answer was already corrected or checked.
     *
     * @param array<string,mixed> $q a normalized question
     * @return list<string>
     */
    public static function doubts(array $q): array
    {
        if (count(self::choiceLetters($q)) < 2 || in_array($q['edit']['kind'] ?? '', ['corrected', 'checked'], true)) {
            return [];
        }
        $answer = self::letters((string) ($q['answer'] ?? ''));
        $out = [];
        if ($answer === '') {
            $out[] = 'No answer was collected.';
        }
        $expected = self::expectedAnswers((string) ($q['text'] ?? ''));
        if ($answer !== '' && $expected !== null && ($expected === 0 ? strlen($answer) < 2 : strlen($answer) !== $expected)) {
            $out[] = $expected === 0
                ? 'The question asks for more than one answer, but the answer has one letter.'
                : 'The question asks for ' . self::NUMBERS[$expected] . ' answers, but the answer has ' . strlen($answer) . '.';
        }
        $votes = array_values(array_filter((array) ($q['community_votes'] ?? []), 'is_array'));
        $suggested = self::letters((string) ($q['suggested_answer'] ?? ''));
        $voted = self::letters((string) ($votes[0]['answer'] ?? ''));
        if (($q['answer_source'] ?? '') === 'suggested' && $suggested !== '' && $voted !== '' && $voted !== $suggested) {
            $out[] = 'ExamTopics suggests ' . $suggested . ', but the community voted ' . $voted . '.';
        }
        if (($q['answer_source'] ?? '') === 'community') {
            if (!empty($q['votes_from_comments'])) {
                $n = count(array_filter(self::comments($q), static fn(array $c) => $c['selected'] !== ''));
                $few = $n === 1 ? 'Only one person voted.' : 'Only ' . $n . ' people voted.';
            } else {
                $n = array_sum(array_map(static fn(array $v) => (int) ($v['count'] ?? 0), $votes));
                $few = $n === 1 ? 'Only one vote was cast.' : 'Only ' . $n . ' votes were cast.';
            }
            if ($n < 3) {
                $out[] = $few;
            }
        }
        $claims = self::textClaims($q);
        if ($claims !== []) {
            $top = $claims[0];
            $forAnswer = 0;
            foreach ($claims as $c) {
                $forAnswer = $c['answer'] === $answer ? $c['count'] : $forAnswer;
            }
            if ($top['answer'] !== $answer && $top['count'] >= 2 && $top['count'] > $forAnswer) {
                $out[] = 'Most comments that name an answer say ' . $top['answer'] . ' (' . $top['count'] . ' of '
                    . array_sum(array_column($claims, 'count')) . ').';
            }
        }
        return $out;
    }

    /** What the comments say, e.g. "ABCD (5), ABC (3)"; null when none names an answer. */
    public static function claimsLine(array $q): ?string
    {
        $claims = self::textClaims($q);
        return $claims === [] ? null
            : implode(', ', array_map(static fn(array $c) => $c['answer'] . ' (' . $c['count'] . ')', $claims));
    }

    /**
     * The answers comments name in their text ("A, B and C", "the answer is BD", "all of the
     * above"): the first each comment names, counted per comment, most named first. Only the
     * question's own choice letters count.
     *
     * @param array<string,mixed> $q
     * @return list<array{answer:string,count:int}>
     */
    public static function textClaims(array $q): array
    {
        $letters = self::choiceLetters($q);
        if (count($letters) < 2) {
            return [];
        }
        $tally = [];
        foreach (self::comments($q) as $c) {
            $claim = self::claimIn($c['content'], $letters);
            if ($claim !== null) {
                $tally[$claim] = ($tally[$claim] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($tally as $answer => $count) {
            $out[] = ['answer' => (string) $answer, 'count' => $count];
        }
        usort($out, static fn(array $a, array $b) => [$b['count'], $a['answer']] <=> [$a['count'], $b['answer']]);
        return $out;
    }

    /**
     * The first answer a comment names, as sorted letters, or null.
     *
     * @param list<string> $letters the question's choice letters
     */
    public static function claimIn(string $text, array $letters): ?string
    {
        $all = implode('', $letters);
        $L = '[' . $all . ']';
        $end = '(?![\w\x{2019}\'])';
        $found = [];
        // "All of the above", "all are correct", "all the given options are correct".
        if (preg_match('/(?<!not\s)\ball\s+(?:of\s+)?(?:the\s+)?(?:above\b|of\s+them\b|(?:given\s+|listed\s+)?(?:options|answers|choices)?\s*(?:are|is)\s+(?:correct|right|true|valid)\b)/iu',
            $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $found[$m[0][1]] = $all;
        }
        $sep = '(?:\s*(?:,|\/|&|\+|\band\b|\be\b|\by\b|\bet\b|\bund\b)\s*|\s+)';
        $patterns = [
            // Separate letters: "A, B and C", "A,B,C,D", "B & D", "A, B e C".
            'list' => '/(?<![\w\x{2019}\'])' . $L . $end . '(?:' . $sep . $L . $end . ')+/u',
            // Joined letters at the start or after an answer word: "ABCD", "the answer is BD".
            'joined' => '/(?<![\w\x{2019}\'])' . $L . '{2,}' . $end . '/u',
            // One letter after an answer word: "Answer: B", "I think it is C", "go with D".
            'after' => '/(?i:\b(?:answers?|ans|correct(?:\s+(?:answer|option|one))?|option|go(?:ing)?\s+(?:with|for)|vote\s+for|i\s+think|it\s+is|it\x{2019}s|it\'s|should\s+be|must\s+be)'
                . '\s*(?:is|:|=|-|would\s+be|should\s+be)?\s*(?:option\s+)?)(' . $L . ')' . $end
                . '(?=\s*(?:$|[.,;:!?)]|(?i:because|since|as|for|is|and|but)\b))/u',
            // One letter followed by a verdict: "B is correct", "C is the answer".
            'verdict' => '/(?<![\w\x{2019}\'])' . $L . '\s+(?i:is\s+(?:the\s+)?(?:correct|right|true|answer)\b)/u',
            // The whole comment is one letter.
            'alone' => '/^\s*' . $L . '\s*[.!]?\s*$/u',
        ];
        foreach ($patterns as $name => $re) {
            if (preg_match_all($re, $text, $ms, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($ms[0] as $i => [$match, $at]) {
                $span = $name === 'after' ? $ms[1][$i][0] : ($name === 'verdict' ? substr($match, 0, 1) : $match);
                // Only the capital letters: separators ("and", "e") are lower case.
                $answer = self::letters((string) preg_replace('/[^A-Z]/', '', $span));
                if ($answer === '') {
                    continue;
                }
                if ($name === 'joined' && ($answer !== $match || !self::afterAnswerWord(substr($text, 0, $at)))) {
                    continue; // letters out of order or repeated, or an acronym in a sentence
                }
                // "C and D are wrong" names what the answer is not.
                if (preg_match('/^\s*(?:is|are|were)\s+(?:not\b|wrong|incorrect|false)/i', substr($text, $at + strlen($match))) === 1) {
                    continue;
                }
                $found[$name === 'after' ? $ms[1][$i][1] : $at] ??= $answer;
                break;
            }
        }
        if ($found === []) {
            return null;
        }
        ksort($found);
        return (string) reset($found);
    }

    /** Text before joined letters ends at the comment's start or with an answer word. */
    private static function afterAnswerWord(string $before): bool
    {
        return trim($before) === ''
            || preg_match('/(?:\b(?:answers?|ans|correct|options?|choices?|is|are|be|with|for|votes?|voted|picked?|chose|choose|selected?)|[:=])\W{0,3}$/i', $before) === 1;
    }

    /**
     * How many answers the wording asks for: a number ("Choose two"), 0 for "more than one"
     * ("Which of the following ... are"), or null when it does not say.
     */
    private static function expectedAnswers(string $text): ?int
    {
        if (preg_match('/\b(?:choose|select|pick|identify|which|what)\s+(two|three|four|five|2|3|4|5)(?![\w-])/i', $text, $m) === 1) {
            $n = array_search(strtolower($m[1]), self::NUMBERS, true);
            return $n !== false ? $n : (int) $m[1];
        }
        if (preg_match('/[(\[]\s*multiple[\s-]+(?:choice|answers?|select(?:ion)?|response)s?\s*[)\]]/i', $text) === 1
            || preg_match('/\bwhich\s+of\s+the\s+following\b(?:(?!\b(?:is|was|does|has)\b)[^.?!\n])*?\b(?:are|were|have)\b/i', $text) === 1) {
            return 0;
        }
        return null;
    }

    private const NUMBERS = [2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five'];

    /**
     * @param array<string,mixed> $q
     * @return list<string> the choices' letters
     */
    public static function choiceLetters(array $q): array
    {
        $out = [];
        foreach ((array) ($q['choices'] ?? []) as $c) {
            $l = is_array($c) ? (string) ($c['letter'] ?? '') : '';
            if (preg_match('/^[A-Z]$/', $l) === 1) {
                $out[] = $l;
            }
        }
        return array_values(array_unique($out));
    }

    /** Answer letters, sorted and without repeats: "ca" and "A, C" both become "AC". */
    public static function letters(string $s): string
    {
        $l = array_unique(str_split((string) preg_replace('/[^A-Z]/', '', strtoupper($s))));
        sort($l);
        return implode('', array_filter($l, static fn(string $c) => $c !== ''));
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
