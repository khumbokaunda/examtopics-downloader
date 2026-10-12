<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Ids;
use App\Services\Documents\Variant;
use RuntimeException;

final class LibraryController extends Controller
{
    public function index(): Response
    {
        $provider = (string) $this->request->query('provider', '');
        $status = (string) $this->request->query('status', '');
        $q = mb_substr(trim((string) $this->request->query('q', '')), 0, 100);
        $filters = [
            'provider' => Ids::isProvider($provider) ? $provider : '',
            'q' => $q,
            'status' => in_array($status, ['complete', 'partial-only'], true) ? $status : '',
        ];
        return $this->render('library/index', [
            'title' => 'Library',
            'nav' => 'library',
            'rows' => $this->s->versions()->library($filters),
            'filters' => $filters,
            'providers' => $this->s->providers()->allWithStats(),
        ]);
    }

    /** Destinations for the command palette: cached exams and providers. */
    public function paletteJson(): Response
    {
        $items = [];
        foreach ($this->s->versions()->library(['provider' => '', 'q' => '', 'status' => '']) as $r) {
            $base = '/library/' . rawurlencode((string) $r['provider_slug']) . '/' . rawurlencode((string) $r['code']);
            $name = (string) ($r['name'] ?? '');
            $label = strtoupper((string) $r['code']) . ($name !== '' && strtolower($name) !== $r['code'] ? ' ' . $name : '');
            $items[] = ['label' => $label, 'section' => 'Read', 'icon' => 'book', 'path' => $this->path($base . '/view')];
            $items[] = ['label' => $label, 'section' => 'Practice', 'icon' => 'patch-question', 'path' => $this->path($base . '/view', ['mode' => 'practice'])];
            $items[] = ['label' => $label, 'section' => 'Documents', 'icon' => 'file-earmark-text', 'path' => $this->path($base)];
        }
        foreach ($this->s->providers()->allWithStats() as $p) {
            $items[] = ['label' => (string) (provider_name($p)), 'section' => 'Provider', 'icon' => 'buildings',
                'path' => $this->path('/providers/' . rawurlencode((string) $p['slug']))];
        }
        return Response::json(['items' => $items]);
    }

    /** @param array<string,string> $query */
    private function path(string $path, array $query = []): string
    {
        return \App\Core\View::buildUrl((string) $this->app->config()->get('base_path', ''), $path, $query);
    }

    public function exam(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $versions = $this->s->versions()->forExam((int) $e['id']);
        if ($versions === []) {
            throw HttpException::notFound('Cached data for this exam');
        }
        $selected = $this->findVersion($e, $this->request->query('version'));
        return $this->render('library/exam', [
            'title' => strtoupper((string) $e['code']),
            'nav' => 'library',
            'provider' => $p,
            'exam' => $e,
            'versions' => $versions,
            'selected' => $selected,
            'documents' => $this->s->documentRepo()->forVersion((int) $selected['id']),
            'variants' => Variant::ALL,
            'imagesAvailable' => $this->s->documents()->imagesAvailable(),
            'jobs' => $this->s->jobs()->list(['provider_id' => (int) $p['id'], 'exam_code' => (string) $e['code']], 20),
            'activeJob' => $this->activeCollectFor($p, $e),
        ]);
    }

    /** Download the canonical dataset of a version (checksum-verified). */
    public function downloadJson(string $provider, string $exam, string $version): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $version);
        $path = $this->s->cache()->datasetPath($v);
        if (!is_file($path) || !hash_equals((string) $v['checksum'], (string) hash_file('sha256', $path))) {
            throw new HttpException(409, 'The cached data file failed its integrity check and cannot be downloaded.');
        }
        return Response::download($path, $this->s->documents()->downloadName($v, (string) $v['version'], 'json'), 'application/json');
    }

    public function deleteVersion(string $provider, string $exam, string $version): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $version);
        try {
            $this->s->cache()->deleteVersion($v, $this->s->documents());
            $this->flash('success', 'Version ' . $v['version'] . ' and its documents were deleted.');
        } catch (RuntimeException $ex) {
            $this->flash('danger', $ex->getMessage());
        }
        return $this->redirect('/library/' . rawurlencode($p['slug']) . '/' . rawurlencode($e['code']));
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $e
     * @return array<string,mixed>|null
     */
    private function activeCollectFor(array $p, array $e): ?array
    {
        $job = $this->s->jobs()->findBySlot('collect');
        return $job !== null && (int) $job['provider_id'] === (int) $p['id'] && $job['exam_code'] === $e['code'] ? $job : null;
    }
}
