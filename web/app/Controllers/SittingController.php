<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\View;
use App\Services\Sittings;
use RuntimeException;

/**
 * The candidate's side: the assessments assigned to them, the instructions page where a sitting
 * starts, the sitting itself (its own plain layout, without menus or notifications), saving
 * answers as they are picked, and handing in.
 *
 * A sitting is reachable only by the student it belongs to; anyone else, staff included, gets
 * "not found" here (staff see sittings on the assessment's results page).
 */
final class SittingController extends Controller
{
    public function index(): Response
    {
        $uid = (int) $this->auth->user()['id'];
        $this->s->sittings()->expireOverdue();
        return $this->render('sitting/index', ['title' => 'My assessments', 'nav' => 'my-assessments',
            'rows' => $this->s->sittings()->repo()->assignedTo($uid), 'history' => $this->s->sittings()->repo()->history($uid)]);
    }

    public function show(string $institution, string $assessment): Response
    {
        $row = $this->assigned($institution, $assessment);
        return $this->render('sitting/instructions', ['title' => $row['title'], 'nav' => 'my-assessments', 'a' => $row,
            'window' => Sittings::window($row)]);
    }

    public function start(string $institution, string $assessment): Response
    {
        $row = $this->assigned($institution, $assessment);
        try {
            $uuid = $this->s->sittings()->start($row, $this->request->input('accept') === '1');
            return $this->redirect('/sit/' . $uuid);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/my/assessments/' . $institution . '/' . $assessment);
        }
    }

    public function sit(string $uuid): Response
    {
        $session = $this->mine($uuid);
        if ($session['status'] === 'in_progress' && Sittings::secondsLeft($session) <= 0) {
            $this->s->sittings()->submit($session);
            $session = $this->mine($uuid);
        }
        if ($session['status'] !== 'in_progress') {
            return $this->redirect('/sit/' . $uuid . '/done');
        }
        $view = new View($this->app, $this->session, $this->auth->user());
        return Response::html($view->render('sitting/sit', [
            'title' => $session['title'], 'session' => $session, 'questions' => $this->s->sittings()->repo()->questions((int) $session['id']),
            'responses' => $this->s->sittings()->repo()->responses((int) $session['id']), 'secondsLeft' => Sittings::secondsLeft($session),
            'seq' => $this->s->sittings()->repo()->maxSeq((int) $session['id']),
        ], 'sitting/layout'));
    }

    public function save(string $uuid): Response
    {
        $session = $this->mine($uuid);
        $position = (int) $this->request->input('position', '0');
        $seq = (string) $this->request->input('seq', '');
        if (preg_match('/^\d{1,18}$/', $seq) !== 1) {
            return Response::json(['error' => 'Bad request.'], 422);
        }
        try {
            $kept = $this->s->sittings()->save($session, $this->s->sittings()->byPosition((int) $session['id']), $position,
                (string) $this->request->input('answer', ''), $this->request->input('flagged') === '1', (int) $seq);
            return Response::json(['ok' => true, 'kept' => $kept, 'seconds_left' => Sittings::secondsLeft($session)]);
        } catch (RuntimeException $e) {
            $fresh = $this->mine($uuid);
            return Response::json(['error' => $e->getMessage(), 'ended' => $fresh['status'] !== 'in_progress',
                'done_url' => View::buildUrl((string) $this->app->config()->get('base_path', ''), '/sit/' . $uuid . '/done')], $fresh['status'] !== 'in_progress' ? 409 : 422);
        }
    }

    public function status(string $uuid): Response
    {
        $session = $this->mine($uuid);
        if ($session['status'] === 'in_progress' && Sittings::secondsLeft($session) <= 0) {
            $this->s->sittings()->submit($session);
            $session = $this->mine($uuid);
        }
        return Response::json(['status' => $session['status'], 'seconds_left' => $session['status'] === 'in_progress' ? Sittings::secondsLeft($session) : 0]);
    }

    public function submit(string $uuid): Response
    {
        $session = $this->mine($uuid);
        $posted = [];
        foreach ($this->s->sittings()->byPosition((int) $session['id']) as $pos => $q) {
            $v = $this->request->post['a' . $pos] ?? null;
            if (is_array($v)) {
                $posted[$pos] = implode('', array_filter($v, 'is_string'));
            } elseif (is_string($v)) {
                $posted[$pos] = $v;
            } elseif ($this->request->input('sent') === '1') {
                $posted[$pos] = '';
            }
        }
        $this->s->sittings()->submit($session, $posted);
        return $this->redirect('/sit/' . $uuid . '/done');
    }

    public function done(string $uuid): Response
    {
        $session = $this->mine($uuid);
        if ($session['status'] === 'in_progress') {
            return $this->redirect('/sit/' . $uuid);
        }
        $responses = $this->s->sittings()->repo()->responses((int) $session['id']);
        return $this->render('sitting/done', ['title' => $session['title'], 'nav' => 'my-assessments', 'session' => $session,
            'answered' => count(array_filter($responses, static fn(array $r): bool => $r['answer'] !== '')),
            'total' => count($this->s->sittings()->repo()->questions((int) $session['id']))]);
    }

    /**
     * The candidate's own result, once the institution has published results, showing what the
     * assessment's review policy allows: nothing, the score and topics, or every answer too.
     */
    public function result(string $uuid): Response
    {
        $session = $this->released($uuid);
        $questions = $this->s->sittings()->repo()->questions((int) $session['id']);
        return $this->render('sitting/result', ['title' => 'Result: ' . $session['title'], 'nav' => 'my-assessments', 'session' => $session,
            'questions' => $session['review_policy'] === 'answers' ? $questions : [],
            'responses' => $session['review_policy'] === 'answers' ? $this->s->sittings()->repo()->responses((int) $session['id']) : [],
            'topics' => $session['review_policy'] !== 'none' ? $this->s->results()->topics($questions) : []]);
    }

    public function resultPdf(string $uuid): Response
    {
        $session = $this->released($uuid);
        if ($session['review_policy'] === 'none') {
            throw HttpException::notFound('Report');
        }
        $questions = $this->s->sittings()->repo()->questions((int) $session['id']);
        $view = new View($this->app, $this->session, $this->auth->user());
        $html = $view->render('sitting/report_pdf', ['institution' => $session['institution_name'], 'title' => $session['title'], 'sitting' => $session,
            'policy' => $session['review_policy'], 'questions' => $questions, 'responses' => $this->s->sittings()->repo()->responses((int) $session['id']),
            'topics' => $this->s->results()->topics($questions)], null);
        return new Response(\App\Services\Results::pdf($html, $this->app->paths()->storage() . '/tmp/pdf'), 200,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="result.pdf"']);
    }

    /** @return array<string,mixed> the person's own handed-in sitting whose results are published */
    private function released(string $uuid): array
    {
        $session = $this->mine($uuid);
        if ($session['status'] === 'in_progress' || $session['results_published_at'] === null) {
            throw new HttpException(404, 'Your result is not published yet. You are told in your inbox when it is.');
        }
        return $session;
    }

    /** @return array<string,mixed> */
    private function assigned(string $institution, string $assessment): array
    {
        $rows = \App\Core\Ids::isUuid($institution) && \App\Core\Ids::isUuid($assessment)
            ? $this->s->sittings()->repo()->assignedTo((int) $this->auth->user()['id'], $institution, $assessment) : [];
        return $rows[0] ?? throw HttpException::notFound('Assessment');
    }

    /** @return array<string,mixed> the signed-in person's own sitting */
    private function mine(string $uuid): array
    {
        $s = $this->s->sittings()->repo()->session($uuid);
        if ($s === null || (int) $s['user_id'] !== (int) $this->auth->user()['id']) {
            throw HttpException::notFound('Sitting');
        }
        return $s;
    }
}
