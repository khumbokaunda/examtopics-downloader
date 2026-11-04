<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
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
        $edited = [];
        foreach ($this->s->questionEdits()->forExam((int) $e['id']) as $key => $edit) {
            $q = isset($byKey[$key]) ? QuestionView::applyQuestionEdit(QuestionView::normalize($byKey[$key]), $edit) : null;
            $edited[] = [
                'key' => (string) $key,
                'number' => $q !== null ? (int) ($q['number'] ?? 0) : null,
                'text' => $q !== null ? (string) ($q['text'] ?? '') : (string) ($edit['text'] ?? ''),
                'changes' => self::changes($edit),
                'updated_at' => $edit['updated_at'],
            ];
        }
        $order = static fn(array $a, array $b) => ($a['number'] ?? PHP_INT_MAX) <=> ($b['number'] ?? PHP_INT_MAX);
        usort($rows, $order);
        usort($edited, $order);
        return $this->render('library/corrections', [
            'title' => Editions::shortCode((string) $e['code']) . ' corrections',
            'nav' => 'library',
            'provider' => $p,
            'exam' => $e,
            'version' => $v,
            'rows' => $rows,
            'edited' => $edited,
            'others' => count($this->s->editionCorrections()->others((int) $e['id'], (string) $p['slug'])),
            'history' => $this->s->correctionHistory()->counts((int) $e['id']),
        ]);
    }

    /** Every change to one question's corrections, newest first, with a way to put any earlier state back. */
    public function history(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->query('version'));
        $key = (string) $this->request->query('key', '');
        if (preg_match(QuestionView::KEY_PATTERN, $key) !== 1) {
            throw HttpException::notFound('Question');
        }
        return $this->render('library/history', [
            'title' => Editions::shortCode((string) $e['code']) . ' correction history',
            'nav' => 'library',
            'provider' => $p,
            'exam' => $e,
            'version' => $v,
            'key' => $key,
            'question' => $this->question($e, $v, $key),
            'entries' => $this->s->correctionHistory()->forQuestion((int) $e['id'], $key),
        ]);
    }

    /** Put back what a question's answer correction or question edit was before one of its changes. */
    public function restore(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->input('version'));
        $id = (string) $this->request->input('entry', '');
        $entry = ctype_digit($id) ? $this->s->correctionHistory()->find((int) $id) : null;
        if ($entry === null || (int) $entry['exam_id'] !== (int) $e['id']) {
            throw HttpException::notFound('Change');
        }
        $key = (string) $entry['question_key'];
        $state = $entry['before_state'];
        $userId = (int) ($this->auth->user()['id'] ?? 0) ?: null;
        if ($entry['kind'] === 'answer' && $state === null) {
            $this->s->answerEdits()->delete((int) $e['id'], $key, $userId);
        } elseif ($entry['kind'] === 'answer') {
            $this->s->answerEdits()->save((int) $e['id'], $key, (string) $state['answer'], $state['note'] ?? null, $userId);
        } elseif ($state === null) {
            $this->s->questionEdits()->delete((int) $e['id'], $key, $userId);
        } else {
            $this->s->questionEdits()->save((int) $e['id'], $key, $state['text'] ?? null, $state['choices'] ?? null,
                $state['question_images'] ?? null, $state['answer_images'] ?? null, $userId);
        }
        $q = $this->question($e, $v, $key);
        $n = $q !== null ? (int) $q['number'] : 0;
        $what = $entry['kind'] === 'answer' ? 'answer' : 'question edit';
        $this->flash('success', ($n > 0 ? "Question $n: the " : 'The ') . $what . ($state === null ? ' was taken off, as it was before.' : ' is back to how it was before that change.'));
        $this->s->audit()->record('answers.restored', ['provider' => provider_name($p), 'exam' => $e['code'], 'question' => $n, 'kind' => $entry['kind']],
            'exam', $p['slug'] . '/' . $e['code']);
        return $this->redirect('/library/' . rawurlencode((string) $p['slug']) . '/' . rawurlencode((string) $e['code']) . '/corrections/history',
            ['version' => (string) $v['version'], 'key' => $key]);
    }

    /** @return array<string,mixed>|null the question in this version, corrections applied */
    private function question(array $e, array $v, string $key): ?array
    {
        try {
            $data = $this->s->cache()->load($v);
        } catch (RuntimeException) {
            return null;
        }
        $raw = array_values(array_filter((array) ($data['questions'] ?? []), static fn($item) => is_array($item) && QuestionView::key($item) === $key));
        return $this->s->corrections()->apply((int) $e['id'], array_slice($raw, 0, 1))[0] ?? null;
    }

    /** What carrying this exam's corrections to its other editions would do, to choose from. */
    public function editions(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->query('version'));
        $carry = $this->s->editionCorrections();
        $others = $carry->others((int) $e['id'], (string) $p['slug']);
        try {
            $plan = $carry->plan((int) $e['id'], $v, $others);
        } catch (RuntimeException $ex) {
            throw new HttpException(409, $ex->getMessage());
        }
        return $this->render('library/carry', [
            'title' => Editions::shortCode((string) $e['code']) . ' corrections to other editions',
            'nav' => 'library',
            'provider' => $p,
            'exam' => $e,
            'version' => $v,
            'others' => $others,
            'plan' => $plan,
        ]);
    }

    /** Carry the chosen corrections. */
    public function carry(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->input('version'));
        $carry = $this->s->editionCorrections();
        try {
            $plan = $carry->plan((int) $e['id'], $v, $carry->others((int) $e['id'], (string) $p['slug']));
        } catch (RuntimeException $ex) {
            throw new HttpException(409, $ex->getMessage());
        }
        $chosen = [];
        $n = 0;
        foreach ($plan as $edition) {
            foreach ($edition['items'] as $_) {
                $id = $this->request->input('carry_' . $n++);
                if ($id !== null && $id !== '') {
                    $chosen[] = $id;
                }
            }
        }
        $done = $carry->apply($plan, $chosen, (int) ($this->auth->user()['id'] ?? 0) ?: null);
        $back = '/library/' . rawurlencode((string) $p['slug']) . '/' . rawurlencode((string) $e['code']) . '/corrections';
        if ($done === []) {
            $this->flash('info', 'Nothing was carried: no correction was chosen.');
            return $this->redirect($back, ['version' => (string) $v['version']]);
        }
        $codes = [];
        foreach ($plan as $edition) {
            if (isset($done[(int) $edition['exam']['exam_id']])) {
                $codes[] = Editions::shortCode((string) $edition['exam']['code']) . ' (' . $done[(int) $edition['exam']['exam_id']] . ')';
            }
        }
        $total = array_sum($done);
        $this->flash('success', ($total === 1 ? '1 correction was' : "$total corrections were") . ' carried to ' . implode(', ', $codes) . '.');
        $this->s->audit()->record('answers.carried', ['provider' => provider_name($p), 'exam' => $e['code'], 'corrections' => $total,
            'editions' => array_map(static fn(string $c) => preg_replace('/ \(\d+\)$/', '', $c), $codes)], 'exam', $p['slug'] . '/' . $e['code']);
        return $this->redirect($back, ['version' => (string) $v['version']]);
    }

    /**
     * What a question edit changed, in words: "wording", "choices B, C", "2 question pictures".
     *
     * @param array<string,mixed> $edit
     * @return list<string>
     */
    private static function changes(array $edit): array
    {
        $count = static fn(?array $list, string $what): ?string => $list === null ? null
            : (count($list) === 0 ? "no $what pictures" : count($list) . " $what " . (count($list) === 1 ? 'picture' : 'pictures'));
        return array_values(array_filter([
            $edit['text'] !== null ? 'wording' : null,
            $edit['choices'] !== null ? (count($edit['choices']) === 1 ? 'choice ' : 'choices ') . implode(', ', array_keys($edit['choices'])) : null,
            $count($edit['question_images'], 'question'),
            $count($edit['answer_images'], 'answer'),
        ]));
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
            $this->s->answerEdits()->delete($examId, $key, (int) ($this->auth->user()['id'] ?? 0) ?: null);
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
}
