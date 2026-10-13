<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Core\Ids;
use App\Services\ActiveJobException;
use App\Services\InventoryLinks;
use App\Services\LinkImport;

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
            'knownLinks' => $exam !== null ? $this->links()->forExam((string) $provider['slug'], (string) $exam['code']) : null,
            'addedLinks' => $exam !== null ? count($this->links()->added((string) $provider['slug'], (string) $exam['code'])) : 0,
        ]);
    }

    public function start(): Response
    {
        $provider = $this->findProvider((string) $this->request->input('provider', ''));
        $code = (string) $this->request->input('exam', '');
        $exam = $this->findExam($provider, $code);
        if ((int) $exam['in_inventory'] !== 1 && $exam['latest_version_id'] === null && $this->links()->added((string) $provider['slug'], $code) === []) {
            $this->flash('danger', 'This exam is not in the current inventory. Refresh the provider inventory first.');
            return $this->redirect('/collect', ['provider' => $provider['slug']]);
        }
        $hasCache = $this->s->versions()->bestForExam((int) $exam['id']) !== null;
        if ($hasCache && $this->request->input('confirm_refresh') !== '1') {
            $this->flash('warning', 'This exam is already in your library. Tick "Collect a fresh version" to refresh it; the current version stays available until the new one passes validation.');
            return $this->redirect('/collect', ['provider' => $provider['slug'], 'exam' => $code]);
        }
        $options = $this->s->jobService()->collectOptions($this->request->input('source_mode'), $this->request->input('rps'),
            $this->request->input('full_scan') === '1');
        try {
            $id = $this->s->jobService()->enqueueCollect($provider, $code, $options);
        } catch (ActiveJobException $e) {
            $this->flash('warning', $e->getMessage());
            return $this->redirect('/collect', ['provider' => $provider['slug'], 'exam' => $code]);
        }
        $this->flash('success', 'Collection queued. You can close this page; the job keeps running on the server.');
        return $this->redirect('/jobs/' . (string) $this->s->jobs()->find($id)['uuid']);
    }

    /**
     * Add discussion links for an exam from pasted text: links, or search results copied as
     * text. For exams ExamTopics does not show on its listing pages, these are the only way
     * to find the questions.
     */
    public function addLinks(): Response
    {
        $provider = $this->findProvider((string) $this->request->input('provider', ''));
        $slug = (string) $provider['slug'];
        $text = (string) $this->request->input('links', '');
        $back = ['provider' => $slug];
        if (strlen($text) > LinkImport::MAX_BYTES) {
            $this->flash('danger', 'That is too much text at once. Paste fewer search results pages at a time.');
            return $this->redirect('/collect', $back);
        }
        $r = LinkImport::parse($text, $slug, (string) $this->request->input('exam', ''));
        if ($r['exam'] === null || $r['links'] === []) {
            $this->flash('warning', 'No ' . provider_name($provider) . ' discussion links were found in that text. Paste search results that show titles like'
                . ' "Exam H19-101_V6.0 topic 1 question 1 discussion" with the examtopics.com address under each, or the discussion links themselves.'
                . ($r['other'] > 0 ? ' (' . $r['other'] . ' links were for a different exam than the one entered.)' : ''));
            return $this->redirect('/collect', $back);
        }
        $this->s->exams()->ensure((int) $provider['id'], $r['exam'], $r['name']);
        $total = $this->links()->addLinks($slug, $r['exam'], $r['links']);
        $this->flash('success', count($r['links']) . ' question links read (questions ' . implode(', ', $r['questions']) . '). '
            . strtoupper($r['exam']) . ' now has ' . $total . ' added links. Start the collection below.'
            . ($r['other'] > 0 ? ' ' . $r['other'] . ' links for other exams were skipped.' : ''));
        $this->app->logger()->info('app', 'Discussion links added', ['provider' => $slug, 'exam' => $r['exam'], 'links' => count($r['links']), 'total' => $total]);
        return $this->redirect('/collect', ['provider' => $slug, 'exam' => $r['exam']]);
    }

    /** Forget the links added for an exam. */
    public function clearLinks(): Response
    {
        $provider = $this->findProvider((string) $this->request->input('provider', ''));
        $exam = $this->findExam($provider, (string) $this->request->input('exam', ''));
        $this->links()->clearAdded((string) $provider['slug'], (string) $exam['code']);
        $this->flash('success', 'The links you added for ' . strtoupper((string) $exam['code']) . ' were removed.');
        return $this->redirect('/collect', ['provider' => (string) $provider['slug'], 'exam' => (string) $exam['code']]);
    }

    private function links(): InventoryLinks
    {
        return new InventoryLinks($this->app->paths());
    }
}
