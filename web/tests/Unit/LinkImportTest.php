<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LinkImport;
use PHPUnit\Framework\TestCase;

/** Discussion links read from pasted search results or links. */
final class LinkImportTest extends TestCase
{
    public function testSearchResultsBecomeLinksWithTheCurrentSpelling(): void
    {
        // Google results as copied from the page, plus an old address and another exam's link.
        $text = (string) file_get_contents(dirname(__DIR__) . '/fixtures/google-results-h19-101.txt')
            . "\nhttps://www.examtopics.com/discussions/huawei/view/391032-exam-h19-101_v60-topic-1-question-16-discussion/"
            . "\nhttps://www.examtopics.com/discussions/huawei/view/1-exam-h12-811-topic-1-question-1-discussion/\n";
        $r = LinkImport::parse($text, 'huawei');
        self::assertSame('h19-101-v6-0', $r['exam']);
        self::assertSame('H19-101_V6.0', $r['name']);
        self::assertSame(['1', '3', '6', '16', '31', '61', '66', '76', '78'], $r['questions']);
        self::assertSame('/discussions/huawei/view/390360-exam-h19-101-v6-0-topic-1-question-1-discussion/', $r['links'][0]);
        self::assertSame('/discussions/huawei/view/391032-exam-h19-101-v6-0-topic-1-question-16-discussion/', $r['links'][3], 'the old spelling is corrected');
        self::assertCount(9, $r['links']);
        self::assertSame(1, $r['other']);

        // A link already in the current spelling is kept as it is; another provider's is ignored.
        $plain = LinkImport::parse("https://www.examtopics.com/discussions/huawei/view/1001-exam-h12-811-topic-1-question-1/\n"
            . "https://www.examtopics.com/discussions/cisco/view/5-exam-200-301-topic-1-question-1/", 'huawei', 'H12-811');
        self::assertSame(['/discussions/huawei/view/1001-exam-h12-811-topic-1-question-1/'], $plain['links']);
        self::assertNull(LinkImport::parse('nothing useful here', 'huawei')['exam']);
    }
}
