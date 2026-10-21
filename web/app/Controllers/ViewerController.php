<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Documents\QuestionView;
use App\Services\Editions;
use App\Services\MediaStore;
use App\Services\StudyProgress;
use RuntimeException;

/**
 * Read-only, paginated question viewer. In practice mode the page contains no answers,
 * explanations, votes or comments, not even hidden in the HTML or in script data. Each is
 * fetched for one question at a time from reveal(), only when the user asks.
 *
 * A checked answer is sent to attempt(), which grades it against the answer on the server and
 * adds it to the person's study progress. The page a person opens is remembered, so the library
 * can offer to continue from it.
 */
final class ViewerController extends Controller
{
    public function show(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->query('version'));
        $mode = $this->request->query('mode') === 'practice' ? 'practice' : 'study';
        $q = mb_substr(trim((string) $this->request->query('q', '')), 0, 100);
        $perPage = max(1, min(50, (int) $this->app->config()->get('viewer.per_page', 10)));

        try {
            $data = $this->s->cache()->load($v);
        } catch (RuntimeException $ex) {
            throw new HttpException(409, $ex->getMessage());
        }
        $questions = QuestionView::applyEdits(
            array_map([QuestionView::class, 'normalize'], array_values(array_filter((array) ($data['questions'] ?? []), 'is_array'))),
            $this->s->answerEdits()->forExam((int) $e['id']));
        // Answers that look doubtful and nobody has checked yet (study mode only).
        $review = $mode === 'study' && $this->request->query('review') === '1';
        $toCheck = $mode === 'study' ? count(array_filter($questions, static fn(array $item) => QuestionView::doubts($item) !== [])) : 0;
        if ($review) {
            $questions = array_values(array_filter($questions, static fn(array $item) => QuestionView::doubts($item) !== []));
        }
        // Practice mode can show only the questions this person last answered wrong.
        $userId = (int) ($this->auth->user()['id'] ?? 0);
        $progress = $this->s->progress()->forExam($userId, (int) $e['id']);
        $mistakes = $mode === 'practice' && $this->request->query('mistakes') === '1';
        if ($mistakes) {
            $wrong = array_flip($this->s->progress()->mistakes($userId, (int) $e['id']));
            $questions = array_values(array_filter($questions, static fn(array $item) => isset($wrong[QuestionView::key($item)])));
        }
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $questions = array_values(array_filter($questions, static function (array $item) use ($needle): bool {
                $hay = (string) ($item['text'] ?? '');
                foreach ((array) ($item['choices'] ?? []) as $c) {
                    $hay .= ' ' . (string) ($c['text'] ?? '');
                }
                return str_contains(mb_strtolower($hay), $needle);
            }));
        }
        $total = count($questions);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) $this->request->query('page', '1')));
        $jump = (int) $this->request->query('jump', '0');
        if ($jump > 0 && $q === '' && !$review && !$mistakes) {
            $page = max(1, min($pages, (int) ceil($jump / $perPage)));
        }
        if ($q === '' && !$review && !$mistakes && $userId > 0) {
            $this->s->progress()->remember($userId, (int) $e['id'], $mode, $page, $this->today());
        }
        $slice = array_slice($questions, ($page - 1) * $perPage, $perPage);
        if ($mode === 'practice') {
            $slice = array_map([self::class, 'practiceView'], $slice);
        }
        return $this->render('viewer/show', [
            'localImages' => $this->localImages(MediaStore::urlsOf(['questions' => $slice])),
            'title' => Editions::shortCode((string) $e['code']) . ($mode === 'practice' ? ' practice' : ' study'),
            'nav' => 'library',
            'provider' => $p,
            'exam' => $e,
            'version' => $v,
            'questions' => $slice,
            'mode' => $mode,
            'review' => $review,
            'mistakes' => $mistakes,
            'progress' => $progress,
            'toCheck' => $toCheck,
            'q' => $q,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'all' => count((array) ($data['questions'] ?? [])),
        ]);
    }

    /** Answer details for one question (practice mode reveal). */
    public function reveal(string $provider, string $exam, string $version, string $number): Response
    {
        if (!ctype_digit($number)) {
            throw HttpException::notFound('Question');
        }
        $answers = $this->answersFor($provider, $exam, $version, [(int) $number]);
        return Response::json($answers[(int) $number] ?? throw HttpException::notFound('Question'));
    }

    /**
     * Grade a checked answer and add it to the person's study progress. The letters picked
     * arrive as "picks" ("BE"); the answer they are graded against is the one the reader shows,
     * corrections included. Questions with no answer letters (pictures) cannot be graded.
     */
    public function attempt(string $provider, string $exam, string $version, string $number): Response
    {
        if (!ctype_digit($number)) {
            throw HttpException::notFound('Question');
        }
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $version);
        $picked = self::letters((string) $this->request->input('picks', ''));
        if ($picked === []) {
            throw HttpException::badRequest('Pick an answer first.');
        }
        try {
            $data = $this->s->cache()->load($v);
        } catch (RuntimeException $ex) {
            throw new HttpException(409, $ex->getMessage());
        }
        $q = null;
        foreach ((array) ($data['questions'] ?? []) as $item) {
            if (is_array($item) && (int) ($item['number'] ?? 0) === (int) $number) {
                $q = QuestionView::normalize($item);
            }
        }
        if ($q === null) {
            throw HttpException::notFound('Question');
        }
        $q = QuestionView::applyEdit($q, $this->s->answerEdits()->forExam((int) $e['id'])[QuestionView::key($q)] ?? null);
        $right = self::answerLetters($q);
        if ($right === []) {
            return Response::json(['recorded' => false, 'error' => 'This answer is not a set of letters, so it cannot be checked.'], 422);
        }
        sort($right);
        $correct = $picked === $right;
        $userId = (int) ($this->auth->user()['id'] ?? 0);
        $this->s->progress()->record($userId, (int) $e['id'], QuestionView::key($q), (int) $number, implode('', $picked), $correct, $this->today());
        $totals = $this->s->progress()->forExam($userId, (int) $e['id']);
        return Response::json(['recorded' => true, 'correct' => $correct, 'totals' => $totals,
            'line' => StudyProgress::line($totals, count((array) ($data['questions'] ?? [])))]);
    }

    /** @return list<string> distinct capital letters, sorted: " b,e " gives ["B", "E"] */
    private static function letters(string $text): array
    {
        $letters = array_values(array_unique(str_split((string) preg_replace('/[^A-Z]/', '', strtoupper(substr($text, 0, 64))))));
        $letters = array_values(array_filter($letters, static fn(string $l): bool => $l !== ''));
        sort($letters);
        return array_slice($letters, 0, 16);
    }

    /** @param array<string,mixed> $q @return list<string> the answer's letters, or none when it is not letters */
    private static function answerLetters(array $q): array
    {
        $answer = trim((string) ($q['answer'] ?? ''));
        return preg_match('/^[A-Z]+$/', $answer) === 1 ? str_split($answer) : [];
    }

    /**
     * Answer details for the questions on a practice page, in one request: the page fetches
     * them in the background as it opens, so revealing an answer is instant. Reading the
     * dataset is the slow part, and this reads it once instead of once per question.
     */
    public function answers(string $provider, string $exam, string $version): Response
    {
        $numbers = [];
        foreach (explode(',', (string) $this->request->query('numbers', '')) as $n) {
            if (ctype_digit($n) && count($numbers) < 100) {
                $numbers[] = (int) $n;
            }
        }
        $answers = $this->answersFor($provider, $exam, $version, $numbers);
        return Response::json(['answers' => (object) $answers]);
    }

    /**
     * @param list<int> $numbers
     * @return array<int,array<string,mixed>> question number => what the reveal shows
     */
    private function answersFor(string $provider, string $exam, string $version, array $numbers): array
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $version);
        if ($numbers === []) {
            return [];
        }
        try {
            $data = $this->s->cache()->load($v);
        } catch (RuntimeException $ex) {
            throw new HttpException(409, $ex->getMessage());
        }
        $wanted = array_flip($numbers);
        $edits = $this->s->answerEdits()->forExam((int) $e['id']);
        $out = [];
        foreach ((array) ($data['questions'] ?? []) as $item) {
            if (!is_array($item) || !isset($wanted[(int) ($item['number'] ?? 0)])) {
                continue;
            }
            $q = QuestionView::normalize($item);
            $q = QuestionView::applyEdit($q, $edits[QuestionView::key($q)] ?? null);
            $out[(int) $q['number']] = $this->revealData($q);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $q a normalised question
     * @return array<string,mixed>
     */
    private function revealData(array $q): array
    {
        return [
            'number' => (int) $q['number'],
            'answer' => QuestionView::answerLine($q),
            // The choices to mark as the answer, as study mode does.
            'letters' => self::answerLetters($q),
            'answer_note' => QuestionView::pictureAnswerNote($q),
            'note' => ($q['edit']['note'] ?? '') !== '' ? $q['edit']['note'] : null,
            'votes' => QuestionView::votesLine($q),
            'explanation' => (string) ($q['explanation'] ?? ''),
            'answer_images' => $this->imageSources((array) ($q['answer_images'] ?? [])),
            'comments' => QuestionView::comments($q),
        ];
    }

    /**
     * Addresses of the saved copies (/media/<hash>), keyed by the original image address.
     *
     * @param list<string> $urls
     * @return array<string,string>
     */
    private function localImages(array $urls): array
    {
        $base = (string) $this->app->config()->get('base_path', '');
        return array_map(static fn(string $sha) => \App\Core\View::buildUrl($base, '/media/' . $sha), $this->s->media()->savedHashes($urls));
    }

    /**
     * Where the browser loads each image from: the saved copy, else the original https address.
     *
     * @param list<mixed> $urls
     * @return list<string>
     */
    private function imageSources(array $urls): array
    {
        $urls = array_values(array_filter(array_map('strval', $urls), static fn(string $u) => $u !== ''));
        $local = $this->localImages($urls);
        return array_values(array_filter(array_map(
            static fn(string $u) => $local[$u] ?? QuestionView::safeImageUrl($u), $urls)));
    }

    /**
     * Strip everything answer-revealing from a question before it reaches the template.
     * @param array<string,mixed> $q
     * @return array<string,mixed>
     */
    public static function practiceView(array $q): array
    {
        return [
            'number' => $q['number'] ?? null,
            'topic' => $q['topic'] ?? null,
            'question_number' => $q['question_number'] ?? null,
            'text' => $q['text'] ?? '',
            'text_parts' => $q['text_parts'] ?? null,
            'choices' => $q['choices'] ?? [],
            'question_images' => $q['question_images'] ?? [],
            'source_url' => $q['source_url'] ?? '',
            'picks' => QuestionView::picks($q),
        ];
    }
}
