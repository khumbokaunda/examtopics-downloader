<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnswerEditRepository;
use App\Repositories\QuestionEditRepository;
use App\Services\Documents\QuestionView;

/**
 * Everything corrected by hand for an exam, applied over the collected questions in one place,
 * so the reader, answer checks, the integrity page and documents all show the same thing:
 * first the question's wording, choices and pictures, then its answer.
 */
final class Corrections
{
    public function __construct(private AnswerEditRepository $answers, private QuestionEditRepository $questions)
    {
    }

    /**
     * @param array<mixed> $raw the dataset's questions
     * @return list<array<string,mixed>> normalized questions with every correction applied
     */
    public function apply(int $examId, array $raw): array
    {
        $questions = array_map([QuestionView::class, 'normalize'], array_values(array_filter($raw, 'is_array')));
        if ($questions === []) {
            return [];
        }
        return QuestionView::applyEdits(QuestionView::applyQuestionEdits($questions, $this->questions->forExam($examId)),
            $this->answers->forExam($examId));
    }

    /**
     * A short fingerprint of the exam's corrections, recorded with each document so one made
     * before a correction can be rebuilt. Null when there are none. An exam with only answer
     * corrections keeps the fingerprint it had before questions could be corrected, so its
     * documents are not made again for nothing.
     */
    public function fingerprint(int $examId): ?string
    {
        $answers = $this->answers->fingerprint($examId);
        $edits = $this->questions->forExam($examId);
        if ($edits === []) {
            return $answers;
        }
        $parts = [(string) $answers];
        foreach ($edits as $key => $e) {
            $parts[] = $key . '=' . json_encode([$e['text'], $e['choices'], $e['question_images'], $e['answer_images']], JSON_UNESCAPED_UNICODE);
        }
        return substr(hash('sha256', implode("\n", $parts)), 0, 16);
    }

    /** @return list<string> picture addresses used by the exam's corrections, uploaded ones included */
    public function imageUrls(int $examId): array
    {
        return $this->questions->imageUrls($examId);
    }
}
