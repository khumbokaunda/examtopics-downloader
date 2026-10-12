<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Validates a collected dataset (docs/DATA_MODEL.md section 1) before it may be published.
 * Errors make the dataset unpublishable; warnings are recorded on the published version.
 */
final class DatasetValidator
{
    public const SUPPORTED_SCHEMA = 1;

    /**
     * @param mixed $data decoded JSON
     * @return array{errors:list<string>,warnings:list<string>}
     */
    public function validate(mixed $data, string $provider, string $exam, ?int $reportedCount = null): array
    {
        $errors = [];
        $warnings = [];
        if (!is_array($data)) {
            return ['errors' => ['Top level is not a JSON object.'], 'warnings' => []];
        }
        if (($data['schema_version'] ?? null) !== self::SUPPORTED_SCHEMA) {
            $errors[] = 'Unsupported schema_version: ' . json_encode($data['schema_version'] ?? null);
        }
        if (($data['kind'] ?? null) !== 'dataset') {
            $errors[] = 'Not a dataset document.';
        }
        if (($data['provider'] ?? null) !== $provider) {
            $errors[] = 'Provider mismatch: expected ' . $provider . ', got ' . json_encode($data['provider'] ?? null);
        }
        if (($data['exam'] ?? null) !== $exam) {
            $errors[] = 'Exam mismatch: expected ' . $exam . ', got ' . json_encode($data['exam'] ?? null);
        }
        $status = $data['status'] ?? null;
        if (!in_array($status, ['complete', 'partial'], true)) {
            $errors[] = 'Missing or invalid completion status.';
        }
        if (!in_array($data['source'] ?? null, ['live', 'github-cache'], true)) {
            $errors[] = 'Missing or invalid source.';
        }
        $questions = $data['questions'] ?? null;
        if (!is_array($questions) || !array_is_list($questions)) {
            return ['errors' => array_merge($errors, ['questions is missing or not a list.']), 'warnings' => $warnings];
        }
        if ($questions === []) {
            $errors[] = 'The dataset contains no questions.';
        }

        $counts = is_array($data['counts'] ?? null) ? $data['counts'] : [];
        $collected = $counts['collected'] ?? null;
        if ($collected !== count($questions)) {
            $errors[] = 'counts.collected (' . json_encode($collected) . ') does not match the number of questions (' . count($questions) . ').';
        }
        $failures = (int) ($counts['failed_listing_pages'] ?? 0) + (int) ($counts['failed_questions'] ?? 0)
            + (int) ($counts['failed_cache_files'] ?? 0);
        if ($status === 'complete' && $failures > 0) {
            $errors[] = 'Marked complete but reports ' . $failures . ' failed items.';
        }
        if ($status === 'partial' && $failures === 0) {
            $errors[] = 'Marked partial but reports no failed items.';
        }
        if (($data['source'] ?? null) === 'live' && $status === 'complete'
            && isset($counts['question_links']) && $counts['question_links'] !== count($questions)) {
            $errors[] = 'Complete live collection expected ' . $counts['question_links'] . ' questions but has ' . count($questions) . '.';
        }

        $ids = [];
        foreach ($questions as $i => $q) {
            $where = 'Question ' . ($i + 1);
            if (!is_array($q)) {
                $errors[] = $where . ' is not an object.';
                continue;
            }
            if (($q['number'] ?? null) !== $i + 1) {
                $errors[] = $where . ': number is ' . json_encode($q['number'] ?? null) . '.';
            }
            $id = $q['id'] ?? null;
            if (!is_string($id) || $id === '') {
                $errors[] = $where . ': missing id.';
            } elseif (isset($ids[$id])) {
                $errors[] = $where . ': duplicate id ' . $id . ' (also question ' . $ids[$id] . ').';
            } else {
                $ids[$id] = $i + 1;
            }
            foreach (['text', 'answer', 'answer_source', 'suggested_answer', 'explanation', 'comments_text', 'source_url', 'timestamp'] as $f) {
                if (!is_string($q[$f] ?? null)) {
                    $errors[] = $where . ': ' . $f . ' must be a string.';
                }
            }
            foreach (['choices', 'community_votes', 'question_images', 'answer_images', 'comments'] as $f) {
                if (!is_array($q[$f] ?? null) || !array_is_list($q[$f])) {
                    $errors[] = $where . ': ' . $f . ' must be a list.';
                }
            }
            foreach ((array) ($q['choices'] ?? []) as $c) {
                if (!is_array($c) || !is_string($c['letter'] ?? null) || !is_string($c['text'] ?? null)) {
                    $errors[] = $where . ': malformed choice.';
                    break;
                }
            }
            foreach ((array) ($q['comments'] ?? []) as $c) {
                if (!is_array($c) || !is_string($c['content'] ?? null) || !is_string($c['author'] ?? null) || !is_int($c['upvotes'] ?? null)) {
                    $errors[] = $where . ': malformed comment.';
                    break;
                }
            }
            if (is_string($q['text'] ?? null) && trim($q['text']) === '' && ($q['question_images'] ?? []) === []) {
                $warnings[] = $where . ' has no text or images.';
            }
            if (count($errors) > 25) {
                $errors[] = 'Too many errors; stopped checking.';
                break;
            }
        }

        if ($reportedCount !== null && $reportedCount > 0 && $errors === []) {
            $n = count($questions);
            if ($n < $reportedCount) {
                $warnings[] = "The inventory reported {$reportedCount} questions, but {$n} were collected.";
            } elseif ($n > $reportedCount) {
                $warnings[] = "Collected {$n} questions, more than the {$reportedCount} the inventory reported (the inventory may be out of date).";
            }
        }
        if ($status === 'partial') {
            $warnings[] = "Partial collection: {$failures} expected items could not be fetched.";
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
