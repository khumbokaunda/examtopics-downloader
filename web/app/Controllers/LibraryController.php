<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Ids;
use App\Services\ActiveJobException;
use App\Services\Documents\QuestionView;
use App\Services\Documents\Variant;
use App\Services\Editions;
use App\Services\MediaStore;
use App\Services\ToolCheck;
use RuntimeException;

final class LibraryController extends Controller
{
    /** Library views: exam cards on a shelf per provider, or one table row per exam. */
    public const VIEWS = ['shelves', 'table'];

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
        // Every provider's count comes from the same search, so the chips say what each holds.
        $all = $this->s->versions()->library(['q' => $q, 'status' => $filters['status']]);
        $counts = [];
        foreach ($all as $r) {
            $counts[(string) $r['provider_slug']] = ($counts[(string) $r['provider_slug']] ?? 0) + 1;
        }
        $providers = array_values(array_filter($this->s->providers()->allWithStats(), static fn(array $p): bool => isset($counts[$p['slug']])));
        usort($providers, static fn(array $a, array $b): int => strcasecmp(provider_name($a), provider_name($b)));
        $view = $this->libraryView();
        $userId = (int) ($this->auth->user()['id'] ?? 0);
        $saved = $this->s->progress()->saved($userId);
        $unfiltered = $filters['provider'] === '' && $filters['q'] === '' && $filters['status'] === '';
        return $this->render('library/index', [
            'title' => 'Library',
            'nav' => 'library',
            'rows' => $filters['provider'] === '' ? $all : array_values(array_filter($all, static fn(array $r): bool => $r['provider_slug'] === $filters['provider'])),
            'filters' => $filters,
            'providers' => $providers,
            'counts' => $counts,
            'total' => count($all),
            'view' => $view,
            'staleDays' => max(1, (int) $this->app->config()->get('library.stale_days', 90)),
            'progress' => $this->s->progress()->byExam($userId),
            'saved' => $saved,
            'personal' => $view === 'shelves' && $unfiltered ? $this->personalShelves($userId, $all, $saved) : [],
            'back' => '/library' . (($query = http_build_query(array_filter($filters, static fn(string $v): bool => $v !== ''))) !== '' ? '?' . $query : ''),
        ]);
    }

    /**
     * The person's own shelves above the library: the exams they studied most recently, with
     * where they were, and the exams they saved.
     *
     * @param list<array<string,mixed>> $rows every library row
     * @param array<int,string> $saved
     * @return list<array{name:string,icon:string,cards:list<array{row:array<string,mixed>,place:?array<string,mixed>}>}>
     */
    private function personalShelves(int $userId, array $rows, array $saved): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['exam_id']] = $r;
        }
        $continue = [];
        foreach ($this->s->progress()->recent($userId, 4) as $place) {
            if (isset($byId[$place['exam_id']])) {
                $continue[] = ['row' => $byId[$place['exam_id']], 'place' => $place];
            }
        }
        $mine = [];
        foreach (array_keys($saved) as $id) {
            if (isset($byId[$id])) {
                $mine[] = ['row' => $byId[$id], 'place' => null];
            }
        }
        return array_values(array_filter([
            ['name' => 'Continue studying', 'icon' => 'play-circle', 'cards' => $continue],
            ['name' => 'Saved', 'icon' => 'bookmark', 'cards' => $mine],
        ], static fn(array $shelf): bool => $shelf['cards'] !== []));
    }

    /**
     * Save an exam to the person's own shelf, or take it off. A page that asks for JSON gets
     * the new state; a plain form goes back to the page it came from.
     */
    public function save(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $now = $this->s->progress()->toggleSaved((int) ($this->auth->user()['id'] ?? 0), (int) $e['id']);
        if ($this->request->wantsJson()) {
            return Response::json(['saved' => $now]);
        }
        $this->flash('success', strtoupper((string) $e['code']) . ($now ? ' is saved to your shelf.' : ' is no longer on your shelf.'));
        $back = (string) $this->request->input('back', '');
        // Only back to a page of the library, never to another site ("//host").
        return preg_match('#^/library(?:[/?]|$)#', $back) === 1 && !str_contains($back, '//')
            ? $this->redirect($back)
            : $this->redirect('/library/' . rawurlencode((string) $p['slug']) . '/' . rawurlencode((string) $e['code']));
    }

    /** Forget the person's own answers and place in one exam. */
    public function resetProgress(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $n = $this->s->progress()->reset((int) ($this->auth->user()['id'] ?? 0), (int) $e['id']);
        $this->s->audit()->record('exam.progress_reset', ['provider' => provider_name($p), 'exam' => (string) $e['code'], 'answers' => $n],
            'exam', $p['slug'] . '/' . $e['code']);
        $this->flash('success', 'Your progress in ' . strtoupper((string) $e['code']) . ' was cleared.');
        return $this->redirect('/library/' . rawurlencode((string) $p['slug']) . '/' . rawurlencode((string) $e['code']));
    }

    /** The view asked for (and remembered for next time), or the one this person last chose. */
    private function libraryView(): string
    {
        $user = (array) $this->auth->user();
        $prefs = json_decode((string) ($user['preferences'] ?? ''), true);
        $saved = is_array($prefs) && in_array($prefs['library_view'] ?? null, self::VIEWS, true) ? (string) $prefs['library_view'] : 'shelves';
        $asked = (string) $this->request->query('view', '');
        if (in_array($asked, self::VIEWS, true)) {
            if ($asked !== $saved) {
                $this->s->users()->setPreferences((int) $user['id'], ['library_view' => $asked]);
            }
            return $asked;
        }
        return $saved;
    }

    /**
     * Correct how editions are grouped on the shelves (library.manage): keep this exam on its
     * own card, let it be grouped by name again, show it first on its card, or group it with
     * another exam of the same provider.
     */
    public function editions(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $rows = $this->s->versions()->library(['provider' => (string) $p['slug']]);
        $self = null;
        foreach ($rows as $r) {
            if ((int) $r['exam_id'] === (int) $e['id']) {
                $self = $r;
            }
        }
        $back = '/library/' . rawurlencode((string) $p['slug']) . '/' . rawurlencode((string) $e['code']);
        if ($self === null) {
            return $this->redirect($back);
        }
        $db = $this->app->db();
        $action = (string) $this->request->input('action', '');
        $with = null;
        switch ($action) {
            case 'separate':
                $db->run("UPDATE exams SET edition_group = 'solo', edition_first = 0 WHERE id = ?", [(int) $e['id']]);
                $message = strtoupper((string) $e['code']) . ' now has a card of its own.';
                break;
            case 'auto':
                $db->run('UPDATE exams SET edition_group = NULL WHERE id = ?', [(int) $e['id']]);
                $message = strtoupper((string) $e['code']) . ' is grouped by its name again.';
                break;
            case 'first':
                $key = Editions::key($self);
                foreach ($rows as $r) {
                    if (Editions::key($r) === $key) {
                        $db->run('UPDATE exams SET edition_first = ? WHERE id = ?', [(int) $r['exam_id'] === (int) $e['id'] ? 1 : 0, (int) $r['exam_id']]);
                    }
                }
                $message = strtoupper((string) $e['code']) . ' is now shown first on its card.';
                break;
            case 'join':
                $code = (string) $this->request->input('with', '');
                foreach ($rows as $r) {
                    if ($r['code'] === $code && (int) $r['exam_id'] !== (int) $e['id']) {
                        $with = $r;
                    }
                }
                if ($with === null) {
                    throw HttpException::notFound('Exam to group with');
                }
                $key = Editions::key($with);
                if (str_contains($key, '/code:')) {
                    // The other exam is on its own card: both join a group named after it.
                    $key = $p['slug'] . '/group:' . $with['code'];
                    $db->run('UPDATE exams SET edition_group = ? WHERE id = ?', [$key, (int) $with['exam_id']]);
                }
                $db->run('UPDATE exams SET edition_group = ?, edition_first = 0 WHERE id = ?', [$key, (int) $e['id']]);
                $message = strtoupper((string) $e['code']) . ' is now on one card with ' . strtoupper((string) $with['code']) . '.';
                break;
            default:
                throw HttpException::badRequest('Unknown action.');
        }
        $this->s->audit()->record('exam.editions_changed', array_filter(['provider' => provider_name($p), 'exam' => (string) $e['code'],
            'change' => $action, 'with' => $with['code'] ?? null]), 'exam', $p['slug'] . '/' . $e['code']);
        $this->flash('success', $message);
        return $this->redirect($back);
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
        try {
            $data = $this->s->cache()->load($selected);
            $images = $this->s->media()->summary(MediaStore::urlsOf($data));
            $answers = $this->answerSummary($e, $data);
        } catch (RuntimeException) {
            $images = $answers = null; // the data file is missing or damaged; the page says so elsewhere
        }
        $userId = (int) ($this->auth->user()['id'] ?? 0);
        return $this->render('library/exam', [
            'title' => Editions::shortCode((string) $e['code']),
            'nav' => 'library',
            'provider' => $p,
            'exam' => $e,
            'versions' => $versions,
            'selected' => $selected,
            'documents' => array_map([$this->s->documents(), 'refresh'], $this->s->documentRepo()->forVersion((int) $selected['id'])),
            'variants' => Variant::ALL,
            'images' => $images,
            'answers' => $answers,
            'jobs' => $this->s->jobs()->list(['provider_id' => (int) $p['id'], 'exam_code' => (string) $e['code']], 20),
            'editions' => $this->editionsOf($p, $e),
            'progress' => $this->s->progress()->forExam($userId, (int) $e['id']),
            'place' => $this->s->progress()->placeOf($userId, (int) $e['id']),
            'isSaved' => isset($this->s->progress()->saved($userId)[(int) $e['id']]),
            'activeJob' => $this->activeCollectFor($p, $e),
        ]);
    }

    /**
     * This exam's place among its editions: the others on its card, whether it is shown first,
     * whether an administrator set the grouping, and the exams it could be grouped with.
     *
     * @param array<string,mixed> $p @param array<string,mixed> $e
     * @return array{others:list<array<string,mixed>>,first:bool,override:?string,candidates:list<array<string,mixed>>}
     */
    private function editionsOf(array $p, array $e): array
    {
        $rows = $this->s->versions()->library(['provider' => (string) $p['slug']]);
        $self = null;
        foreach ($rows as $r) {
            if ((int) $r['exam_id'] === (int) $e['id']) {
                $self = $r;
            }
        }
        if ($self === null) {
            return ['others' => [], 'first' => true, 'override' => null, 'candidates' => []];
        }
        $key = Editions::key($self);
        $family = array_values(array_filter($rows, static fn(array $r): bool => Editions::key($r) === $key));
        usort($family, [Editions::class, 'compare']);
        return [
            'others' => array_values(array_filter($family, static fn(array $r): bool => (int) $r['exam_id'] !== (int) $e['id'])),
            'first' => (int) $family[0]['exam_id'] === (int) $e['id'],
            'override' => $self['edition_group'] !== null ? (string) $self['edition_group'] : null,
            'candidates' => array_values(array_filter($rows, static fn(array $r): bool => Editions::key($r) !== $key)),
        ];
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

    /** Queue a download of the version's images that are not saved yet. */
    public function downloadImages(string $provider, string $exam, string $version): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $version);
        if (in_array('-images-from', ToolCheck::missingOptions((string) $this->app->config()->get('tool.binary')), true)) {
            $this->flash('danger', 'The collection tool on the server is older than the web app, so it cannot download images. '
                . ToolCheck::rebuildHint() . ' Then try again.');
            return $this->redirect('/library/' . rawurlencode($p['slug']) . '/' . rawurlencode($e['code']), ['version' => $v['version']]);
        }
        // From the integrity page the job shows in place of the button, so several can be started.
        $back = $this->returnTo('', '/integrity');
        $inPlace = $back !== '' || $this->request->wantsJson();
        try {
            $id = $this->s->jobService()->enqueueImages($v);
        } catch (ActiveJobException $ex) {
            if ($inPlace) {
                return $this->jobStarted($ex->job, false, $ex->getMessage(), $back ?: '/integrity');
            }
            $this->flash('warning', $ex->getMessage());
            return $this->redirect('/library/' . rawurlencode($p['slug']) . '/' . rawurlencode($e['code']), ['version' => $v['version']]);
        }
        $this->s->audit()->record('exam.images_queued', ['provider' => provider_name($p), 'exam' => $e['code'], 'version' => $v['version']],
            'exam', $p['slug'] . '/' . $e['code']);
        if ($inPlace) {
            return $this->jobStarted($this->s->jobs()->find($id), true, 'Image download for ' . strtoupper((string) $e['code']) . ' queued.', $back ?: '/integrity');
        }
        $this->flash('success', 'Image download queued. You can close this page; the job keeps running on the server.');
        return $this->redirect('/jobs/' . (string) $this->s->jobs()->find($id)['uuid']);
    }

    public function deleteVersion(string $provider, string $exam, string $version): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $version);
        try {
            $this->s->cache()->deleteVersion($v, $this->s->documents());
            $this->s->audit()->record('exam.version_deleted', ['provider' => provider_name($p), 'exam' => $e['code'], 'version' => $v['version']],
                'exam', $p['slug'] . '/' . $e['code']);
            $this->flash('success', 'Version ' . $v['version'] . ' and its documents were deleted.');
        } catch (RuntimeException $ex) {
            $this->flash('danger', $ex->getMessage());
        }
        return $this->redirect('/library/' . rawurlencode($p['slug']) . '/' . rawurlencode($e['code']));
    }

    /**
     * Answers to check, and the ones corrected or checked in the reader.
     *
     * @param array<string,mixed> $e
     * @param array<string,mixed> $data
     * @return array{check:int,corrected:int,checked:int,edited:int,fingerprint:?string}
     */
    private function answerSummary(array $e, array $data): array
    {
        $questions = $this->s->corrections()->apply((int) $e['id'], (array) ($data['questions'] ?? []));
        $out = ['check' => 0, 'corrected' => 0, 'checked' => 0, 'edited' => 0, 'fingerprint' => $this->s->corrections()->fingerprint((int) $e['id'])];
        foreach ($questions as $q) {
            $out['check'] += QuestionView::doubts($q) !== [] ? 1 : 0;
            $kind = (string) ($q['edit']['kind'] ?? '');
            if ($kind === 'corrected' || $kind === 'checked') {
                $out[$kind]++;
            }
            $out['edited'] += isset($q['question_edit']) ? 1 : 0;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $e
     * @return array<string,mixed>|null
     */
    private function activeCollectFor(array $p, array $e): ?array
    {
        return $this->s->jobs()->findBySlot(\App\Services\JobService::examSlot((int) $p['id'], (string) $e['code']));
    }
}
