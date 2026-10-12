<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Core\Ids;
use App\Services\ActiveJobException;

/**
 * Collect workflow: provider → exam (with reported count and library status) → options →
 * start, or use the existing cached version. A collection is never started automatically
 * when valid cached data exists; refreshing requires an explicit confirmation.
 */
final class CollectController extends Controller
{
    public function index(): Response
    {
        $providers = $this->s->providers()->allWithStats();
        $slug = (string) $this->request->query('provider', '');
        $code = (string) $this->request->query('exam', '');
        $q = mb_substr(trim((string) $this->request->query('q', '')), 0, 100);

        $provider = Ids::isProvider($slug) ? $this->s->providers()->findBySlug($slug) : null;
        $exam = null;
        $versions = [];
        $best = null;
        if ($provider !== null && Ids::isExam($code)) {
            $exam = $this->s->exams()->find((int) $provider['id'], $code);
            if ($exam !== null) {
                $versions = $this->s->versions()->forExam((int) $exam['id']);
                $best = $this->s->versions()->bestForExam((int) $exam['id']);
            }
        }
        return $this->render('collect/index', [
            'title' => 'Collect exam',
            'nav' => 'collect',
            'providers' => $providers,
            'provider' => $provider,
            'exams' => $provider !== null ? $this->s->exams()->forProvider((int) $provider['id'], $q) : [],
            'q' => $q,
            'exam' => $exam,
            'versions' => $versions,
            'best' => $best,
            'documents' => $best !== null ? $this->s->documentRepo()->forVersion((int) $best['id']) : [],
            'activeCollect' => $this->s->jobs()->findBySlot('collect'),
            'allowedRps' => (array) $this->app->config()->get('tool.allowed_rps', [0.25, 0.5, 1.0]),
            'defaultRps' => (float) $this->app->config()->get('tool.default_rps', 1.0),
            'lastRefresh' => $provider !== null ? $this->s->refreshes()->latest((int) $provider['id']) : null,
        ]);
    }

    public function start(): Response
    {
        $provider = $this->findProvider((string) $this->request->input('provider', ''));
        $code = (string) $this->request->input('exam', '');
        $exam = $this->findExam($provider, $code);
        if ((int) $exam['in_inventory'] !== 1 && $exam['latest_version_id'] === null) {
            $this->flash('danger', 'This exam is not in the current inventory. Refresh the provider inventory first.');
            return $this->redirect('/collect', ['provider' => $provider['slug']]);
        }
        $hasCache = $this->s->versions()->bestForExam((int) $exam['id']) !== null;
        if ($hasCache && $this->request->input('confirm_refresh') !== '1') {
            $this->flash('warning', 'This exam is already in your library. Tick "Collect a fresh version" to refresh it; the current version stays available until the new one passes validation.');
            return $this->redirect('/collect', ['provider' => $provider['slug'], 'exam' => $code]);
        }
        $options = $this->s->jobService()->collectOptions($this->request->input('source_mode'), $this->request->input('rps'));
        try {
            $id = $this->s->jobService()->enqueueCollect($provider, $code, $options);
        } catch (ActiveJobException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/collect', ['provider' => $provider['slug'], 'exam' => $code]);
        }
        $this->flash('success', 'Collection queued. You can close this page; the job keeps running on the server.');
        return $this->redirect('/jobs/' . (string) $this->s->jobs()->find($id)['uuid']);
    }
}
