<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use App\Services\AuditText;
use Tests\Support\HttpTestCase;

/** The library's shelves and table views, the search, and correcting how editions are grouped. */
final class LibraryShelvesTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
        $this->exam('amazon', 'Amazon', 'aws-certified-solutions-architect-associate-saa-c02', 'AWS Solutions Architect Associate SAA-C02', '-400 days');
        $this->exam('amazon', 'Amazon', 'aws-certified-solutions-architect-associate-saa-c03', 'AWS Solutions Architect Associate SAA-C03', '-3 days');
        $this->exam('amazon', 'Amazon', 'aws-certified-cloud-practitioner', 'AWS Certified Cloud Practitioner', '-10 days');
        $this->exam('microsoft', 'Microsoft', 'az-104', 'Microsoft Azure Administrator', '-1 day', 'partial');
        $this->exam('microsoft', 'Microsoft', 'az-500', 'Microsoft Azure Security Technologies', '-2 days');
    }

    /** An exam with one version, written straight to the database (no files are needed to list it). */
    private function exam(string $slug, string $provider, string $code, string $name, string $when, string $status = 'complete'): int
    {
        $db = $this->app->db();
        $pid = $this->s->providers()->upsert($slug, $provider, 'manual');
        $eid = $this->s->exams()->ensure($pid, $code, $name);
        $at = gmdate('Y-m-d H:i:s', (int) strtotime($when));
        $db->run("INSERT INTO cache_versions (exam_id, version, status, source, collected_at, published_at, collected_count, checksum, size_bytes, schema_version)
                  VALUES (?, ?, ?, 'live', ?, ?, 42, ?, 1000, 1)", [$eid, gmdate('Ymd\THis\Z', (int) strtotime($when)) . '-' . substr(md5($code), 0, 6), $status, $at, $at, str_repeat('a', 64)]);
        if ($status === 'complete') {
            $this->s->exams()->setLatestVersion($eid, $db->lastId());
        }
        return $eid;
    }

    /** @return list<string> the codes on each card, in page order */
    private function cards(string $body): array
    {
        preg_match_all('#<article class="card exam-card".*?</article>#s', $body, $m);
        return array_map(static function (string $card): string {
            preg_match_all('#href="/library/[a-z]+/([a-z0-9-]+)"#', $card, $codes);
            return implode(',', array_values(array_unique($codes[1])));
        }, $m[0]);
    }

    public function testShelvesAreTheDefaultAndGroupEditions(): void
    {
        $page = $this->get('/library')->body;
        self::assertStringContainsString('data-shelf-search', $page);
        self::assertStringNotContainsString('id="library-table"', $page);
        self::assertSame([
            'aws-certified-cloud-practitioner',
            'aws-certified-solutions-architect-associate-saa-c03,aws-certified-solutions-architect-associate-saa-c02',
            'az-104', 'az-500',
        ], $this->cards($page), 'one card per exam, editions together and the newest first, providers in name order');
        self::assertStringContainsString('>SAA-C03</span>', $page, 'the short code under the title');
        self::assertStringNotContainsString('>AWS-CERTIFIED-CLOUD-PRACTITIONER</span>', $page, 'not when the name already says it');
        self::assertStringContainsString('title="AWS-CERTIFIED-SOLUTIONS-ARCHITECT-ASSOCIATE-SAA-C03"', $page, 'the full code on hover');
        self::assertStringContainsString('>SAA-C02</a>', $page, 'the other edition linked by its short code');
        self::assertStringContainsString('href="/library/microsoft/az-104/view?mode=practice"', $page, 'read and practise from the card');
        self::assertSame(1, substr_count($page, '>Partial</span>'), 'status shows only for a problem');
        self::assertMatchesRegularExpression('#Amazon <span class="filter-chip-count">3</span>#', $page, 'provider chips count exams');
        self::assertMatchesRegularExpression('#All <span class="filter-chip-count">5</span>#', $page);

        $amazon = $this->get('/library', ['provider' => 'amazon'])->body;
        self::assertCount(2, $this->cards($amazon));
        self::assertMatchesRegularExpression('#Microsoft <span class="filter-chip-count">2</span>#', $amazon, 'the other chips still count');
    }

    public function testOneSearchBoxFindsNamesCodesAndProviders(): void
    {
        $codes = fn(array $q): array => $this->cards($this->get('/library', $q)->body);
        self::assertSame(['az-104', 'az-500'], $codes(['q' => 'microsoft']), 'a provider');
        self::assertSame(['aws-certified-solutions-architect-associate-saa-c03'], $codes(['q' => 'saa c03']), 'every word must match');
        self::assertSame(['az-500'], $codes(['q' => 'azure security']), 'words of the name in any order');
        self::assertSame(['az-104'], $codes(['q' => 'AZ-104']));
        self::assertStringContainsString('Nothing matches', $this->get('/library', ['q' => 'oracle'])->body);
    }

    public function testTheTableViewIsRememberedPerPerson(): void
    {
        $table = $this->get('/library', ['view' => 'table'])->body;
        self::assertStringContainsString('id="library-table"', $table);
        self::assertStringContainsString('title="AWS-CERTIFIED-SOLUTIONS-ARCHITECT-ASSOCIATE-SAA-C02">SAA-C02</a>', $table, 'short code, full code on hover');
        self::assertStringContainsString('class="cell-wrap', $table, 'names may wrap');
        self::assertSame(1, substr_count($table, 'older than 90 days'), 'only the exam collected 400 days ago is flagged');

        self::assertStringContainsString('id="library-table"', $this->get('/library')->body, 'remembered');
        $prefs = json_decode((string) $this->s->users()->findByUsername('admin')['preferences'], true);
        self::assertSame('table', $prefs['library_view']);

        // Someone else still gets shelves; a view that does not exist changes nothing.
        $this->s->users()->create('member', 'member password long', Access::MEMBER);
        $this->newSession();
        $this->login('member', 'member password long');
        $member = $this->get('/library', ['view' => 'list'])->body;
        self::assertStringContainsString('data-shelf-search', $member);
        self::assertStringNotContainsString('older than', $this->get('/library', ['view' => 'table'])->body, 'the stale hint is for collectors');
    }

    public function testEditionsCanBeSplitJoinedAndOrdered(): void
    {
        $base = '/library/amazon/aws-certified-solutions-architect-associate-saa-c02';
        self::assertSame(303, $this->post($base . '/editions', ['action' => 'separate'])->status);
        self::assertSame('AWS-CERTIFIED-SOLUTIONS-ARCHITECT-ASSOCIATE-SAA-C02 now has a card of its own.', $this->flashes()[0]['message']);
        self::assertCount(5, $this->cards($this->get('/library')->body));

        $this->post($base . '/editions', ['action' => 'auto']);
        self::assertCount(4, $this->cards($this->get('/library')->body), 'grouped by name again');

        $this->post($base . '/editions', ['action' => 'first']);
        self::assertContains('aws-certified-solutions-architect-associate-saa-c02,aws-certified-solutions-architect-associate-saa-c03',
            $this->cards($this->get('/library')->body), 'the chosen edition first');

        // Two exams on cards of their own share one once joined, and either can leave again.
        $this->post('/library/amazon/aws-certified-cloud-practitioner/editions', ['action' => 'separate']);
        $this->post($base . '/editions', ['action' => 'separate']);
        $this->post($base . '/editions', ['action' => 'join', 'with' => 'aws-certified-cloud-practitioner']);
        $cards = $this->cards($this->get('/library')->body);
        self::assertCount(4, $cards);
        self::assertContains('aws-certified-solutions-architect-associate-saa-c02,aws-certified-cloud-practitioner', $cards);
        self::assertSame(404, $this->post($base . '/editions', ['action' => 'join', 'with' => 'az-104'])->status, 'only exams of the same provider');
        self::assertSame(400, $this->post($base . '/editions', ['action' => 'shuffle'])->status);

        $done = $this->app->db()->all("SELECT * FROM audit_log WHERE action = 'exam.editions_changed' ORDER BY id");
        self::assertCount(6, $done);
        self::assertSame('admin gave AWS-CERTIFIED-SOLUTIONS-ARCHITECT-ASSOCIATE-SAA-C02 (Amazon) a card of its own', AuditText::describe($done[0])['text']);
        self::assertStringContainsString('on one card with AWS-CERTIFIED-CLOUD-PRACTITIONER', AuditText::describe($done[5])['text']);

        // Only those who manage the library can change it.
        $this->s->users()->create('member', 'member password long', Access::MEMBER);
        $this->newSession();
        $this->login('member', 'member password long');
        self::assertSame(403, $this->post($base . '/editions', ['action' => 'auto'])->status);
    }

    public function testNoEmDashesInTheNewPages(): void
    {
        foreach ([$this->get('/library')->body, $this->get('/library', ['view' => 'table'])->body] as $page) {
            self::assertStringNotContainsString("\u{2014}", $page);
        }
    }
}
