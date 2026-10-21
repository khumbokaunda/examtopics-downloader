<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;

final class LibraryDocumentsTest extends HttpTestCase
{
    private array $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        $job = $this->s->jobs()->find($id);
        self::assertSame('completed', $job['status'], (string) $job['error_summary']);
        $this->version = $this->s->versions()->find((int) $job['cache_version_id']);
    }

    private function base(): string
    {
        return '/library/huawei/h12-811';
    }

    private function generate(string $variant, string $format): array
    {
        $r = $this->post($this->base() . '/' . $this->version['version'] . '/documents', ['variant' => $variant, 'format' => $format]);
        self::assertSame(303, $r->status);
        $flash = $this->flashes();
        self::assertSame('success', $flash[0]['type'] ?? null, $flash[0]['message'] ?? 'no flash');
        return $this->app->db()->one(
            'SELECT * FROM documents WHERE cache_version_id = ? AND variant = ? AND format = ?',
            [(int) $this->version['id'], $variant, $format]
        );
    }

    private function download(array $doc): \App\Core\Http\Response
    {
        return $this->get('/documents/' . $doc['uuid'] . '/download');
    }

    public function testLibraryPagesRender(): void
    {
        $lib = $this->get('/library');
        self::assertSame(200, $lib->status);
        self::assertStringContainsString('H12-811', $lib->body);
        $exam = $this->get($this->base());
        self::assertSame(200, $exam->status);
        self::assertStringContainsString($this->version['version'], $exam->body);
        self::assertStringContainsString('latest complete', $exam->body);
        $dash = $this->get('/');
        self::assertStringContainsString('Exams in the library', $dash->body);
        self::assertMatchesRegularExpression('#kpi-value d-block">5<#', $dash->body, 'dashboard totals come from stored data');
    }

    public function testTheExamPageListsItsEditions(): void
    {
        $exam = $this->get($this->base())->body;
        self::assertStringContainsString('<h1 class="page-title">HCIA-Datacom V1.0</h1>', $exam, 'the name as the title');
        self::assertStringContainsString('No other editions of this exam are in the library.', $exam);

        // A second edition (listed from the database alone) shares the card.
        $pid = (int) $this->provider('huawei')['id'];
        $eid = $this->s->exams()->ensure($pid, 'h12-821', 'HCIA-Datacom V2.0');
        $this->app->db()->run("INSERT INTO cache_versions (exam_id, version, status, source, collected_at, published_at, collected_count, checksum, size_bytes, schema_version)
            VALUES (?, '20260101T000000Z-abcdef', 'complete', 'live', UTC_TIMESTAMP(), UTC_TIMESTAMP(), 9, ?, 10, 1)", [$eid, str_repeat('b', 64)]);
        $this->s->exams()->setLatestVersion($eid, $this->app->db()->lastId());
        $exam = $this->get($this->base())->body;
        self::assertStringContainsString('href="/library/huawei/h12-821">H12-821</a>', $exam);
        self::assertStringContainsString('Show this edition first', $exam, 'H12-821 sorts first, so this one can be put first');
        self::assertStringContainsString('Show separately', $exam);
        self::assertStringNotContainsString('Group by name again', $exam, 'nothing to undo yet');

        $this->post($this->base() . '/editions', ['action' => 'first']);
        $exam = $this->get($this->base())->body;
        self::assertStringContainsString('with this edition first', $exam);
        self::assertStringNotContainsString('Show this edition first', $exam);
        self::assertStringNotContainsString('Group by name again', $exam, 'the order is not a grouping: choosing another edition first changes it back');
        self::assertSame(['h12-811', 'h12-821'], array_column(\App\Services\Editions::shelves($this->s->versions()->library())[0]['cards'][0], 'code'));

        // Someone who only reads sees the editions, not the controls.
        $this->s->users()->create('reader', 'reader password long', \App\Services\Access::MEMBER);
        $this->newSession();
        $this->login('reader', 'reader password long');
        $exam = $this->get($this->base())->body;
        self::assertStringContainsString('href="/library/huawei/h12-821">H12-821</a>', $exam);
        self::assertStringNotContainsString('Show separately', $exam);
        self::assertStringNotContainsString('/editions', $exam);
    }

    public function testPracticeModeDoesNotExposeAnswersInPageSource(): void
    {
        $data = $this->s->cache()->load($this->version);
        $q3 = $data['questions'][2];
        self::assertSame('BE', $q3['answer']);

        $study = $this->get($this->base() . '/view', ['version' => $this->version['version']]);
        self::assertStringContainsString('suggested by ExamTopics', $study->body);
        self::assertStringContainsString('I think it is', $study->body);
        self::assertStringContainsString('Community votes', $study->body);
        self::assertStringContainsString('class="choice is-answer"', $study->body, 'study mode marks the answer choices');

        $practice = $this->get($this->base() . '/view', ['version' => $this->version['version'], 'mode' => 'practice']);
        self::assertSame(200, $practice->status);
        foreach (['suggested by ExamTopics', 'most-voted', 'Community votes', 'I think it is', 'Agreed.', 'Answer:', 'BE ('] as $leak) {
            self::assertStringNotContainsString($leak, $practice->body, "practice page leaks: $leak");
        }
        self::assertStringNotContainsString('is-answer', $practice->body, 'practice mode must not mark the answer choices');
        self::assertStringContainsString('Reveal answer', $practice->body);
        self::assertStringContainsString('OSPF', $practice->body, 'choices are still shown');

        $reveal = $this->get('/api/library/huawei/h12-811/' . $this->version['version'] . '/questions/3');
        $json = json_decode($reveal->body, true);
        self::assertStringStartsWith('BE', $json['answer']);
        self::assertSame(['B', 'E'], $json['letters'], 'the reveal says which choices to mark');
        self::assertNotEmpty($json['comments']);
        self::assertSame(404, $this->get('/api/library/huawei/h12-811/' . $this->version['version'] . '/questions/99')->status);

        // The page fetches all of its answers in one request, so a reveal is instant.
        self::assertStringContainsString('data-practice="huawei/h12-811/' . $this->version['version'] . '"', $practice->body);
        self::assertStringContainsString('data-pickable', $practice->body);
        $batch = json_decode($this->get('/api/library/huawei/h12-811/' . $this->version['version'] . '/answers', ['numbers' => '1,3,99,x'])->body, true);
        self::assertSame(['1', '3'], array_map('strval', array_keys($batch['answers'])));
        self::assertSame(['B', 'E'], $batch['answers']['3']['letters']);
        self::assertSame($json, $batch['answers']['3'], 'the same details as the single reveal');
        self::assertSame([], json_decode($this->get('/api/library/huawei/h12-811/' . $this->version['version'] . '/answers')->body, true)['answers']);
    }

    /** H19-301 is served in the 2026 page layout (inline choices, no suggested answer). */
    public function testTheNewPageLayoutReachesTheReaderAndDocuments(): void
    {
        $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h19-301', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        $job = $this->s->jobs()->find($id);
        self::assertSame('completed', $job['status'], (string) $job['error_summary']);
        $version = $this->s->versions()->find((int) $job['cache_version_id']);
        $data = $this->s->cache()->load($version);
        self::assertCount(5, $data['questions'][0]['choices']);
        self::assertTrue($data['questions'][0]['votes_from_comments']);

        $study = $this->get('/library/huawei/h19-301/view', ['version' => $version['version']])->body;
        self::assertStringContainsString('EIGRP', $study, 'inline choices become real choices');
        self::assertStringContainsString('most-voted community answer', $study);
        self::assertStringContainsString('(counted from the comments)', $study);
        self::assertStringContainsString('selected BE', $study);
        self::assertStringContainsString('comment is-reply', $study);

        $r = $this->post('/library/huawei/h19-301/' . $version['version'] . '/documents', ['variant' => 'discussions', 'format' => 'md']);
        self::assertSame(303, $r->status);
        $doc = $this->app->db()->one("SELECT * FROM documents WHERE cache_version_id = ? AND variant = 'discussions' AND format = 'md'", [(int) $version['id']]);
        $md = (string) file_get_contents($this->download($doc)->file);
        self::assertStringContainsString('selected BE', $md);
        self::assertStringContainsString('> > **bob**', $md, 'a reply is quoted inside its comment');
    }

    public function testPaletteListsCachedExamsAndProviders(): void
    {
        $r = $this->get('/api/palette');
        self::assertSame(200, $r->status);
        $paths = array_column(json_decode($r->body, true)['items'], 'path');
        self::assertContains('/library/huawei/h12-811/view', $paths);
        self::assertContains('/library/huawei/h12-811/view?mode=practice', $paths);
        self::assertContains('/library/huawei/h12-811', $paths);
        self::assertContains('/providers/huawei', $paths);
        self::assertNotContains('/library/huawei/h19-301/view', $paths, 'exams that were never collected are not listed');
    }

    public function testViewerPaginatesAndSearches(): void
    {
        $this->app->config(); // per_page default 10 → 5 questions fit on one page
        $r = $this->get($this->base() . '/view', ['q' => 'question 4']);
        self::assertStringContainsString('Question 4', $r->body);
        self::assertStringNotContainsString('id="question-1"', $r->body);
        self::assertStringContainsString('1 of 5 questions', $r->body);
    }

    public function testAnswersCanBeCorrectedInTheReader(): void
    {
        $v = $this->version['version'];
        $before = $this->generate('study', 'md');
        $r = $this->post($this->base() . '/answers', ['key' => '1-1', 'version' => $v, 'letter_A' => '1', 'letter_C' => '1', 'note' => 'C is also right.', 'page' => '1']);
        self::assertSame(303, $r->status);
        self::assertStringEndsWith('#question-1', $r->headers['Location']);
        self::assertSame('success', $this->flashes()[0]['type'] ?? null);

        $reader = $this->get($this->base() . '/view', ['version' => $v]);
        self::assertStringContainsString('AC (corrected; the collected answer was A)', $reader->body);
        self::assertStringContainsString('C is also right.', $reader->body);
        // The exam page counts it and offers to rebuild the document made before it.
        $exam = $this->get($this->base());
        self::assertStringContainsString('1 corrected', $exam->body);
        self::assertStringContainsString('Rebuild with your corrections', $exam->body);
        $after = $this->generate('study', 'md');
        self::assertNotSame($before['options_hash'], $after['options_hash']);
        $md = (string) file_get_contents($this->download($after)->file);
        self::assertStringContainsString('**Answer:** AC (corrected; the collected answer was A)', $md);
        self::assertStringContainsString('**Note:** C is also right.', $md);

        // The corrections page lists it, and it can be removed from there.
        self::assertStringContainsString('Review corrections', $exam->body);
        $list = $this->get($this->base() . '/corrections', ['version' => $v])->body;
        self::assertStringContainsString('Question 1', $list);
        self::assertStringContainsString('C is also right.', $list);
        self::assertMatchesRegularExpression('#<span class="mono fw-semibold">AC</span>#', $list);
        $r = $this->post($this->base() . '/answers', ['key' => '1-1', 'version' => $v, 'action' => 'remove', 'return' => 'corrections']);
        self::assertStringContainsString('/corrections', $r->headers['Location']);
        self::assertStringContainsString('Nothing corrected yet', $this->get($this->base() . '/corrections', ['version' => $v])->body);

        // Removing it shows the collected answer again: the collected data never changed.
        $this->post($this->base() . '/answers', ['key' => '1-1', 'version' => $v, 'action' => 'remove']);
        self::assertStringNotContainsString('(corrected;', $this->get($this->base() . '/view', ['version' => $v])->body);
        self::assertSame(404, $this->post($this->base() . '/answers', ['key' => '9-99', 'version' => $v, 'letter_A' => '1'])->status);
    }

    public function testDocumentVariantsComeFromCanonicalDataWithoutMutatingIt(): void
    {
        $datasetPath = $this->s->cache()->datasetPath($this->version);
        $checksum = hash_file('sha256', $datasetPath);
        $jobsBefore = (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs');

        $study = $this->generate('study', 'md');
        $md = $this->download($study);
        self::assertSame(200, $md->status);
        $text = (string) file_get_contents($md->file);
        self::assertStringContainsString('**Answer:** BE (suggested by ExamTopics)', $text);
        self::assertStringNotContainsString('### Discussion', $text);
        self::assertStringContainsString('attachment; filename="Huawei_H12-811_Questions_Answers_', $md->headers['Content-Disposition']);

        $disc = (string) file_get_contents($this->download($this->generate('discussions', 'md'))->file);
        self::assertStringContainsString('### Discussion', $disc);
        self::assertStringContainsString('> I think it is BE.', $disc);

        $practice = (string) file_get_contents($this->download($this->generate('practice', 'md'))->file);
        self::assertStringNotContainsString('Answer:', $practice);
        self::assertStringNotContainsString('I think it is', $practice);
        self::assertStringNotContainsString('Community votes', $practice);
        self::assertStringContainsString('OSPF', $practice);

        $key = (string) file_get_contents($this->download($this->generate('practice_key', 'md'))->file);
        self::assertStringContainsString('## Answer key', $key);
        self::assertStringContainsString('| 3 | BE |', $key);
        self::assertStringNotContainsString('**Answer:**', $key);

        $pdfDoc = $this->generate('study', 'pdf');
        $pdf = $this->download($pdfDoc);
        self::assertSame('application/pdf', $pdf->headers['Content-Type']);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($pdf->file, false, null, 0, 5));
        $practicePdf = $this->generate('practice', 'pdf');
        self::assertGreaterThan(1000, (int) $practicePdf['size_bytes']);

        self::assertSame($checksum, hash_file('sha256', $datasetPath), 'documents must not modify canonical data');
        self::assertSame($jobsBefore, (int) $this->app->db()->value('SELECT COUNT(*) FROM jobs'), 'documents never trigger a collection');
    }

    public function testPdfsAreBuiltInTheBackgroundWithProgress(): void
    {
        $this->configure($this->startFixture(), null, ['documents' => ['background' => true], 'setsid' => '/usr/bin/setsid']);
        $this->newSession();
        $this->login();
        $r = $this->post($this->base() . '/' . $this->version['version'] . '/documents', ['variant' => 'study', 'format' => 'pdf']);
        self::assertSame(303, $r->status, 'the request returns at once');
        $flash = $this->flashes()[0] ?? [];
        self::assertSame('info', $flash['type'] ?? null, (string) ($flash['message'] ?? ''));
        $doc = $this->app->db()->one("SELECT * FROM documents WHERE format = 'pdf'");
        self::assertContains($doc['status'], ['building', 'ready']);
        $deadline = time() + 60;
        do {
            $status = json_decode($this->get('/api/documents/' . $doc['uuid'])->body, true);
            if ($status['status'] !== 'building') {
                break;
            }
            usleep(200_000);
        } while (time() < $deadline);
        self::assertSame('ready', $status['status'], (string) ($status['error'] ?? ''));
        self::assertSame([5, 5, 100], [$status['done'], $status['total'], $status['percent']]);
        self::assertStringContainsString('/documents/' . $doc['uuid'] . '/download', $this->get($this->base())->body);

        // A build whose process has gone is shown as failed, so it can be started again.
        $this->app->db()->run("UPDATE documents SET status = 'building', builder_pid = 999999 WHERE id = ?", [(int) $doc['id']]);
        $status = json_decode($this->get('/api/documents/' . $doc['uuid'])->body, true);
        self::assertSame('failed', $status['status']);
        self::assertStringContainsString('stopped before it finished', (string) $status['error']);
        // The build recorded its memory, so the message says how far it got and how much it used.
        self::assertMatchesRegularExpression('/at question 5 of 5, while using about \\d+ MB of memory/', (string) $status['error']);
    }

    public function testLargeExamsGetTheirPdfInParts(): void
    {
        $single = $this->generate('study', 'pdf');
        // Parts of at most 2 questions: the 5-question exam becomes 3 parts, in one build.
        $this->configure($this->startFixture(), null, ['documents' => ['background' => false, 'pdf_part_questions' => 2]]);
        $doc = $this->generate('study', 'pdf');
        $parts = \App\Services\DocumentStore::parts($doc);
        self::assertSame([[1, 2], [3, 4], [5, 5]], array_map(static fn(array $p) => [$p['first'], $p['last']], $parts));
        self::assertNull($this->app->db()->value('SELECT id FROM documents WHERE id = ?', [(int) $single['id']]), 'the single PDF is replaced');
        $dir = dirname($this->app->paths()->documentsRoot() . '/' . $doc['rel_path']);
        self::assertFileDoesNotExist($dir . '/study.pdf', 'and its file removed');

        self::assertStringContainsString('Part 3', $this->get($this->base())->body);
        $r = $this->get('/documents/' . $doc['uuid'] . '/download', ['part' => '2']);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('_Part2of3.pdf', $r->headers['Content-Disposition']);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($r->file, false, null, 0, 5));
        self::assertSame(404, $this->get('/documents/' . $doc['uuid'] . '/download', ['part' => '4'])->status);

        // Deleting the document removes every part.
        $this->post('/documents/' . $doc['uuid'] . '/delete');
        self::assertSame([], glob($dir . '/study.part*.pdf'));
    }

    public function testDocumentsAreReusedAndTamperingIsDetected(): void
    {
        $first = $this->generate('study', 'md');
        $this->app->db()->run("UPDATE documents SET generated_at = '2000-01-01 00:00:00' WHERE id = ?", [(int) $first['id']]);
        $second = $this->generate('study', 'md');
        self::assertSame((int) $first['id'], (int) $second['id']);
        self::assertSame('2000-01-01 00:00:00', $second['generated_at'], 'a valid existing document is reused, not regenerated');

        // Corrupt the file: download is refused; generating again repairs it.
        $path = $this->app->paths()->documentsRoot() . '/' . $first['rel_path'];
        file_put_contents($path, 'tampered');
        self::assertSame(409, $this->download($first)->status);
        $repaired = $this->generate('study', 'md');
        self::assertNotSame('2000-01-01 00:00:00', $repaired['generated_at']);
        self::assertSame(200, $this->download($repaired)->status);

        // A manipulated stored path cannot reach files outside the documents root.
        $this->app->db()->run('UPDATE documents SET rel_path = ? WHERE id = ?', ['../../config/config.php', (int) $first['id']]);
        $r = $this->download($first);
        self::assertSame(409, $r->status);
        self::assertNull($r->file);
    }

    public function testDownloadsRequireAuthentication(): void
    {
        $doc = $this->generate('study', 'md');
        $this->newSession(); // a different, anonymous browser
        $r = $this->download($doc);
        self::assertSame(303, $r->status);
        self::assertNull($r->file);
        $r = $this->get($this->base() . '/' . $this->version['version'] . '/json');
        self::assertSame(303, $r->status);
        self::assertNull($r->file);
    }

    public function testJsonDownloadAndIntegrity(): void
    {
        $r = $this->get($this->base() . '/' . $this->version['version'] . '/json');
        self::assertSame(200, $r->status);
        self::assertSame($this->version['checksum'], hash_file('sha256', $r->file));
        file_put_contents($this->s->cache()->datasetPath($this->version), '{}', FILE_APPEND);
        self::assertSame(409, $this->get($this->base() . '/' . $this->version['version'] . '/json')->status);
    }

    public function testVersionDeletionIsGuarded(): void
    {
        // The latest complete version cannot be deleted.
        $this->post($this->base() . '/' . $this->version['version'] . '/delete');
        self::assertSame('danger', $this->flashes()[0]['type']);
        self::assertNotNull($this->s->versions()->find((int) $this->version['id']));

        // Collect again: the old version becomes deletable, together with its documents.
        $this->generate('study', 'md');
        $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        $docPath = $this->app->paths()->documentDir('huawei', 'h12-811', $this->version['version']) . '/study.md';
        self::assertFileExists($docPath);
        $this->post($this->base() . '/' . $this->version['version'] . '/delete');
        self::assertSame('success', $this->flashes()[0]['type']);
        self::assertNull($this->s->versions()->find((int) $this->version['id']));
        self::assertFileDoesNotExist($docPath);
        self::assertFileDoesNotExist($this->s->cache()->datasetPath($this->version));
        self::assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM cache_versions'));
    }

    public function testRebuildIndexFromManifests(): void
    {
        $this->app->db()->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['documents', 'cache_versions', 'exams', 'providers'] as $t) {
            $this->app->db()->pdo()->exec("TRUNCATE TABLE $t");
        }
        $this->app->db()->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        $report = $this->s->cache()->rebuildIndex();
        self::assertSame(1, $report['indexed']);
        self::assertSame([], $report['skipped']);
        $exam = $this->s->exams()->find((int) $this->s->providers()->findBySlug('huawei')['id'], 'h12-811');
        self::assertNotNull($exam['latest_version_id']);
        self::assertSame(200, $this->get($this->base() . '/view')->status);
    }

    public function testHostileContentIsEscapedEverywhere(): void
    {
        $data = $this->s->cache()->load($this->version);
        $data['questions'][0]['text'] = "# Not a heading\n<script>alert(1)</script> | pipe *bold* [link](javascript:x)\n1. not a list";
        $data['questions'][0]['choices'][0]['text'] = '<img src=x onerror=alert(2)>';
        $data['questions'][0]['question_images'] = ['javascript:alert(3)', 'https://example.com/a.png" onerror="x'];
        $file = sys_get_temp_dir() . '/examtopics-hostile.json';
        file_put_contents($file, json_encode($data));
        $exam = $this->s->exams()->find((int) $this->s->providers()->findBySlug('huawei')['id'], 'h12-811');
        $pub = $this->s->cache()->publish($this->s->providers()->findBySlug('huawei'), $exam, $file, null, []);
        $this->version = $this->s->versions()->find($pub['version_id']);

        $page = $this->get($this->base() . '/view', ['version' => $this->version['version']]);
        self::assertStringNotContainsString('<script>alert(1)', $page->body);
        self::assertStringNotContainsString('<img src=x', $page->body);
        self::assertStringNotContainsString('src="javascript:', $page->body);
        self::assertStringNotContainsString('onerror="x', $page->body);
        self::assertStringContainsString('&lt;script&gt;', $page->body);

        $md = (string) file_get_contents($this->download($this->generate('study', 'md'))->file);
        self::assertStringContainsString('\# Not a heading', $md);
        self::assertStringContainsString('\<script\>', $md);
        self::assertStringContainsString('\|', $md);
        self::assertStringContainsString('\1. not a list', $md);
        self::assertStringNotContainsString('](javascript:alert(3))', $md);

        $html = (new \App\Services\Documents\PdfRenderer())->html($data, [
            'provider' => 'Huawei', 'exam' => 'H12-811', 'exam_code' => 'h12-811', 'version' => 'v', 'collected_at' => 'now', 'status' => 'complete',
        ], \App\Services\Documents\Variant::get('study'));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringStartsWith('%PDF-', substr((string) file_get_contents($this->download($this->generate('study', 'pdf'))->file), 0, 5));
    }
}
