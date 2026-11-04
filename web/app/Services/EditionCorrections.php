<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnswerEditRepository;
use App\Repositories\CacheVersionRepository;
use App\Repositories\QuestionEditRepository;
use App\Services\Documents\QuestionView;
use RuntimeException;

/**
 * Carries an exam's corrections to its other editions (the exams on its card in the library):
 * each corrected question is looked for in the other edition (EditionMatcher), and what
 * carrying it would do is shown first, so nothing changes until it is chosen.
 *
 * - An answer correction (or a check, or a note) is carried by the wording of its choices, so a
 *   different choice order cannot misplace it; when a choice is not found, it is not carried.
 * - A question edit (wording, choices, pictures) is carried only to a question that reads the
 *   same as this one was collected, since the edit replaces that wording.
 * - A correction the other edition already has is shown, and replaced only when chosen.
 */
final class EditionCorrections
{
    public const STATUSES = [
        'apply' => 'Will be added',
        'conflict' => 'Has its own',
        'same' => 'Already the same',
        'unmappable' => 'Its choices differ',
        'different' => 'Its wording differs',
        'none' => 'Not found there',
    ];

    public function __construct(
        private CacheVersionRepository $versions,
        private CacheService $cache,
        private AnswerEditRepository $answers,
        private QuestionEditRepository $questions,
    ) {
    }

    /**
     * The other editions on the exam's card, with their best versions.
     *
     * @return list<array<string,mixed>> library rows
     */
    public function others(int $examId, string $providerSlug): array
    {
        $rows = $this->versions->library(['provider' => $providerSlug]);
        $self = null;
        foreach ($rows as $r) {
            $self = (int) $r['exam_id'] === $examId ? $r : $self;
        }
        if ($self === null) {
            return [];
        }
        $key = Editions::key($self);
        return array_values(array_filter($rows, static fn(array $r): bool => (int) $r['exam_id'] !== $examId
            && is_array($r['best'] ?? null) && Editions::key($r) === $key));
    }

    /**
     * What carrying each correction to each other edition would do.
     *
     * @param array<string,mixed> $version the exam's version whose questions the corrections are read against
     * @param list<array<string,mixed>> $others from others()
     * @return list<array{exam:array<string,mixed>,items:list<array<string,mixed>>,error:?string}>
     */
    public function plan(int $examId, array $version, array $others): array
    {
        $answerEdits = $this->answers->forExam($examId);
        $questionEdits = $this->questions->forExam($examId);
        if ($answerEdits === [] && $questionEdits === []) {
            return [];
        }
        $source = [];
        foreach ($this->collected($version) as $q) {
            $source[QuestionView::key($q)] ??= $q;
        }
        $keys = array_values(array_unique(array_merge(array_keys($answerEdits), array_keys($questionEdits))));
        usort($keys, static fn($a, $b): int => (int) ($source[$a]['number'] ?? PHP_INT_MAX) <=> (int) ($source[$b]['number'] ?? PHP_INT_MAX));
        $out = [];
        foreach ($others as $other) {
            $targetId = (int) $other['exam_id'];
            try {
                $matcher = new EditionMatcher($this->collected((array) $other['best']));
            } catch (RuntimeException $e) {
                $out[] = ['exam' => $other, 'items' => [], 'error' => $e->getMessage()];
                continue;
            }
            $theirAnswers = $this->answers->forExam($targetId);
            $theirQuestions = $this->questions->forExam($targetId);
            $items = [];
            foreach ($keys as $key) {
                $q = $source[(string) $key] ?? null;
                if ($q === null) {
                    continue; // not in the version read here; it is carried from a version that has it
                }
                $m = $matcher->match($q);
                $base = ['target' => $targetId, 'source_key' => (string) $key, 'source_number' => (int) $q['number'],
                    'target_key' => $m['key'] ?? null, 'target_number' => $m !== null ? (int) $m['question']['number'] : null,
                    'exact' => $m['exact'] ?? false];
                if (isset($answerEdits[$key])) {
                    $items[] = $base + $this->answerItem($answerEdits[$key], $q, $m, $theirAnswers);
                }
                if (isset($questionEdits[$key])) {
                    $items[] = $base + $this->questionItem($questionEdits[$key], $q, $m, $theirQuestions);
                }
            }
            foreach ($items as $i => $item) {
                $items[$i]['id'] = $targetId . ':' . $item['source_key'] . ':' . $item['kind'];
            }
            $out[] = ['exam' => $other, 'items' => $items, 'error' => null];
        }
        return $out;
    }

    /**
     * Carry the chosen items (ids from plan()) that can be carried.
     *
     * @param list<array{exam:array<string,mixed>,items:list<array<string,mixed>>,error:?string}> $plan
     * @param list<string> $chosen
     * @return array<int,int> how many were carried, per exam id
     */
    public function apply(array $plan, array $chosen, ?int $userId): array
    {
        $chosen = array_flip($chosen);
        $done = [];
        foreach ($plan as $edition) {
            foreach ($edition['items'] as $item) {
                if (!isset($chosen[$item['id']]) || !in_array($item['status'], ['apply', 'conflict'], true)) {
                    continue;
                }
                $d = $item['data'];
                if ($item['kind'] === 'answer') {
                    $this->answers->save($item['target'], (string) $item['target_key'], $d['answer'], $d['note'], $userId);
                } else {
                    $this->questions->save($item['target'], (string) $item['target_key'], $d['text'], $d['choices'], $d['question_images'], $d['answer_images'], $userId);
                }
                $done[$item['target']] = ($done[$item['target']] ?? 0) + 1;
            }
        }
        return $done;
    }

    /**
     * @param array<string,mixed> $edit an answer_edits row @param array<string,mixed> $q
     * @param array<string,mixed>|null $m @param array<string,array<string,mixed>> $theirs
     * @return array<string,mixed>
     */
    private function answerItem(array $edit, array $q, ?array $m, array $theirs): array
    {
        $letters = (string) $edit['answer'];
        $note = trim((string) ($edit['note'] ?? '')) === '' ? null : trim((string) $edit['note']);
        $what = ($letters !== '' ? 'Answer ' . $letters : 'Note only') . ($note !== null && $letters !== '' ? ', with a note' : '');
        $item = ['kind' => 'answer', 'what' => $what, 'theirs' => null, 'data' => null];
        if ($m === null) {
            return $item + ['status' => 'none'];
        }
        $mapped = $letters === '' ? '' : EditionMatcher::mapLetters($letters, $q, $m['question']);
        if ($mapped === null) {
            return $item + ['status' => 'unmappable'];
        }
        if ($mapped !== $letters) {
            $item['what'] .= ' (' . $mapped . ' there)';
        }
        $item['data'] = ['answer' => $mapped, 'note' => $note];
        $existing = $theirs[$m['key']] ?? null;
        if ($existing === null) {
            return ['status' => 'apply'] + $item;
        }
        $item['theirs'] = ((string) $existing['answer'] !== '' ? 'Answer ' . $existing['answer'] : 'Note only');
        $same = (string) $existing['answer'] === $mapped && trim((string) ($existing['note'] ?? '')) === (string) $note;
        return ['status' => $same ? 'same' : 'conflict'] + $item;
    }

    /**
     * @param array<string,mixed> $edit a decoded question edit @param array<string,mixed> $q
     * @param array<string,mixed>|null $m @param array<string,array<string,mixed>> $theirs
     * @return array<string,mixed>
     */
    private function questionItem(array $edit, array $q, ?array $m, array $theirs): array
    {
        $parts = array_filter([$edit['text'] !== null ? 'wording' : null, $edit['choices'] !== null ? 'choices' : null,
            ($edit['question_images'] !== null || $edit['answer_images'] !== null) ? 'pictures' : null]);
        $item = ['kind' => 'question', 'what' => 'Question edit: ' . implode(', ', $parts), 'theirs' => null,
            'data' => ['text' => $edit['text'], 'choices' => $edit['choices'], 'question_images' => $edit['question_images'], 'answer_images' => $edit['answer_images']]];
        if ($m === null) {
            return ['status' => 'none'] + $item;
        }
        if (!EditionMatcher::same($q, $m['question'])) {
            return ['status' => 'different'] + $item;
        }
        $existing = $theirs[$m['key']] ?? null;
        if ($existing === null) {
            return ['status' => 'apply'] + $item;
        }
        $item['theirs'] = 'Its own question edit';
        $same = [$existing['text'], $existing['choices'], $existing['question_images'], $existing['answer_images']]
            === [$edit['text'], $edit['choices'], $edit['question_images'], $edit['answer_images']];
        return ['status' => $same ? 'same' : 'conflict'] + $item;
    }

    /** @param array<string,mixed> $version @return list<array<string,mixed>> its questions as collected, normalized */
    private function collected(array $version): array
    {
        $data = $this->cache->load($version);
        return array_map([QuestionView::class, 'normalize'], array_values(array_filter((array) ($data['questions'] ?? []), 'is_array')));
    }
}
