<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\View;
use App\Services\Documents\QuestionView;
use App\Services\Editions;
use RuntimeException;

/**
 * Answers corrected or confirmed by hand in the reader. A correction is stored per exam
 * question (answer_edits) and shown over the collected data, in the reader and in documents
 * made after it; the collected data is never changed.
 */
final class AnswerController extends Controller
{
    /**
     * Every answer corrected, checked or annotated for an exam, in one table, so corrections
     * made by someone else (or by a browser assistant acting for you) can be reviewed at once.
     */
    public function index(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->query('version'));
        try {
            $data = $this->s->cache()->load($v);
        } catch (RuntimeException) {
            $data = ['questions' => []];
        }
        $byKey = [];
        foreach ((array) ($data['questions'] ?? []) as $item) {
            if (is_array($item)) {
                $byKey[QuestionView::key($item)] = $item;
            }
        }
        $rows = [];
        foreach ($this->s->answerEdits()->forExam((int) $e['id']) as $key => $edit) {
            $q = isset($byKey[$key]) ? QuestionView::applyEdit(QuestionView::normalize($byKey[$key]), $edit) : null;
            $rows[] = [
                'key' => (string) $key,
                'number' => $q !== null ? (int) ($q['number'] ?? 0) : null,
                'text' => $q !== null ? (string) ($q['text'] ?? '') : '',
                'kind' => $q['edit']['kind'] ?? ((string) $edit['answer'] === '' ? 'note' : 'corrected'),
                'collected' => $q['edit']['collected'] ?? null,
                'answer' => (string) $edit['answer'],
                'note' => trim((string) ($edit['note'] ?? '')),
                'updated_at' => (string) $edit['updated_at'],
            ];
        }
        usort($rows, static fn(array $a, array $b) => ($a['number'] ?? PHP_INT_MAX) <=> ($b['number'] ?? PHP_INT_MAX));
        return $this->render('library/corrections', [
            'title' => Editions::shortCode((string) $e['code']) . ' corrections',
            'nav' => 'library',
            'provider' => $p,
            'exam' => $e,
            'version' => $v,
            'rows' => $rows,
        ]);
    }

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
        $n = (int) ($q['number'] ?? 0);
        $examId = (int) $e['id'];

        // A correction can be removed even when its question is not in this version.
        if ($this->request->input('action') === 'remove') {
            $this->s->answerEdits()->delete($examId, $key);
            $this->flash('success', ($n > 0 ? "Question $n: your" : 'Your') . ' correction was removed, so the collected answer is shown again.');
            $this->app->logger()->info('app', 'Answer correction removed', ['exam' => $e['code'], 'question' => $key]);
            $this->s->audit()->record('answers.removed', ['provider' => provider_name($p), 'exam' => $e['code'], 'question' => $n], 'exam', $p['slug'] . '/' . $e['code']);
            return $this->backToReader($p, $e, $v, $n);
        }
        if ($q === null) {
            throw HttpException::notFound('Question');
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
        $this->s->audit()->record('answers.saved', ['provider' => provider_name($p), 'exam' => $e['code'], 'question' => $n, 'kind' => $edit['kind'],
            'answer' => $letters, 'note' => $note !== ''], 'exam', $p['slug'] . '/' . $e['code']);
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
        if ($this->request->input('return') === 'corrections') {
            return $this->redirect('/library/' . rawurlencode((string) $p['slug']) . '/' . rawurlencode((string) $e['code']) . '/corrections', $query);
        }
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
