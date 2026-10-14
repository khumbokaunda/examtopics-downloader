<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Ids;
use App\Services\ExamRequests;

/**
 * Exam requests. Anyone with exams.request asks for exams; people with requests.manage see
 * every request and answer them. Someone else's request answers 404 to a person who may not
 * see it, so a URL cannot reveal that it exists.
 */
final class RequestController extends Controller
{
    public function index(): Response
    {
        $manager = $this->auth->can('requests.manage');
        $status = (string) $this->request->query('status', $manager ? 'open' : '');
        $status = isset(ExamRequests::STATUSES[$status]) ? $status : '';
        return $this->render('requests/index', [
            'title' => $manager ? 'Exam requests' : 'My requests',
            'nav' => 'requests',
            'manager' => $manager,
            'status' => $status,
            'requests' => $this->s->requests()->list($manager ? null : (int) $this->auth->user()['id'], $status),
            'providers' => $this->s->providers()->allWithStats(),
            'prefill' => [
                'provider' => (string) $this->request->query('provider', ''),
                'exam' => (string) $this->request->query('exam', ''),
            ],
        ]);
    }

    public function store(): Response
    {
        $slug = (string) $this->request->input('provider', '');
        $exam = (string) $this->request->input('exam', '');
        $provider = Ids::isProvider($slug) ? $this->s->providers()->findBySlug($slug) : null;
        $back = $this->request->input('return') === 'provider' && $provider !== null ? '/providers/' . rawurlencode($slug) : '/requests';
        if ($provider === null) {
            $this->flash('danger', 'Choose a provider.');
            return $this->redirect($back);
        }
        [$error] = $this->s->requests()->create((array) $this->auth->user(), $provider, $exam, (string) $this->request->input('note', ''));
        if ($error !== null) {
            $this->flash('warning', $error);
        } else {
            $this->flash('success', 'Requested ' . strtoupper(trim($exam)) . '. You will be notified here' . (\App\Services\Notifier::wantsEmail((array) $this->auth->user()) ? ' and by email' : '') . ' when it is collected.');
        }
        return $this->redirect($back);
    }

    public function answer(string $uuid): Response
    {
        $request = $this->s->requests()->find($uuid) ?? throw HttpException::notFound('Request');
        $status = (string) $this->request->input('status', '');
        if ($request['status'] !== 'open' || !in_array($status, ['collected', 'declined'], true)) {
            $this->flash('warning', 'That request has already been answered.');
            return $this->redirect('/requests');
        }
        $this->s->requests()->answer($request, $status, (string) $this->request->input('response', ''), (array) $this->auth->user());
        $this->flash('success', $status === 'collected'
            ? 'Marked as collected. Everyone who asked for ' . strtoupper((string) $request['exam_code']) . ' has been told.'
            : 'Request declined. ' . ($request['display_name'] ?: $request['username']) . ' has been told.');
        return $this->redirect('/requests');
    }

    public function cancel(string $uuid): Response
    {
        $request = $this->s->requests()->find($uuid);
        if ($request === null || (int) $request['user_id'] !== (int) $this->auth->user()['id']) {
            throw HttpException::notFound('Request');
        }
        $this->s->requests()->cancel($request);
        $this->flash('success', 'Request cancelled.');
        return $this->redirect('/requests');
    }
}
