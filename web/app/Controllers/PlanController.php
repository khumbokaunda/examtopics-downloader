<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use RuntimeException;

/**
 * The commercial side, for the people who look after institutions (institutions.manage): plans,
 * which plan each institution is on, and messages from the public contact page. The public
 * site's own settings are an administrator's (settings.manage).
 */
final class PlanController extends Controller
{
    public function index(): Response
    {
        $editing = null;
        if (($ref = (string) $this->request->query('edit', '')) !== '') {
            $editing = $this->s->plans()->find($ref) ?? throw HttpException::notFound('Plan');
        }
        return $this->render('platform/plans', ['title' => 'Plans', 'nav' => 'platform-plans', 'plans' => $this->s->plans()->all(), 'editing' => $editing,
            'siteOn' => $this->s->publicSite()->on()]);
    }

    public function save(string $plan = ''): Response
    {
        $p = $plan !== '' ? ($this->s->plans()->find($plan) ?? throw HttpException::notFound('Plan')) : null;
        try {
            $this->s->plans()->save($p, $this->request->post);
            $this->flash('success', $p === null ? 'Plan added.' : 'Plan saved.');
        } catch (RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
            return $this->redirect('/platform/plans', $p !== null ? ['edit' => (string) $p['uuid']] : []);
        }
        return $this->redirect('/platform/plans');
    }

    public function archive(string $plan): Response
    {
        $p = $this->s->plans()->find($plan) ?? throw HttpException::notFound('Plan');
        $archive = $this->request->input('archived') === '1';
        $this->s->plans()->archive($p, $archive);
        $this->flash('success', $archive ? $p['name'] . ' is not offered any more. Institutions on it keep it.' : $p['name'] . ' is offered again.');
        return $this->redirect('/platform/plans');
    }

    public function assign(string $ref): Response
    {
        $i = $this->s->institutions()->repo()->findByUuid($ref) ?? throw HttpException::notFound('Institution');
        $uuid = (string) $this->request->input('plan', '');
        $plan = $uuid !== '' ? ($this->s->plans()->find($uuid) ?? throw HttpException::notFound('Plan')) : null;
        try {
            $this->s->plans()->assign($i, $plan, (array) $this->auth->user(), (string) $this->request->input('note', ''));
            $this->flash('success', $plan === null ? $i['name'] . ' has no plan now, so no limits.' : $i['name'] . ' is on ' . $plan['name'] . '.');
        } catch (RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        }
        return $this->redirect('/platform/institutions/' . $i['uuid']);
    }

    public function messages(): Response
    {
        $status = $this->request->query('status') === 'answered' ? 'answered' : 'new';
        return $this->render('platform/messages', ['title' => 'Messages', 'nav' => 'platform-messages', 'status' => $status,
            'messages' => $this->s->publicSite()->messages($status), 'siteOn' => $this->s->publicSite()->on()]);
    }

    public function answered(string $message): Response
    {
        try {
            $this->s->publicSite()->markAnswered($message, (array) $this->auth->user());
        } catch (RuntimeException) {
            throw HttpException::notFound('Message');
        }
        $this->flash('success', 'Marked as answered.');
        return $this->redirect('/platform/messages');
    }

    public function site(): Response
    {
        return $this->render('settings/site', ['title' => 'Public site', 'nav' => 'settings', 'site' => $this->s->publicSite()->settings()]);
    }

    public function saveSite(): Response
    {
        try {
            $this->s->publicSite()->save($this->request->post);
            $this->flash('success', 'Public site saved.');
        } catch (RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        }
        return $this->redirect('/settings/site');
    }
}
