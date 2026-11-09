<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use RuntimeException;

/** What institutions are asked for (institutions.manage): document slots, extra profile fields, optional built-in fields. */
final class PlatformRequirementsController extends Controller
{
    public function index(): Response
    {
        $req = $this->s->onboarding()->requirements();
        return $this->render('platform/requirements', ['title' => 'What institutions provide', 'nav' => 'platform-institutions',
            'documents' => $req->all('document'), 'fields' => $req->all('field'), 'builtins' => $req->all('builtin')]);
    }

    public function save(?string $requirement = null): Response
    {
        $req = $this->s->onboarding()->requirements();
        $r = $requirement !== null ? ($req->find($requirement) ?? throw HttpException::notFound('Requirement')) : null;
        try {
            $req->save($r, $r !== null ? (string) $r['kind'] : (string) $this->request->input('kind', ''), [
                'label' => $this->request->input('label', ''), 'help' => $this->request->input('help', ''), 'field_type' => $this->request->input('field_type', 'text'),
                'options' => $this->request->input('options', ''), 'required' => $this->request->input('required') === '1',
                'applies_to' => array_values(array_filter((array) ($this->request->post['applies_to'] ?? []), 'is_string')),
            ], $this->auth->user());
            $this->flash('success', 'Saved.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/platform/institution-requirements');
    }

    public function toggle(string $requirement): Response
    {
        $r = $this->s->onboarding()->requirements()->find($requirement) ?? throw HttpException::notFound('Requirement');
        $this->s->onboarding()->requirements()->toggle($r);
        return $this->redirect('/platform/institution-requirements');
    }

    public function move(string $requirement): Response
    {
        $r = $this->s->onboarding()->requirements()->find($requirement) ?? throw HttpException::notFound('Requirement');
        $this->s->onboarding()->requirements()->move($r, $this->request->input('to') === 'up' ? -1 : 1);
        return $this->redirect('/platform/institution-requirements');
    }
}
