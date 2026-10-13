<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Ids;

/**
 * Discussion links for one exam, read from text the user pastes: discussion addresses, or a
 * search results page copied as text, where each result shows a title such as "Exam
 * H19-101_V6.0 topic 1 question 1 discussion" and an address line such as
 * "https://www.examtopics.com › huawei › view › 390360...".
 *
 * ExamTopics sometimes changes how an exam is spelled in its addresses (h19-101_v60 became
 * h19-101-v6-0); an old address then fails while the discussion number still works with the
 * new spelling. So every link is rebuilt with the exam's current spelling.
 */
final class LinkImport
{
    /** Pasted text beyond this is refused; many pages of search results fit easily. */
    public const MAX_BYTES = 300_000;

    /**
     * @param string $exam the exam code to keep links for; '' to take it from the text
     * @return array{exam:?string,name:?string,links:list<string>,questions:list<string>,other:int}
     */
    public static function parse(string $text, string $provider, string $exam = ''): array
    {
        $none = ['exam' => null, 'name' => null, 'links' => [], 'questions' => [], 'other' => 0];
        if (!Ids::isProvider($provider)) {
            return $none;
        }
        $found = self::scan(substr($text, 0, self::MAX_BYTES), $provider);
        if ($found === []) {
            return $none;
        }
        $target = self::slug($exam);
        $name = null;
        if ($target === '') {
            // The exam most entries are for. A title spells it the way ExamTopics now
            // spells addresses ("H19-101_V6.0" is h19-101-v6-0); an address may be older.
            $counts = array_count_values(array_map(static fn(array $f) => self::key($f['code']), $found));
            arsort($counts);
            $key = (string) array_key_first($counts);
            foreach ($found as $f) {
                if (self::key($f['code']) === $key && ($target === '' || $f['title'])) {
                    $target = $f['title'] ? self::slug($f['code']) : $f['code'];
                    $name = $f['title'] ? $f['code'] : $name;
                }
            }
        } else {
            foreach ($found as $f) {
                if ($f['title'] && self::key($f['code']) === self::key($target)) {
                    $name = $f['code'];
                }
            }
        }
        if (!Ids::isExam($target)) {
            return $none;
        }
        $links = [];
        $numbers = [];
        $other = 0;
        foreach ($found as $f) {
            if (self::key($f['code']) !== self::key($target)) {
                $other++;
                continue;
            }
            $path = $f['path'] !== null && $f['code'] === $target
                ? $f['path']
                : '/discussions/' . $provider . '/view/' . $f['id'] . '-exam-' . $target
                    . ($f['topic'] > 0 ? '-topic-' . $f['topic'] : '') . '-question-' . $f['question'] . '-discussion/';
            $links[$f['id']] = $path;
            $numbers[$f['id']] = [$f['topic'], $f['question']];
        }
        asort($numbers);
        $topics = array_unique(array_column($numbers, 0));
        $labels = array_map(static fn(array $n) => count($topics) > 1 ? 'T' . $n[0] . ' Q' . $n[1] : (string) $n[1], array_values($numbers));
        return ['exam' => $target, 'name' => $name, 'links' => array_values(array_replace($numbers, $links)),
            'questions' => array_values(array_unique($labels)), 'other' => $other];
    }

    /**
     * Entries in text order: addresses, and search results paired from a title and the
     * address line after it.
     *
     * @return list<array{id:string,code:string,topic:int,question:int,path:?string,title:bool}>
     */
    private static function scan(string $text, string $provider): array
    {
        $p = preg_quote($provider, '#');
        $tokens = [];
        preg_match_all('#https?://(?:www\.)?examtopics\.com(/discussions/' . $p . '/view/(\d+)-exam-([a-z0-9_.-]+?)(?:-topic-(\d+))?-question-(\d+)(?:-discussion)?/?)(?![\w-])#i',
            $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            $tokens[] = [$x[0][1], 'url', ['id' => $x[2][0], 'code' => strtolower($x[3][0]), 'topic' => (int) $x[4][0],
                'question' => (int) $x[5][0], 'path' => $x[1][0] . (str_ends_with($x[1][0], '/') ? '' : '/'), 'title' => false]];
        }
        preg_match_all('#\bExam\s+([A-Za-z0-9][A-Za-z0-9_.-]*)\s+(?:topic\s+(\d+)\s+)?question\s+(\d+)\s+discussion\b#i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            $tokens[] = [$x[0][1], 'title', ['code' => $x[1][0], 'topic' => (int) ($x[2][0] ?? 0), 'question' => (int) $x[3][0]]];
        }
        preg_match_all('#examtopics\.com\s*[›>»]\s*' . $p . '\s*[›>»]\s*view\s*[›>»]\s*(\d+)#iu', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            $tokens[] = [$x[0][1], 'crumb', ['id' => $x[1][0]]];
        }
        usort($tokens, static fn(array $a, array $b) => $a[0] <=> $b[0]);

        $out = [];
        $title = null;
        $used = null;
        foreach ($tokens as [, $kind, $t]) {
            if ($kind === 'url') {
                $out[] = $t;
            } elseif ($kind === 'title') {
                // A result's text often repeats its title after the address line: not a new result.
                $title = $t === $used ? null : $t;
            } elseif ($title !== null) {
                $out[] = ['id' => $t['id'], 'code' => $title['code'], 'topic' => $title['topic'], 'question' => $title['question'], 'path' => null, 'title' => true];
                $used = $title;
                $title = null;
            }
        }
        return $out;
    }

    /** The address spelling of an exam code: "H19-101_V6.0" is h19-101-v6-0. */
    public static function slug(string $code): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($code))), '-');
    }

    /** Spellings of one exam compare equal: h19-101_v60, h19-101-v6-0 and H19-101_V6.0. */
    private static function key(string $code): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($code));
    }
}
