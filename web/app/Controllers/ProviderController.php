<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Ids;
use App\Services\ActiveJobException;

final class ProviderController extends Controller
{
    public function index(): Response
    {
        $this->s->supervisor()->ensure();
        return $this->render('providers/index', [
            'title' => 'Providers & inventory',
            'nav' => 'providers',
            'providers' => $this->s->providers()->allWithStats(),
            'activeJobs' => $this->activeInventoryJobs(),
        ]);
    }

    public function show(string $slug): Response
    {
        $provider = $this->findProvider($slug);
        $q = trim((string) $this->request->query('q', ''));
        return $this->render('providers/show', [
            'title' => (string) (provider_name($provider)),
            'nav' => 'providers',
            'provider' => $provider,
            'q' => mb_substr($q, 0, 100),
            'exams' => $this->s->exams()->forProvider((int) $provider['id'], mb_substr($q, 0, 100)),
            'lastRefresh' => $this->s->refreshes()->latest((int) $provider['id']),
            'lastSuccess' => $this->s->refreshes()->latestSuccessful((int) $provider['id']),
            'activeJob' => $this->s->jobs()->findBySlot('inventory:' . $provider['slug']),
            'mayRefresh' => $this->mayRefresh($provider),
            'requested' => $this->auth->can('exams.request')
                ? $this->s->requests()->openFor((int) $this->auth->user()['id'], (int) $provider['id']) : [],
        ]);
    }

    /**
     * Collectors refresh any provider. Others with inventory.first may load the exam list of a
     * provider that has never been fetched, so they can see what it offers and request exams.
     *
     * @param array<string,mixed> $provider
     */
    private function mayRefresh(array $provider): bool
    {
        return $this->auth->can('exams.collect')
            || ($this->auth->can('inventory.first') && $this->s->providers()->neverFetched((int) $provider['id']));
    }

    public function refresh(string $slug): Response
    {
        $provider = $this->findProvider($slug);
        if (!$this->mayRefresh($provider)) {
            throw new HttpException(403, "This provider's exam list has already been loaded. An administrator keeps it up to date.");
        }
        try {
            $id = $this->s->jobService()->enqueueInventory($provider);
            $this->s->audit()->record('provider.refresh_queued', ['provider' => provider_name($provider)], 'provider', (string) $provider['slug']);
            $this->flash('success', 'Inventory refresh queued (job #' . $id . ').');
        } catch (ActiveJobException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/providers/' . rawurlencode($slug));
    }

    public function discover(): Response
    {
        try {
            $id = $this->s->jobService()->enqueueProviders();
            $this->s->audit()->record('provider.discover_queued');
            $this->flash('success', 'Provider discovery queued (job #' . $id . ').');
        } catch (ActiveJobException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/providers');
    }

    /** Add a provider by slug; it is validated by its first inventory refresh. */
    public function add(): Response
    {
        $slug = strtolower(trim((string) $this->request->input('slug', '')));
        if (!Ids::isProvider($slug)) {
            $this->flash('danger', 'Provider identifiers use lower-case letters, digits and single hyphens, as in ExamTopics URLs (e.g. "huawei").');
            return $this->redirect('/providers');
        }
        $this->s->providers()->upsert($slug, null, 'manual');
        $provider = (array) $this->s->providers()->findBySlug($slug);
        try {
            $this->s->audit()->record('provider.added', ['provider' => $slug], 'provider', $slug);
            $this->s->jobService()->enqueueInventory($provider);
            $this->flash('success', 'Added "' . $slug . '" and queued its inventory refresh. If the refresh fails, check the identifier.');
        } catch (ActiveJobException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/providers/' . rawurlencode($slug));
    }

    /** JSON for the exam picker. */
    public function examsJson(string $slug): Response
    {
        $provider = $this->findProvider($slug);
        $q = mb_substr(trim((string) $this->request->query('q', '')), 0, 100);
        $rows = array_map(static fn(array $e): array => [
            'code' => $e['code'],
            'name' => $e['name'],
            'reported_count' => $e['reported_count'] === null ? null : (int) $e['reported_count'],
            'cached' => $e['latest_version'] !== null,
        ], $this->s->exams()->forProvider((int) $provider['id'], $q));
        return Response::json(['provider' => $provider['slug'], 'exams' => $rows]);
    }

    /** @return array<string,array<string,mixed>> */
    private function activeInventoryJobs(): array
    {
        $out = [];
        foreach ($this->s->jobs()->active() as $j) {
            if ($j['type'] === 'inventory') {
                $out[(string) $j['provider_slug']] = $j;
            } elseif ($j['type'] === 'providers') {
                $out['*'] = $j;
            }
        }
        return $out;
    }
}
