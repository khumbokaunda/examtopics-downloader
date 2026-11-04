<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Repositories\QuestionReportRepository;
use App\Services\Access;
use App\Services\Documents\QuestionView;
use App\Services\Editions;
use RuntimeException;

/**
 * What members add to a question in the reader: their own discussion, where a comment may
 * suggest an answer (their vote), and reports of a problem with the question for the people
 * who correct questions. Like corrections, both are kept per exam question, so they apply to
 * every version of the exam.
 */
final class DiscussionController extends Controller
{
    private const MAX_BODY = 5000;

    /** Post a comment, with or without a suggested answer. */
    public function post(string $provider, string $exam): Response
    {
        [$p, $e, $v, $q] = $this->question($provider, $exam);
        $n = (int) $q['number'];
        $userId = (int) ($this->auth->user()['id'] ?? 0);
        $body = trim(str_replace(["\r\n", "\r"], "\n", mb_substr((string) $this->request->input('body', ''), 0, self::MAX_BODY)));
        $answer = '';
        foreach (QuestionView::choiceLetters($q) as $l) {
            if ($this->request->input('letter_' . $l) === '1') {
                $answer .= $l;
            }
        }
        if ($body === '' && $answer === '') {
            $this->flash('warning', "Question $n: write something, or pick the answer you would choose.");
            return $this->backToReader($p, $e, $v, $n);
        }
        if (!$this->s->throttle()->hit('discussion', (string) $userId, 30, 3600)) {
            $this->flash('warning', 'You have posted a lot in the last hour. Try again a little later.');
            return $this->backToReader($p, $e, $v, $n);
        }
        $this->s->discussions()->add((int) $e['id'], QuestionView::key($q), $userId, $body, $answer === '' ? null : $answer);
        $this->flash('success', "Question $n: " . ($answer !== '' ? "posted, with your vote for $answer." : 'posted.'));
        $this->s->audit()->record('discussion.posted', ['provider' => provider_name($p), 'exam' => $e['code'], 'question' => $n,
            'answer' => $answer !== '' ? $answer : null], 'exam', $p['slug'] . '/' . $e['code']);
        return $this->backToReader($p, $e, $v, $n, 'discussion');
    }

    /** Delete a comment: your own, or anyone's for people who correct questions. */
    public function delete(string $provider, string $exam, string $id): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->input('version'));
        $comment = ctype_digit($id) ? $this->s->discussions()->find((int) $id) : null;
        if ($comment === null || (int) $comment['exam_id'] !== (int) $e['id']) {
            throw HttpException::notFound('Comment');
        }
        $mine = (int) $comment['user_id'] === (int) ($this->auth->user()['id'] ?? 0);
        if (!$mine && !$this->auth->can('answers.edit')) {
            throw new HttpException(403, 'Only its author, or someone who corrects questions, can delete a comment.');
        }
        $n = (int) $this->request->input('number', '0');
        $this->s->discussions()->delete((int) $comment['id']);
        $this->flash('success', 'The comment was deleted' . ($comment['answer'] !== null ? ', with its vote.' : '.'));
        $author = $mine ? null : $this->s->users()->find((int) $comment['user_id']);
        $this->s->audit()->record('discussion.deleted', ['provider' => provider_name($p), 'exam' => $e['code'], 'question' => $n,
            'author' => $author !== null ? Access::displayName($author) : null], 'exam', $p['slug'] . '/' . $e['code']);
        return $this->backToReader($p, $e, $v, $n, 'discussion');
    }

    /** Report a problem with a question to the people who correct questions. */
    public function report(string $provider, string $exam): Response
    {
        [$p, $e, $v, $q] = $this->question($provider, $exam);
        $n = (int) $q['number'];
        $user = (array) $this->auth->user();
        $kind = (string) $this->request->input('kind', '');
        $body = trim(str_replace(["\r\n", "\r"], "\n", mb_substr((string) $this->request->input('body', ''), 0, self::MAX_BODY)));
        if (!isset(QuestionReportRepository::KINDS[$kind])) {
            $this->flash('warning', "Question $n: choose what is wrong.");
            return $this->backToReader($p, $e, $v, $n);
        }
        if ($body === '') {
            $this->flash('warning', "Question $n: say what is wrong, so it can be put right.");
            return $this->backToReader($p, $e, $v, $n);
        }
        if (!$this->s->throttle()->hit('question_report', (string) $user['id'], 20, 3600)) {
            $this->flash('warning', 'You have sent a lot of reports in the last hour. Try again a little later.');
            return $this->backToReader($p, $e, $v, $n);
        }
        $key = QuestionView::key($q);
        $id = $this->s->reports()->add((int) $e['id'], $key, (int) $user['id'], $kind, $body);
        $code = Editions::shortCode((string) $e['code']);
        $this->s->notifier()->notifyPermission('answers.edit', 'question_report', "Problem reported: $code question $n",
            Access::displayName($user) . ': ' . QuestionReportRepository::KINDS[$kind] . '. ' . mb_substr($body, 0, 300),
            '/library/' . rawurlencode((string) $p['slug']) . '/' . rawurlencode((string) $e['code']) . '/view?reported=1#question-' . $n,
            self::resolveKey((int) $e['id'], $key), 'question-report:' . $id, (int) $user['id']);
        $this->flash('success', "Thank you. Your report on question $n was sent to the people who check questions.");
        $this->s->audit()->record('questions.reported', ['provider' => provider_name($p), 'exam' => $e['code'], 'question' => $n,
            'kind' => $kind], 'exam', $p['slug'] . '/' . $e['code']);
        return $this->backToReader($p, $e, $v, $n);
    }

    /** Mark a question's open reports dealt with, and tell the people who sent them. */
    public function resolve(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->input('version'));
        $key = (string) $this->request->input('key', '');
        if (preg_match(QuestionView::KEY_PATTERN, $key) !== 1) {
            throw HttpException::notFound('Question');
        }
        $n = (int) $this->request->input('number', '0');
        $reporters = array_unique(array_filter(array_map(static fn(array $r) => (int) $r['user_id'], $this->s->reports()->open((int) $e['id'])[$key] ?? [])));
        $done = $this->s->reports()->resolveQuestion((int) $e['id'], $key, (int) ($this->auth->user()['id'] ?? 0) ?: null);
        if ($done === 0) {
            $this->flash('info', "Question $n has no open reports.");
            return $this->backToReader($p, $e, $v, $n);
        }
        $this->s->notifier()->resolve(self::resolveKey((int) $e['id'], $key));
        $code = Editions::shortCode((string) $e['code']);
        foreach ($reporters as $userId) {
            $reporter = $this->s->users()->find($userId);
            if ($reporter !== null && $userId !== (int) ($this->auth->user()['id'] ?? 0)) {
                $this->s->notifier()->notify($reporter, 'question_report', "Your report on $code question $n was dealt with",
                    'Thank you for reporting it. Open the question to see it now.',
                    '/library/' . rawurlencode((string) $p['slug']) . '/' . rawurlencode((string) $e['code']) . '/view?jump=' . $n . '#question-' . $n,
                    null, 'question-report-resolved:' . $e['id'] . ':' . $key . ':' . time());
            }
        }
        $this->flash('success', "Question $n: " . ($done === 1 ? 'the report was' : "$done reports were") . ' marked dealt with.');
        $this->s->audit()->record('questions.report_resolved', ['provider' => provider_name($p), 'exam' => $e['code'], 'question' => $n,
            'reports' => $done], 'exam', $p['slug'] . '/' . $e['code']);
        return $this->backToReader($p, $e, $v, $n);
    }

    private static function resolveKey(int $examId, string $key): string
    {
        return 'question-report:' . $examId . ':' . $key;
    }

    /**
     * The provider, exam, version and question (with its corrections) a form names.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>,3:array<string,mixed>}
     */
    private function question(string $provider, string $exam): array
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
        $raw = array_values(array_filter((array) ($data['questions'] ?? []), static fn($item) => is_array($item) && QuestionView::key($item) === $key));
        $q = $this->s->corrections()->apply((int) $e['id'], array_slice($raw, 0, 1))[0] ?? throw HttpException::notFound('Question');
        return [$p, $e, $v, $q];
    }
}
