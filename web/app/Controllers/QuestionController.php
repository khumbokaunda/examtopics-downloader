<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Documents\QuestionView;
use RuntimeException;

/**
 * Questions corrected by hand in the reader: their wording, the wording of their choices, and
 * their pictures (a broken one removed, a better one uploaded). Like answer corrections, an edit
 * is stored per exam question (question_edits) and shown over the collected data, in the reader
 * and in documents made after it, in every version of the exam; the collected data is never
 * changed, so collecting the exam again keeps the edit.
 */
final class QuestionController extends Controller
{
    /** Longest wording kept for a question and for one choice. */
    private const MAX_TEXT = 20000;
    private const MAX_CHOICE = 4000;
    /** Most pictures a question or its answer can have. */
    private const MAX_IMAGES = 30;

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
        $collected = null;
        foreach ((array) ($data['questions'] ?? []) as $item) {
            if (is_array($item) && QuestionView::key($item) === $key) {
                $collected = QuestionView::normalize($item);
                break;
            }
        }
        $n = (int) ($collected['number'] ?? 0);
        $examId = (int) $e['id'];
        $existing = $this->s->questionEdits()->forExam($examId)[$key] ?? null;
        $audit = ['provider' => provider_name($p), 'exam' => $e['code'], 'question' => $n];

        // An edit can be put back even when its question is not in this version.
        if ($this->request->input('action') === 'reset') {
            $this->s->questionEdits()->delete($examId, $key);
            $this->flash('success', ($n > 0 ? "Question $n is" : 'The question is') . ' shown as collected again.');
            $this->app->logger()->info('app', 'Question edit removed', ['exam' => $e['code'], 'question' => $key]);
            $this->s->audit()->record('questions.reset', $audit, 'exam', $p['slug'] . '/' . $e['code']);
            return $this->backToReader($p, $e, $v, $n);
        }
        if ($collected === null) {
            throw HttpException::notFound('Question');
        }
        $current = QuestionView::applyQuestionEdit($collected, $existing);
        $problems = [];

        // Wording. A field the form did not send keeps what was saved before.
        $text = $existing['text'] ?? null;
        $sent = $this->request->input('text');
        if ($sent !== null) {
            $new = QuestionView::fromEditorText(mb_substr($sent, 0, self::MAX_TEXT));
            $original = QuestionView::fromEditorText(QuestionView::editorText($collected));
            if ($new === '' && $original !== '') {
                $problems[] = 'The question cannot be left empty; Put back shows it as collected.';
            } else {
                $text = $new === $original ? null : $new;
            }
        }

        $choices = $existing['choices'] ?? [];
        foreach ((array) ($collected['choices'] ?? []) as $c) {
            $letter = is_array($c) ? (string) ($c['letter'] ?? '') : '';
            $sent = $letter !== '' ? $this->request->input('choice_' . $letter) : null;
            if ($sent === null) {
                continue;
            }
            $new = trim(str_replace(["\r\n", "\r"], "\n", mb_substr($sent, 0, self::MAX_CHOICE)));
            if ($new === '' && trim((string) ($c['text'] ?? '')) !== '' && ((array) ($c['images'] ?? [])) === []) {
                $problems[] = "Choice $letter cannot be left empty.";
                continue;
            }
            if ($new === trim((string) ($c['text'] ?? ''))) {
                unset($choices[$letter]);
            } else {
                $choices[$letter] = $new;
            }
        }

        // Pictures: ticked ones are removed, an uploaded one is added at the end.
        $lists = [];
        $added = 0;
        $removed = 0;
        foreach (['question_images' => 'question', 'answer_images' => 'answer'] as $field => $prefix) {
            $list = [];
            foreach ((array) ($current[$field] ?? []) as $url) {
                if ($this->request->input('remove_' . $prefix . '_' . self::imageId((string) $url)) === '1') {
                    $removed++;
                } else {
                    $list[] = (string) $url;
                }
            }
            $upload = $this->request->file($prefix . '_image');
            if ($upload !== null) {
                $stored = $this->storeUpload($upload);
                if (is_string($stored)) {
                    $problems[] = $stored;
                } elseif (count($list) >= self::MAX_IMAGES) {
                    $problems[] = 'A question or its answer can have up to ' . self::MAX_IMAGES . ' pictures.';
                } elseif (!in_array($stored['url'], $list, true)) {
                    $list[] = $stored['url'];
                    $added++;
                }
            }
            $lists[$field] = $list === array_values(array_map('strval', (array) ($collected[$field] ?? []))) ? null : $list;
        }

        if ($problems !== []) {
            // Nothing is saved, so a half-made edit never shows; an uploaded picture is kept on
            // the server and can be added again.
            $this->flash('warning', "Question $n: nothing was saved. " . implode(' ', $problems));
            return $this->backToReader($p, $e, $v, $n);
        }
        ksort($choices);
        $choices = $choices === [] ? null : $choices;
        $had = $existing['choices'] ?? null;
        if ($had !== null) {
            ksort($had);
        }
        $before = [$existing['text'] ?? null, $had, $existing['question_images'] ?? null, $existing['answer_images'] ?? null];
        if ($before === [$text, $choices, $lists['question_images'], $lists['answer_images']]) {
            $this->flash('info', "Question $n: nothing was changed.");
            return $this->backToReader($p, $e, $v, $n);
        }
        $this->s->questionEdits()->save($examId, $key, $text, $choices, $lists['question_images'], $lists['answer_images'],
            (int) ($this->auth->user()['id'] ?? 0) ?: null);

        $what = array_values(array_filter([
            $text !== ($existing['text'] ?? null) ? 'the wording' : null,
            $choices !== $had ? 'the choices' : null,
            $added > 0 ? ($added === 1 ? 'a picture added' : "$added pictures added") : null,
            $removed > 0 ? ($removed === 1 ? 'a picture removed' : "$removed pictures removed") : null,
        ]));
        $done = $what === [] ? 'saved' : implode(', ', $what);
        $empty = $text === null && $choices === null && $lists['question_images'] === null && $lists['answer_images'] === null;
        $this->flash('success', "Question $n: " . ($empty ? 'back to how it was collected.' : $done . '. Documents made before this can be rebuilt on the exam page.'));
        $this->app->logger()->info('app', 'Question edited', ['exam' => $e['code'], 'question' => $key, 'added' => $added, 'removed' => $removed]);
        $this->s->audit()->record($empty ? 'questions.reset' : 'questions.edited', $audit + ['changed' => $what],
            'exam', $p['slug'] . '/' . $e['code']);
        return $this->backToReader($p, $e, $v, $n);
    }

    /** A short, stable name for a picture in the form, so a removal names the right one. */
    public static function imageId(string $url): string
    {
        return substr(hash('sha256', $url), 0, 12);
    }

    /**
     * @param array{name:string,tmp_name:string,size:int,error:int} $upload
     * @return array{url:string,sha256:string}|string the stored picture, or why it was refused
     */
    private function storeUpload(array $upload): array|string
    {
        if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'That picture is larger than the server accepts (upload_max_filesize in php.ini). Use a smaller one.';
        }
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            return 'The picture did not arrive. Try again.';
        }
        return $this->s->media()->storeUpload($upload['tmp_name'], (int) ($this->auth->user()['id'] ?? 0) ?: null);
    }
}
