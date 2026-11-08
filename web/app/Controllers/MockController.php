<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Documents\QuestionView;
use App\Services\Editions;
use App\Services\MediaStore;
use App\Services\MockExams;
use RuntimeException;

/**
 * Timed mock exams (see App\Services\MockExams): set one up for an exam, take it with no answers
 * in the page, and review the result. An attempt is its owner's alone, addressed by a random id.
 */
final class MockController extends Controller
{
    /** Where to choose the questions from. */
    public const SOURCES = ['all' => 'All questions', 'new' => 'Questions I have not answered yet', 'mistakes' => 'My mistakes',
        'due' => 'Due for review today', 'bookmarked' => 'My bookmarked questions'];

    public function setup(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->query('version'));
        $userId = $this->userId();
        $keys = array_map([QuestionView::class, 'key'], $this->gradable($e, $v));
        return $this->render('mock/setup', [
            'title' => Editions::shortCode((string) $e['code']) . ' mock exam',
            'nav' => 'library',
            'provider' => $p,
            'exam' => $e,
            'version' => $v,
            'available' => array_map(fn(string $source) => count($this->choose($source, $keys, $userId, (int) $e['id'])), array_combine(array_keys(self::SOURCES), array_keys(self::SOURCES))),
            'open' => $this->s->mocks()->open($userId, (int) $e['id']),
            'history' => $this->s->mocks()->history($userId, (int) $e['id']),
        ]);
    }

    public function start(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->input('version'));
        $userId = $this->userId();
        $back = '/library/' . rawurlencode((string) $p['slug']) . '/' . rawurlencode((string) $e['code']) . '/mock';
        $open = $this->s->mocks()->open($userId, (int) $e['id']);
        if ($open !== null) {
            $this->flash('info', 'Finish or give up your current mock exam first.');
            return $this->redirect('/mock/' . $open['uuid']);
        }
        $source = (string) $this->request->input('source', 'all');
        $keys = $this->choose($source, array_map([QuestionView::class, 'key'], $this->gradable($e, $v)), $userId, (int) $e['id']);
        if ($keys === []) {
            $this->flash('warning', 'There are no questions to ask from "' . (self::SOURCES[$source] ?? self::SOURCES['all']) . '". Choose other questions.');
            return $this->redirect($back);
        }
        $count = min(count($keys), max(1, (int) $this->request->input('count', '20')));
        $minutes = $this->request->input('timed') === '1' ? max(1, (int) $this->request->input('minutes', '30')) : 0;
        $mock = $this->s->mocks()->start($userId, (int) $e['id'], (int) $v['id'], $keys, $count, $minutes);
        return $this->redirect('/mock/' . $mock['uuid']);
    }

    /** The attempt: the questions while it is open, the result once it is finished. */
    public function show(string $uuid): Response
    {
        $mock = $this->mine($uuid);
        [$p, $e, $v, $questions] = $this->context($mock);
        if ($mock['finished_at'] === null && MockExams::timeUp($mock, true)) {
            // Left open past its time: finished with what was saved.
            $mock = $this->s->mocks()->finish($mock, null, $questions, $this->today());
            $this->flash('info', 'The time ran out, so your saved answers were graded.');
        }
        $byKey = [];
        foreach ($questions as $q) {
            $byKey[QuestionView::key($q)] = $q;
        }
        $asked = array_values(array_filter(array_map(static fn(string $k) => $byKey[$k] ?? null, $mock['keys'])));
        $base = [
            'nav' => 'library', 'provider' => $p, 'exam' => $e, 'version' => $v, 'mock' => $mock,
            'localImages' => $this->localImages(MediaStore::urlsOf(['questions' => $asked])),
        ];
        if ($mock['finished_at'] === null) {
            return $this->render('mock/exam', $base + [
                'title' => Editions::shortCode((string) $e['code']) . ' mock exam',
                'questions' => array_map([ViewerController::class, 'practiceView'], $asked),
                'secondsLeft' => MockExams::secondsLeft($mock),
            ]);
        }
        $show = (string) $this->request->query('show', '');
        $shown = array_values(array_filter($asked, static function (array $q) use ($mock, $show): bool {
            $r = $mock['results'][QuestionView::key($q)] ?? null;
            return match ($show) {
                'wrong' => $r !== null && !$r['right'],
                'unanswered' => $r !== null && $r['picked'] === '',
                default => true,
            };
        }));
        return $this->render('mock/results', $base + [
            'title' => Editions::shortCode((string) $e['code']) . ' mock exam result',
            'questions' => $shown,
            'show' => $show,
            'missing' => count($mock['keys']) - count($asked),
            'readiness' => \App\Services\Readiness::assess($mock, $this->s->mocks()->history($this->userId(), (int) $mock['exam_id'], 50)),
            'certificate' => $this->s->readiness()->forMock((int) $mock['id']),
            'attempts' => $this->s->mocks()->history($this->userId(), (int) $mock['exam_id'], 6),
        ]);
    }

    /** Save one question's pick as it is made (the page's script). */
    public function save(string $uuid): Response
    {
        $mock = $this->mine($uuid);
        $key = (string) $this->request->input('key', '');
        if ($mock['finished_at'] !== null) {
            return Response::json(['saved' => false, 'error' => 'This mock exam is finished.'], 409);
        }
        if (MockExams::timeUp($mock, true)) {
            return Response::json(['saved' => false, 'error' => 'The time is up.'], 409);
        }
        if (!in_array($key, $mock['keys'], true)) {
            throw HttpException::notFound('Question');
        }
        $this->s->mocks()->save($mock, $key, (string) $this->request->input('picks', ''), $this->request->input('flagged') === '1');
        return Response::json(['saved' => true, 'seconds_left' => MockExams::secondsLeft($mock)]);
    }

    /** Hand it in: graded from the form, or, when sent after the time and its grace, from what was saved. */
    public function finish(string $uuid): Response
    {
        $mock = $this->mine($uuid);
        [, , , $questions] = $this->context($mock);
        if ($mock['finished_at'] !== null) {
            return $this->redirect('/mock/' . $mock['uuid']);
        }
        $picks = null;
        if (!MockExams::timeUp($mock, true)) {
            $byKey = [];
            foreach ($questions as $q) {
                $byKey[QuestionView::key($q)] = $q;
            }
            $picks = [];
            foreach ($mock['keys'] as $i => $key) {
                $letters = '';
                foreach (QuestionView::choiceLetters($byKey[$key] ?? []) as $l) {
                    $letters .= $this->request->input('pick_' . $i . '_' . $l) === '1' ? $l : '';
                }
                $picks[$key] = $letters;
            }
        }
        $mock = $this->s->mocks()->finish($mock, $picks, $questions, $this->today());
        return $this->redirect('/mock/' . $mock['uuid']);
    }

    /** Give up an open attempt without grading it. */
    public function discard(string $uuid): Response
    {
        $mock = $this->mine($uuid);
        if ($mock['finished_at'] === null) {
            $this->s->mocks()->discard($mock);
            $this->flash('success', 'The mock exam was given up. Nothing from it was counted.');
        }
        $exam = (array) $this->app->db()->one('SELECT e.code, p.slug FROM exams e JOIN providers p ON p.id = e.provider_id WHERE e.id = ?', [(int) $mock['exam_id']]);
        return $this->redirect('/library/' . rawurlencode((string) $exam['slug']) . '/' . rawurlencode((string) $exam['code']) . '/mock');
    }

    /**
     * The questions a source offers, of these gradable ones.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    private function choose(string $source, array $keys, int $userId, int $examId): array
    {
        $pick = match ($source) {
            'new' => array_diff_key(array_flip($keys), array_flip($this->s->progress()->answeredKeys($userId, $examId))),
            'mistakes' => array_flip($this->s->progress()->mistakes($userId, $examId)),
            'due' => array_flip($this->s->progress()->dueKeys($userId, $examId, $this->today())),
            'bookmarked' => array_filter($this->s->notes()->forExam($userId, $examId), static fn(array $n) => $n['bookmarked']),
            default => null,
        };
        return $pick === null ? $keys : array_values(array_filter($keys, static fn(string $k) => isset($pick[$k])));
    }

    private function userId(): int
    {
        return (int) ($this->auth->user()['id'] ?? 0);
    }

    /** @return array<string,mixed> the signed-in person's attempt; anyone else's is not found */
    private function mine(string $uuid): array
    {
        $mock = $this->s->mocks()->find($uuid);
        if ($mock === null || (int) $mock['user_id'] !== $this->userId()) {
            throw HttpException::notFound('Mock exam');
        }
        return $mock;
    }

    /**
     * The attempt's provider, exam, version and questions (corrections applied).
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>,3:list<array<string,mixed>>}
     */
    private function context(array $mock): array
    {
        $v = $this->s->versions()->find((int) $mock['version_id'])
            ?? throw new HttpException(409, 'The exam data this mock exam was taken from has been deleted.');
        $p = $this->findProvider((string) $v['provider_slug']);
        $e = $this->findExam($p, (string) $v['exam_code']);
        try {
            $data = $this->s->cache()->load($v);
        } catch (RuntimeException $ex) {
            throw new HttpException(409, $ex->getMessage());
        }
        return [$p, $e, $v, $this->s->corrections()->apply((int) $e['id'], (array) ($data['questions'] ?? []))];
    }

    /** @return list<array<string,mixed>> the version's questions that can be graded */
    private function gradable(array $e, array $v): array
    {
        try {
            $data = $this->s->cache()->load($v);
        } catch (RuntimeException $ex) {
            throw new HttpException(409, $ex->getMessage());
        }
        return MockExams::gradable($this->s->corrections()->apply((int) $e['id'], (array) ($data['questions'] ?? [])));
    }
}
