<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\MediaStore;
use Tests\Support\HttpTestCase;

/** Question images: downloaded after a collection, kept by content, served and embedded locally. */
final class MediaTest extends HttpTestCase
{
    private const IMAGE_PATH = '/assets/media/exam-media/fixture-h12-811.png';

    /** @return array{job:array<string,mixed>,version:array<string,mixed>} */
    private function collect(array $fixtureArgs = [], array $overrides = []): array
    {
        $this->configure($fixtureUrl = $this->startFixture($fixtureArgs), null, $overrides);
        $this->newSession();
        $this->login();
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        $job = (array) $this->s->jobs()->find($id);
        self::assertSame('completed', $job['status'], (string) $job['error_summary']);
        $this->imageUrl = $fixtureUrl . self::IMAGE_PATH;
        return ['job' => $job, 'version' => (array) $this->s->versions()->find((int) $job['cache_version_id'])];
    }

    private string $imageUrl = '';

    public function testCollectionSavesImagesAndTheReaderServesThem(): void
    {
        ['job' => $job, 'version' => $version] = $this->collect();
        self::assertStringContainsString('5 questions saved; 1 of 1 images saved', (string) $job['message']);

        $row = $this->app->db()->one('SELECT * FROM media WHERE url = ?', [$this->imageUrl]);
        self::assertSame('saved', $row['status']);
        self::assertSame('image/png', $row['mime']);
        $sha = (string) $row['sha256'];
        $file = $this->app->paths()->mediaFile($sha, 'png');
        self::assertFileExists($file);
        self::assertSame($sha, hash_file('sha256', $file), 'the file is named by its content');
        self::assertDirectoryDoesNotExist($this->app->paths()->jobDir((int) $job['id']) . '/images', 'the download folder is cleaned up');

        $r = $this->get('/media/' . $sha);
        self::assertSame(200, $r->status);
        self::assertSame('image/png', $r->headers['Content-Type']);
        self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        self::assertStringContainsString('sandbox', $r->headers['Content-Security-Policy']);
        self::assertStringContainsString('immutable', $r->headers['Cache-Control']);
        self::assertSame($file, realpath((string) $r->file));
        foreach ([str_repeat('0', 64), strtoupper($sha), substr($sha, 0, 63), '..%2F..%2Fetc%2Fpasswd', $sha . '.png'] as $bad) {
            self::assertSame(404, $this->get('/media/' . rawurldecode($bad))->status, $bad);
        }

        // The reader uses the saved copy, in study mode and in the practice reveal.
        $study = $this->get('/library/huawei/h12-811/view', ['version' => $version['version']]);
        self::assertStringContainsString('src="/media/' . $sha . '"', $study->body);
        $practice = $this->get('/library/huawei/h12-811/view', ['version' => $version['version'], 'mode' => 'practice']);
        self::assertStringContainsString('src="/media/' . $sha . '"', $practice->body);

        $exam = $this->get('/library/huawei/h12-811');
        self::assertStringContainsString('1 of 1 saved on this server', $exam->body);
        self::assertStringNotContainsString('missing</button>', $exam->body);

        // Signed out, images are not served.
        $this->newSession();
        self::assertSame(303, $this->get('/media/' . $sha)->status);
    }

    public function testPdfsEmbedSavedImagesAndAreRebuiltWhenMoreAreSaved(): void
    {
        ['version' => $version] = $this->collect();
        $sha = (string) $this->app->db()->value('SELECT sha256 FROM media WHERE url = ?', [$this->imageUrl]);
        $doc = $this->s->documents()->obtain($version, 'pdf', 'study');
        self::assertSame(1, json_decode((string) $doc['options'], true)['images']);
        $pdf = (string) file_get_contents((string) $this->s->documents()->verifiedPath($doc));
        self::assertMatchesRegularExpression('#/Subtype\s*/Image#', $pdf, 'the image is embedded');

        // Forget the image: the PDF lists it by address instead, and is a different document.
        $this->app->db()->run('DELETE FROM media');
        @unlink($this->app->paths()->mediaFile($sha, 'png'));
        $without = $this->s->documents()->obtain($version, 'pdf', 'study');
        self::assertSame(0, json_decode((string) $without['options'], true)['images']);
        $pdf = (string) file_get_contents((string) $this->s->documents()->verifiedPath($without));
        self::assertDoesNotMatchRegularExpression('#/Subtype\s*/Image#', $pdf);
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM documents WHERE variant = 'study' AND format = 'pdf'"),
            'the superseded record is removed, so only one download is offered');

        // Download the missing image again from the exam page; the page then offers a rebuild.
        $page = $this->get('/library/huawei/h12-811');
        self::assertStringContainsString('0 of 1 saved on this server', $page->body);
        self::assertStringContainsString('Download 1 missing', $page->body);
        $r = $this->post('/library/huawei/h12-811/' . $version['version'] . '/images');
        self::assertSame(303, $r->status);
        self::assertMatchesRegularExpression('#^/jobs/[0-9a-f-]{36}$#', $r->headers['Location']);
        $job = $this->app->db()->one("SELECT * FROM jobs WHERE type = 'images'");
        self::assertSame(\App\Services\JobService::examSlot((int) $job['provider_id'], 'h12-811'), $job['active_slot'], 'image downloads share their exam\'s collection slot');
        $this->drain();
        $job = (array) $this->s->jobs()->find((int) $job['id']);
        self::assertSame('completed', $job['status'], (string) $job['error_summary']);
        self::assertSame('1 of 1 images saved', $job['message']);
        self::assertSame((int) $version['id'], (int) $job['cache_version_id']);
        self::assertStringContainsString('Rebuild with new images', $this->get('/library/huawei/h12-811')->body);
        $rebuilt = $this->s->documents()->obtain($version, 'pdf', 'study');
        self::assertSame(1, json_decode((string) $rebuilt['options'], true)['images']);
    }

    public function testFailedImagesAreReportedAndRetriedWithoutBlockingTheCollection(): void
    {
        ['job' => $job, 'version' => $version] = $this->collect(['-fail', self::IMAGE_PATH . '=404']);
        self::assertStringContainsString('0 of 1 images saved', (string) $job['message']);
        self::assertStringContainsString('1 could not be downloaded', (string) $job['error_summary']);
        self::assertSame('complete', $job['result_status'], 'a missing image does not make the questions partial');
        self::assertSame('failed', $this->app->db()->value('SELECT status FROM media WHERE url = ?', [$this->imageUrl]));
        self::assertStringContainsString('Download 1 missing', $this->get('/library/huawei/h12-811')->body);

        // Another exam's collection does not block it: the download joins the queue behind it.
        $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h19-301', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->flashes();
        $this->post('/library/huawei/h12-811/' . $version['version'] . '/images');
        self::assertSame('success', $this->flashes()[0]['type'] ?? null);
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM jobs WHERE type = 'images'"));
        // The same exam's download twice is refused.
        $this->post('/library/huawei/h12-811/' . $version['version'] . '/images');
        self::assertSame('warning', $this->flashes()[0]['type'] ?? null);
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM jobs WHERE type = 'images'"));
    }

    public function testAnOutdatedToolIsExplainedNotDumped(): void
    {
        ['version' => $version] = $this->collect();
        $this->app->db()->run('DELETE FROM media');
        $usage = 'for o in -batch -events -out-json -inventory -providers -exam; do echo "  $o" >&2; done';
        // A tool built before -images-from existed: its help lacks the option, and Go's flag
        // package rejects it with exit code 64.
        $old = $this->fakeTool('old-tool', 'if [ "$1" = "-h" ]; then ' . $usage . '; exit 0; fi; '
            . 'echo "flag provided but not defined: -images-from" >&2; echo "Usage of examtopics-downloader:" >&2; ' . $usage . '; exit 64');
        $this->configure($this->startFixture(), $old);
        $this->newSession();
        $this->login();

        // Settings says so, and the exam page refuses to queue a download that cannot work.
        self::assertStringContainsString('out of date', $this->get('/settings')->body);
        $this->post('/library/huawei/h12-811/' . $version['version'] . '/images');
        $flash = $this->flashes()[0] ?? [];
        self::assertSame('danger', $flash['type'] ?? null);
        self::assertStringContainsString('older than the web app', (string) $flash['message']);
        self::assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM jobs WHERE type = 'images'"));

        // A job queued anyway (or the step after a collection) stops early with the same advice.
        $id = $this->s->jobService()->enqueueImages($version);
        $this->drain();
        $job = (array) $this->s->jobs()->find($id);
        self::assertSame('failed', $job['status']);
        self::assertSame('0 of 1 images saved', $job['message']);
        self::assertStringContainsString('older than the web app', (string) $job['error_summary']);
        self::assertStringContainsString('go build -o bin/examtopics-downloader ./cmd', (string) $job['error_summary']);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM media'), 'nothing is marked failed, so a retry after rebuilding fetches all');

        // If an old tool does get run, its usage error is explained rather than shown.
        $half = $this->fakeTool('half-tool', 'if [ "$1" = "-h" ]; then for o in -batch -events -out-json -inventory -providers -exam -images-from -images-dir; '
            . 'do echo "  $o" >&2; done; exit 0; fi; echo "flag provided but not defined: -images-dir" >&2; echo "Usage of examtopics-downloader:" >&2; exit 64');
        $this->configure($this->startFixture(), $half);
        $id = $this->s->jobService()->enqueueImages($version);
        $this->drain();
        $job = (array) $this->s->jobs()->find($id);
        self::assertStringContainsString('it does not know the -images-dir option', (string) $job['error_summary']);
        self::assertStringNotContainsString('Usage of', (string) $job['error_summary']);
    }

    public function testImagesCanBeTurnedOff(): void
    {
        ['job' => $job] = $this->collect([], ['media' => ['download' => false]]);
        self::assertSame('5 questions saved', $job['message']);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM media'));
    }

    public function testUnusedImagesArePrunedOnRequest(): void
    {
        ['version' => $version] = $this->collect();
        $sha = (string) $this->app->db()->value('SELECT sha256 FROM media WHERE url = ?', [$this->imageUrl]);
        $file = $this->app->paths()->mediaFile($sha, 'png');

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/cleanup.php') . ' --media 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        self::assertStringContainsString('Removed 0 unused images', implode("\n", $out), 'images of cached versions are kept');
        self::assertFileExists($file);

        $media = $this->s->media();
        self::assertSame(['addresses' => 1, 'files' => 1, 'bytes' => filesize($file)], $media->prune([], true));
        self::assertFileExists($file, 'a dry run deletes nothing');
        $media->prune(MediaStore::urlsOf($this->s->cache()->load($version)));
        self::assertFileExists($file);
        $media->prune([]);
        self::assertFileDoesNotExist($file);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM media'));
    }

    public function testImportChecksEveryFileAgain(): void
    {
        $this->configure($this->startFixture());
        $dir = $this->app->paths()->storage() . '/import-test';
        mkdir($dir);
        $png = $this->png();
        $good = hash('sha256', $png);
        file_put_contents("$dir/$good.png", $png);
        $text = 'not an image at all';
        $textSha = hash('sha256', $text);
        file_put_contents("$dir/$textSha.png", $text);
        $liar = str_repeat('a', 64);
        file_put_contents("$dir/$liar.png", $png); // name does not match the content
        file_put_contents($this->app->paths()->storage() . '/outside.png', $png);
        $outsideSha = $good;
        symlink($this->app->paths()->storage() . '/outside.png', "$dir/" . str_repeat('b', 64) . '.png');

        $item = static fn(string $url, string $file, string $sha) => ['url' => $url, 'status' => 'saved', 'file' => $file, 'sha256' => $sha];
        $doc = ['images' => [
            $item('https://www.examtopics.com/good.png', "$good.png", $good),
            $item('https://www.examtopics.com/text.png', "$textSha.png", $textSha),
            $item('https://www.examtopics.com/liar.png', "$liar.png", $liar),
            $item('https://www.examtopics.com/link.png', str_repeat('b', 64) . '.png', str_repeat('b', 64)),
            $item('https://www.examtopics.com/escape.png', "../outside.png", $outsideSha),
            $item('https://www.examtopics.com/unrequested.png', "$good.png", $good),
            ['url' => 'https://www.examtopics.com/gone.png', 'status' => 'failed', 'error' => 'HTTP 404 with token ghp_testtokenTESTTOKENtesttoken000000'],
        ]];
        $requested = ['https://www.examtopics.com/good.png', 'https://www.examtopics.com/text.png', 'https://www.examtopics.com/liar.png',
            'https://www.examtopics.com/link.png', 'https://www.examtopics.com/escape.png', 'https://www.examtopics.com/gone.png'];
        $counts = $this->s->media()->import($dir, $doc, $requested);
        self::assertSame(['saved' => 1, 'failed' => 1, 'skipped' => 4], $counts);

        $rows = [];
        foreach ($this->app->db()->all('SELECT url, status, error FROM media') as $r) {
            $rows[basename((string) $r['url'])] = $r;
        }
        self::assertSame('saved', $rows['good.png']['status']);
        foreach (['text.png', 'liar.png', 'link.png', 'escape.png'] as $refused) {
            self::assertSame('skipped', $rows[$refused]['status'], $refused);
        }
        self::assertArrayNotHasKey('unrequested.png', $rows);
        self::assertStringNotContainsString('ghp_', (string) $rows['gone.png']['error'], 'errors are redacted');
        self::assertSame(1, count(glob($this->app->paths()->mediaRoot() . '/*/*') ?: []));

        // A failed image is tried again; a saved or skipped one is not.
        self::assertSame(['https://www.examtopics.com/gone.png', 'https://www.examtopics.com/new.png'],
            $this->s->media()->missing(array_merge($requested, ['https://www.examtopics.com/new.png'])));
        // A later failure never replaces a saved image.
        (new \App\Repositories\MediaRepository($this->app->db()))->record('https://www.examtopics.com/good.png', 'failed', null, null, null, 'x');
        self::assertSame('saved', $this->app->db()->value("SELECT status FROM media WHERE url LIKE '%/good.png'"));
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }
}
