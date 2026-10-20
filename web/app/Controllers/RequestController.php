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
            // Administrators collect exams themselves, so only people who ask see the form.
            'asker' => $this->auth->can('exams.request') && !$this->auth->can('exams.collect'),
        ] + $this->choice());
    }

    /**
     * The provider chosen in the request form, and what to ask next: the exams on its list
     * that are not collected yet, or (for a provider whose list was never loaded) a code.
     *
     * @return array{chosen:?array<string,mixed>,choices:list<array<string,mixed>>,listed:bool,mine:array<string,true>,exam:string}
     */
    private function choice(): array
    {
        $slug = (string) $this->request->query('provider', '');
        $provider = Ids::isProvider($slug) ? $this->s->providers()->findBySlug($slug) : null;
        if ($provider === null) {
            return ['chosen' => null, 'choices' => [], 'listed' => false, 'mine' => [], 'exam' => ''];
        }
        $exams = $this->s->exams()->forProvider((int) $provider['id']);
        return [
            'chosen' => $provider,
            'choices' => array_values(array_filter($exams, static fn(array $e): bool => $e['latest_version'] === null)),
            'listed' => $exams !== [],
            'mine' => $this->s->requests()->openFor((int) $this->auth->user()['id'], (int) $provider['id']),
            'exam' => strtolower((string) $this->request->query('exam', '')),
        ];
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
            $this->s->audit()->record('request.created', ['provider' => provider_name($provider), 'exam' => strtolower(trim($exam))], 'exam', $provider['slug'] . '/' . strtolower(trim($exam)));
            $this->flash('success', 'Requested ' . strtoupper(trim($exam)) . '. You will be notified here' . (\App\Services\Notifier::wantsEmail((array) $this->auth->user()) ? ' and by email' : '') . ' when it is collected.');
        }
        return $this->redirect($back);
    }

    public function answer(string $uuid): Response
    {
        $request = $this->s->requests()->find($uuid) ?? throw HttpException::notFound('Request');
        $status = (string) $this->request->input('status', '');
        if ($request['status'] !== 'open') {
            $this->flash('warning', 'That request has already been answered.');
            return $this->redirect('/requests');
        }
        if (!in_array($status, ['collected', 'declined'], true)) {
            $this->flash('danger', 'Choose "It is in the library" or "Decline".');
            return $this->redirect('/requests');
        }
        $this->s->requests()->answer($request, $status, (string) $this->request->input('response', ''), (array) $this->auth->user());
        $this->s->audit()->record('request.answered', ['provider' => provider_name(['name' => $request['provider_name'], 'slug' => $request['provider_slug']]),
            'exam' => (string) $request['exam_code'], 'status' => $status === 'collected' ? 'collected' : 'declined',
            'requester' => (string) ($request['display_name'] ?: $request['username'])], 'exam', $request['provider_slug'] . '/' . $request['exam_code']);
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
        $this->s->audit()->record('request.cancelled', ['provider' => provider_name(['name' => $request['provider_name'], 'slug' => $request['provider_slug']]),
            'exam' => (string) $request['exam_code']], 'exam', $request['provider_slug'] . '/' . $request['exam_code']);
        $this->flash('success', 'Request cancelled.');
        return $this->redirect('/requests');
    }
}
