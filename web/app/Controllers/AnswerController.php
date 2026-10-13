<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\View;
use App\Services\Documents\QuestionView;
use RuntimeException;

/**
 * Answers corrected or confirmed by hand in the reader. A correction is stored per exam
 * question (answer_edits) and shown over the collected data, in the reader and in documents
 * made after it; the collected data is never changed.
 */
final class AnswerController extends Controller
{
    public function save(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->input('version'));
        $key = (string) $this->request->input('key', '');
        if (preg_match(QuestionView::KEY_PATTERN, $key) !== 1) {
            throw HttpException::notFound('Question');
        }
        try {
            $data = $this->s->cache()->load($v);
        } catch (RuntimeException $ex) {
            throw new HttpException(409, $ex->getMessage());
        }
        $q = null;
        foreach ((array) ($data['questions'] ?? []) as $item) {
            if (is_array($item) && QuestionView::key($item) === $key) {
                $q = QuestionView::normalize($item);
                break;
            }
        }
        if ($q === null) {
            throw HttpException::notFound('Question');
        }
        $n = (int) ($q['number'] ?? 0);
        $examId = (int) $e['id'];

        if ($this->request->input('action') === 'remove') {
            $this->s->answerEdits()->delete($examId, $key);
            $this->flash('success', "Question $n: your correction was removed, so the collected answer is shown again.");
            $this->app->logger()->info('app', 'Answer correction removed', ['exam' => $e['code'], 'question' => $key]);
            return $this->backToReader($p, $e, $v, $n);
        }

        $letters = '';
        foreach (QuestionView::choiceLetters($q) as $l) {
            if ($this->request->input('letter_' . $l) === '1') {
                $letters .= $l;
            }
        }
        $note = trim(mb_substr(str_replace("\r\n", "\n", (string) $this->request->input('note', '')), 0, 2000));
        if ($letters === '' && $note === '') {
            $this->flash('warning', "Question $n: nothing was saved. Tick the correct letters, or write a note.");
            return $this->backToReader($p, $e, $v, $n);
        }
        $this->s->answerEdits()->save($examId, $key, $letters, $note === '' ? null : $note, (int) ($this->auth->user()['id'] ?? 0) ?: null);
        $edit = QuestionView::applyEdit($q, ['answer' => $letters, 'note' => $note])['edit'];
        $this->flash('success', match ($edit['kind']) {
            'corrected' => "Question $n: the answer is now $letters. Documents made before this can be rebuilt on the exam page.",
            'checked' => "Question $n: answer $letters marked as checked.",
            default => "Question $n: note saved.",
        });
        $this->app->logger()->info('app', 'Answer corrected', ['exam' => $e['code'], 'question' => $key, 'kind' => $edit['kind'], 'answer' => $letters]);
        return $this->backToReader($p, $e, $v, $n);
    }

    /**
     * The reader page the form was on, at the question.
     *
     * @param array<string,mixed> $p
     * @param array<string,mixed> $e
     * @param array<string,mixed> $v
     */
    private function backToReader(array $p, array $e, array $v, int $n): Response
    {
        $query = ['version' => (string) $v['version']];
        $page = (int) $this->request->input('page', '1');
        if ($page > 1) {
            $query['page'] = (string) $page;
        }
        $search = mb_substr(trim((string) $this->request->input('q', '')), 0, 100);
        if ($search !== '') {
            $query['q'] = $search;
        }
        if ($this->request->input('review') === '1') {
            $query['review'] = '1';
        }
        $path = '/library/' . rawurlencode((string) $p['slug']) . '/' . rawurlencode((string) $e['code']) . '/view';
        return Response::redirect(View::buildUrl((string) $this->app->config()->get('base_path', ''), $path, $query) . '#question-' . $n);
    }
}
