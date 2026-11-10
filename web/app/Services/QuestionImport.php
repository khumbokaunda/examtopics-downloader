<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Bringing questions into a question bank from a file, so a lecturer does not have to type each
 * one. Two layouts are read:
 *
 *  - CSV: a row per question with the columns type, question, a to j (the choices), answer,
 *    marks, difficulty, topic, explanation and reference. Only "question" and "answer" are
 *    needed; the kind of question is worked out from the answer when "type" is empty.
 *  - Aiken: plain text. The question, then a line per choice ("A. ..." or "A) ..."), then
 *    "ANSWER: B". One right answer per question; a blank line between questions.
 *
 * It works like the student import: the file is read and every question checked first
 * (check()), the person sees what will happen and what cannot be imported, and only then are
 * the good ones saved (apply()). Imported questions are drafts, so they go through the bank's
 * usual review before any assessment can use them. A question already in the bank, word for
 * word, is not imported again.
 */
final class QuestionImport
{
    public const FORMATS = ['csv' => 'CSV spreadsheet', 'aiken' => 'Aiken text'];
    public const COLUMNS = ['type', 'question', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'answer', 'marks', 'difficulty', 'topic', 'explanation', 'reference'];
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 1048576;
    private const LETTERS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];

    public function __construct(private QuestionBanks $banks)
    {
    }

    public static function template(): string
    {
        return "type,question,a,b,c,d,answer,marks,difficulty,topic,explanation\r\n"
            . "single,Which layer of the OSI model routes packets?,Physical,Data link,Network,Transport,C,1,easy,,Routers work at the network layer.\r\n"
            . "multiple,Which of these are link-state routing protocols?,OSPF,RIP,IS-IS,BGP,\"A,C\",2,medium,,\r\n"
            . "true_false,A switch forwards frames by MAC address.,,,,,True,1,easy,,\r\n";
    }

    /**
     * Read a file in either layout.
     *
     * @return list<array<string,mixed>> rows: line, type, text, choices (letter => text), answer, marks, difficulty, topic, explanation, reference
     */
    public static function parse(string $content, string $format): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new RuntimeException('The file is larger than 1 MB. Split it into smaller files.');
        }
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $rows = $format === 'aiken' ? self::parseAiken($content) : self::parseCsv($content);
        if ($rows === []) {
            throw new RuntimeException('No questions were found in the file.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('The file has more than ' . self::MAX_ROWS . ' questions. Split it into smaller files.');
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function parseCsv(string $csv): array
    {
        $first = (string) (preg_split('/\r\n|\r|\n/', $csv)[0] ?? '');
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, 0, $delimiter, '"', '');
        if (!is_array($header)) {
            throw new RuntimeException('The file is empty.');
        }
        $map = [];
        foreach ($header as $pos => $h) {
            $key = str_replace([' ', '-'], '_', strtolower(trim((string) $h)));
            $key = (string) preg_replace('/^(choice|option)_?([a-j])$/', '$2', $key);
            $key = match ($key) { 'text', 'stem' => 'question', 'answers', 'correct', 'correct_answer', 'key' => 'answer', 'mark', 'points' => 'marks', 'kind' => 'type', default => $key };
            if (in_array($key, self::COLUMNS, true)) {
                $map[$key] = $pos;
            }
        }
        if (!isset($map['question'], $map['answer'])) {
            throw new RuntimeException('The first row must name the columns, with one called "question" and one called "answer". Download the template to see the layout.');
        }
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || trim(implode('', array_map('strval', $cells))) === '') {
                continue;
            }
            $cell = static fn(string $c): string => isset($map[$c]) ? trim((string) ($cells[$map[$c]] ?? '')) : '';
            $choices = [];
            foreach (self::LETTERS as $l) {
                if ($cell($l) !== '') {
                    $choices[$l] = $cell($l);
                }
            }
            $rows[] = ['line' => $line, 'type' => strtolower($cell('type')), 'text' => $cell('question'), 'choices' => $choices, 'answer' => $cell('answer'), 'marks' => $cell('marks'),
                'difficulty' => strtolower($cell('difficulty')), 'topic' => $cell('topic'), 'explanation' => $cell('explanation'), 'reference' => $cell('reference')];
        }
        fclose($stream);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function parseAiken(string $text): array
    {
        $rows = [];
        $current = null;
        $flush = static function () use (&$rows, &$current): void {
            if ($current !== null && (trim($current['text']) !== '' || $current['choices'] !== [])) {
                $current['text'] = trim($current['text']);
                $rows[] = $current;
            }
            $current = null;
        };
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $n => $raw) {
            $line = trim($raw);
            if ($line === '') {
                // A blank line ends a question once it has its choices (a missing answer line is then
                // reported for that question); before its choices it is spacing in the question.
                if ($current !== null && ($current['answer'] !== '' || $current['choices'] !== [])) {
                    $flush();
                }
                continue;
            }
            if ($current !== null && $current['answer'] !== '') {
                $flush();
            }
            $current ??= ['line' => $n + 1, 'type' => 'single', 'text' => '', 'choices' => [], 'answer' => '', 'marks' => '', 'difficulty' => '', 'topic' => '',
                'explanation' => '', 'reference' => ''];
            if (preg_match('/^ANSWER\s*:\s*([A-J])\b/i', $line, $m) === 1) {
                $current['answer'] = strtoupper($m[1]);
            } elseif (preg_match('/^([A-J])[.)]\s+(.*)$/', $line, $m) === 1 && $current['text'] !== '') {
                $current['choices'][strtolower($m[1])] = trim($m[2]);
            } elseif ($current['choices'] === []) {
                $current['text'] .= ($current['text'] !== '' ? "\n" : '') . $line;
            } else {
                // Text after the choices and before the answer: part of the last choice.
                $last = array_key_last($current['choices']);
                $current['choices'][$last] .= ' ' . $line;
            }
        }
        $flush();
        return $rows;
    }

    /**
     * Check every row against the bank's rules, and say what will happen to it.
     *
     * @param array<string,mixed> $bank @param list<array<string,mixed>> $rows from parse()
     * @return list<array<string,mixed>> each row with "errors" (empty when it can be imported) and, then, "v" (the checked question)
     */
    public function check(int $iid, array $bank, array $rows): array
    {
        $repo = $this->banks->repo();
        $topics = [];
        foreach ($repo->topics($iid, (int) $bank['id']) as $t) {
            $topics[mb_strtolower((string) $t['name'])] ??= (string) $t['uuid'];
        }
        $have = [];
        foreach ($repo->questions($iid, (int) $bank['id']) as $q) {
            $have[self::fingerprint((string) $q['text'])] = true;
        }
        $out = [];
        foreach ($rows as $r) {
            $errors = [];
            $answer = trim((string) $r['answer']);
            $choices = (array) $r['choices'];
            $type = (string) $r['type'];
            $truth = match (strtolower($answer)) { 'true', 't', 'yes' => 'T', 'false', 'f', 'no' => 'F', default => null };
            $letters = $truth === null || $choices !== [] ? array_values(array_unique(preg_split('/[\s,;|\/]+/', strtolower($answer), -1, PREG_SPLIT_NO_EMPTY) ?: [])) : [];
            if (count($letters) === 1 && strlen($letters[0]) > 1 && preg_match('/^[a-j]+$/', $letters[0]) === 1) {
                $letters = str_split($letters[0]); // "AC" for A and C
            }
            $type = match (true) {
                in_array($type, ['single', 'multiple', 'true_false'], true) => $type,
                in_array($type, ['tf', 'truefalse', 'true/false', 'true or false'], true) => 'true_false',
                in_array($type, ['multi', 'multiple select', 'multiselect'], true) => 'multiple',
                $type !== '' => '',
                $choices === [] && $truth !== null => 'true_false',
                count($letters) > 1 => 'multiple',
                default => 'single',
            };
            if ($type === '') {
                $errors[] = 'The type is single, multiple or true_false.';
                $type = 'single';
            }
            $in = ['type' => $type, 'text' => (string) $r['text'], 'marks' => $r['marks'] !== '' ? (string) $r['marks'] : '1',
                'difficulty' => $r['difficulty'] !== '' ? (string) $r['difficulty'] : 'medium', 'explanation' => (string) $r['explanation'], 'reference' => (string) $r['reference']];
            if ($type === 'true_false') {
                $in['tf_answer'] = $truth ?? '';
            } else {
                $in['choice_text'] = [];
                $in['correct'] = [];
                foreach (self::LETTERS as $idx => $l) {
                    $in['choice_text'][$idx] = (string) ($choices[$l] ?? '');
                    if (in_array($l, $letters, true)) {
                        $in['correct'][] = (string) $idx;
                        if (!isset($choices[$l])) {
                            $errors[] = 'The answer names choice ' . strtoupper($l) . ', which is empty.';
                        }
                    }
                }
                if ($letters === [] || array_diff($letters, self::LETTERS) !== []) {
                    $errors[] = 'Give the answer as the letter of the right choice (A, or A,C for several).';
                }
            }
            if ((string) $r['topic'] !== '') {
                $uuid = $topics[mb_strtolower((string) $r['topic'])] ?? null;
                if ($uuid === null) {
                    $errors[] = 'The bank has no topic called "' . $r['topic'] . '".';
                } else {
                    $in['topic'] = $uuid;
                }
            }
            [$v, $problems] = $this->banks->validate($iid, (int) $bank['id'], $in);
            $errors = array_values(array_unique(array_merge($errors, $problems)));
            $print = self::fingerprint((string) $r['text']);
            if ($errors === [] && isset($have[$print])) {
                $errors[] = 'Already in the bank.';
            }
            if ($errors === []) {
                $have[$print] = true; // the same question twice in one file is imported once
            }
            $out[] = $r + ['kind' => $type, 'errors' => $errors, 'v' => $errors === [] ? $v : null];
        }
        return $out;
    }

    /**
     * Save the questions that passed, as drafts.
     *
     * @param array<string,mixed> $i @param array<string,mixed> $m @param array<string,mixed> $bank @param list<array<string,mixed>> $checked from check()
     * @return int how many were imported
     */
    public function apply(array $i, array $m, array $bank, array $checked): int
    {
        $n = 0;
        foreach ($checked as $r) {
            if (($r['errors'] ?? ['x']) === [] && is_array($r['v'] ?? null)) {
                $this->banks->saveQuestion($i, $m, $bank, null, $r['v'], false);
                $n++;
            }
        }
        if ($n > 0) {
            $this->banks->record('institution.questions_imported', $i, ['bank' => $bank['name'], 'count' => $n]);
        }
        return $n;
    }

    /** The rows that cannot be imported, as a CSV to correct and upload again. @param list<array<string,mixed>> $checked */
    public static function problemsCsv(array $checked): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['line', 'question', 'problem'], ',', '"', '');
        foreach ($checked as $r) {
            if ($r['errors'] !== []) {
                fputcsv($out, [(int) $r['line'], mb_substr((string) $r['text'], 0, 200), implode(' ', $r['errors'])], ',', '"', '');
            }
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    private static function fingerprint(string $text): string
    {
        return hash('sha256', mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text))));
    }
}
