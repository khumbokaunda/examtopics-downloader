<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;

/** The integrity page: what needs checking across every exam, kept per exam until it changes. */
final class IntegrityTest extends HttpTestCase
{
    private const BASE = '/library/huawei/h12-811';
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
        $this->version = (array) $this->s->versions()->find((int) $this->s->jobs()->find($id)['cache_version_id']);
        // Every answer the fixture collects looks right. Reworded to ask for more answers than
        // they have, questions 1 and 2 become ones to check, as the reader also says.
        foreach (['1-1' => 'Which two protocols are described? (Choose two.)', '1-2' => 'Choose three protocols that are described.'] as $key => $text) {
            $this->s->questionEdits()->save($this->examId(), $key, $text, null, null, null, null);
        }
    }

    private function examId(): int
    {
        return (int) $this->version['exam_id'];
    }

    /** The answers the reader says are left to check. */
    private function readerToCheck(): int
    {
        $body = $this->get(self::BASE . '/view', ['version' => $this->version['version']])->body;
        return preg_match('#To check \((\d+)\)#', $body, $m) === 1 ? (int) $m[1] : 0;
    }

    public function testTheExamsToCheckAreListedWithLinksToTheirFixes(): void
    {
        $toCheck = $this->readerToCheck();
        self::assertSame(2, $toCheck);
        $page = $this->get('/integrity');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('1 exam needs checking, of 1 in the library.', $page->body);
        self::assertMatchesRegularExpression('#kpi-value d-block">' . $toCheck . '</span><span class="kpi-label d-block">Answers to check#', $page->body);
        self::assertStringContainsString('href="' . self::BASE . '/view?version=' . $this->version['version'] . '&amp;review=1"', $page->body);
        self::assertStringContainsString('Check ' . $toCheck, $page->body);
        self::assertStringContainsString('href="/integrity"', $this->get('/')->body, 'in the menu');

        // Questions ExamTopics lists that were not collected.
        $this->app->db()->run('UPDATE exams SET reported_count = 8 WHERE id = ?', [$this->examId()]);
        $page = $this->get('/integrity', ['show' => 'missing'])->body;
        self::assertStringContainsString('3 missing', $page);
        self::assertStringContainsString('href="/collect?provider=huawei&amp;exam=h12-811">Collect again</a>', $page);

        // An image not saved can be downloaded from here, staying on the page.
        $this->app->db()->run("UPDATE media SET status = 'failed'");
        $page = $this->get('/integrity', ['show' => 'images'])->body;
        self::assertStringContainsString('1 not saved', $page);
        self::assertStringContainsString('action="' . self::BASE . '/' . $this->version['version'] . '/images" data-job-form', $page);
        $r = $this->post(self::BASE . '/' . $this->version['version'] . '/images', ['back' => '/integrity?show=images']);
        self::assertSame('/integrity?show=images', $r->headers['Location']);
        self::assertSame('Image download for H12-811 queued.', $this->flashes()[0]['message']);
        $again = $this->postJson(self::BASE . '/' . $this->version['version'] . '/images', ['back' => '/integrity']);
        self::assertSame(409, $again['status'], 'one download at a time, shown in place');
        self::assertSame('images', $this->app->db()->value('SELECT type FROM jobs WHERE uuid = ?', [$again['json']['job']['uuid']]));
        $this->drain();

        // A filter that matches nothing says so.
        self::assertStringContainsString('No exam has this problem.', $this->get('/integrity', ['show' => 'documents'])->body);
    }

    public function testFiguresAreKeptUntilTheVersionOrCorrectionsChange(): void
    {
        $toCheck = $this->readerToCheck();
        $this->get('/integrity');
        $row = $this->app->db()->one('SELECT * FROM exam_checks WHERE exam_id = ?', [$this->examId()]);
        self::assertSame($toCheck, (int) $row['to_check']);
        self::assertSame(5, (int) $row['questions']);

        // Kept: a second visit reads the stored figures.
        $this->app->db()->run('UPDATE exam_checks SET to_check = 42 WHERE exam_id = ?', [$this->examId()]);
        self::assertStringContainsString('Check 42', $this->get('/integrity')->body);

        // A document made, then an answer checked: the figures are worked out again, and the
        // document is now one to rebuild.
        $this->s->documents()->obtain($this->version, 'md', 'study');
        self::assertStringContainsString('Up to date', $this->get('/integrity', ['show' => 'all'])->body);
        $doubtful = null;
        $data = $this->s->cache()->load($this->version);
        foreach ($this->s->corrections()->apply($this->examId(), (array) $data['questions']) as $q) {
            if ($doubtful === null && \App\Services\Documents\QuestionView::doubts($q) !== [] && \App\Services\Documents\QuestionView::letters((string) ($q['answer'] ?? '')) !== '') {
                $doubtful = $q;
            }
        }
        self::assertNotNull($doubtful);
        $fields = ['key' => \App\Services\Documents\QuestionView::key($doubtful), 'version' => $this->version['version']];
        foreach (str_split((string) $doubtful['answer']) as $l) {
            $fields['letter_' . $l] = '1';
        }
        $this->post(self::BASE . '/answers', $fields);
        $page = $this->get('/integrity', ['show' => 'all'])->body;
        self::assertSame($toCheck - 1, (int) $this->app->db()->value('SELECT to_check FROM exam_checks WHERE exam_id = ?', [$this->examId()]));
        self::assertStringContainsString('1 to rebuild', $page);
        self::assertStringContainsString('1 checked', $page);
        self::assertSame($toCheck - 1, $this->readerToCheck(), 'the same count as the reader');
    }

    /**
     * Normally the figures are worked out by a background process, so the page never waits on
     * reading every exam: it shows what it has and reloads when the rest is done.
     */
    public function testFiguresAreWorkedOutInTheBackground(): void
    {
        $this->configure($this->startFixture(), null, ['integrity' => ['background' => true], 'setsid' => '/usr/bin/setsid']);
        $this->newSession();
        $this->login();
        $page = $this->get('/integrity')->body;
        self::assertStringContainsString('data-integrity-wait="/api/integrity"', $page);
        self::assertStringContainsString('Checking 1 exam in the background. This page updates when they are done.', $page);
        self::assertStringContainsString('being checked', $page);
        $this->waitForChecks();
        $page = $this->get('/integrity')->body;
        self::assertStringContainsString('Check 2', $page);
        self::assertStringNotContainsString('data-integrity-wait', $page);

        // After a correction, the kept figures show, marked as updating, until the new ones are in.
        $this->post(self::BASE . '/answers', ['key' => '1-1', 'version' => $this->version['version'], 'letter_A' => '1']);
        $page = $this->get('/integrity')->body;
        self::assertStringContainsString('Check 2', $page);
        self::assertStringContainsString('>updating<', $page);
        $this->waitForChecks();
        self::assertStringContainsString('Check 1', $this->get('/integrity')->body);

        // A data file that is gone shows at once, and a check that cannot read it records why
        // rather than trying again on every visit.
        $file = $this->s->cache()->datasetPath($this->version);
        $copy = $file . '.kept';
        rename($file, $copy);
        $page = $this->get('/integrity')->body;
        self::assertStringContainsString('data unreadable', $page);
        self::assertStringNotContainsString('data-integrity-wait', $page);
        $this->s->questionEdits()->delete($this->examId(), '1-2');
        $this->get('/integrity');
        $this->waitForChecks();
        self::assertStringContainsString('missing', (string) $this->app->db()->value('SELECT error FROM exam_checks'));
        self::assertSame(0, $this->s->integrity()->waiting());
        // Put back (a restore, say): it is read again.
        rename($copy, $file);
        touch($file, time() + 5);
        self::assertSame(1, $this->s->integrity()->waiting());
        self::assertSame(1, $this->s->integrity()->workOutAll());
        self::assertNull($this->app->db()->value('SELECT error FROM exam_checks'));
        self::assertSame($this->readerToCheck(), (int) $this->app->db()->value('SELECT to_check FROM exam_checks'));
    }

    /** Wait for the background check, as the page does. */
    private function waitForChecks(): void
    {
        $deadline = time() + 60;
        do {
            $status = json_decode($this->get('/api/integrity')->body, true);
            if ($status['waiting'] === 0) {
                return;
            }
            usleep(200_000);
        } while (time() < $deadline);
        self::fail('The background check did not finish: ' . json_encode($status));
    }
}
