<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpTestCase;

/** "Exam not listed? Collect from links": an exam the listing pages do not show. */
final class CollectLinksTest extends HttpTestCase
{
    public function testAnUnlistedExamIsCollectedFromPastedLinks(): void
    {
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
        $this->provider('huawei');

        // No inventory was loaded, so the exam is unknown until its links are pasted.
        $r = $this->post('/collect/links', ['provider' => 'huawei', 'exam' => '', 'links' =>
            "https://www.examtopics.com/discussions/huawei/view/1001-exam-h12-811-topic-1-question-1/\n"
            . "https://www.examtopics.com/discussions/huawei/view/1002-exam-h12-811-topic-1-question-2-discussion/\n"]);
        self::assertSame(303, $r->status);
        self::assertStringContainsString('exam=h12-811', $r->headers['Location']);
        self::assertStringContainsString('2 question links read (questions 1, 2)', $this->flashes()[0]['message'] ?? '');
        self::assertStringContainsString('Includes the 2 question links you added', $this->get('/collect', ['provider' => 'huawei', 'exam' => 'h12-811'])->body);

        $r = $this->post('/collect', ['provider' => 'huawei', 'exam' => 'h12-811', 'source_mode' => 'live', 'rps' => '1']);
        self::assertSame(303, $r->status);
        self::assertSame('success', $this->flashes()[0]['type'] ?? null);
        $this->drain();
        $job = (array) $this->app->db()->one("SELECT * FROM jobs WHERE type = 'collect' ORDER BY id DESC LIMIT 1");
        self::assertSame('completed', $job['status'], (string) $job['error_summary']);
        self::assertSame(2, (int) $this->s->versions()->find((int) $job['cache_version_id'])['collected_count']);
        self::assertStringContainsString('no listing pages are read', (string) file_get_contents($this->app->paths()->jobDir((int) $job['id']) . '/tool.log'));
    }
}
