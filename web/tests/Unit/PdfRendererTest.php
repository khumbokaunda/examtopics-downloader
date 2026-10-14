<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Documents\ImageSource;
use App\Services\Documents\PdfRenderer;
use App\Services\Documents\Variant;
use PHPUnit\Framework\TestCase;

/** The study-guide PDF: contents, sections, bookmarks, flags, appendices and fonts. */
final class PdfRendererTest extends TestCase
{
    private const META = ['provider' => 'Huawei', 'exam' => 'HCIA-Datacom', 'exam_code' => 'h12-811',
        'version' => '20261012T083000Z-abcdef', 'collected_at' => '2026-10-12 08:30 UTC', 'status' => 'complete'];

    private static function tmp(): string
    {
        $dir = sys_get_temp_dir() . '/examtopics-pdf-test';
        @mkdir($dir, 0750, true);
        return $dir;
    }

    /** @return array<string,mixed> */
    private static function dataset(int $n, int $topics = 1): array
    {
        $qs = [];
        for ($i = 1; $i <= $n; $i++) {
            $qs[] = [
                'number' => $i, 'topic' => $topics > 1 ? 1 + ($i % $topics) : 1, 'question_number' => $i,
                'text' => "Question $i: which protocol is described? Ω 中",
                'choices' => $i === 3
                    ? [['letter' => 'A', 'text' => 'True'], ['letter' => 'B', 'text' => 'False']]
                    : [['letter' => 'A', 'text' => 'OSPF'], ['letter' => 'B', 'text' => 'BGP'], ['letter' => 'C', 'text' => 'RIP']],
                'answer' => $i === 2 ? 'B' : 'A', 'answer_source' => 'suggested', 'suggested_answer' => $i === 2 ? 'B' : 'A',
                // Question 2: the community prefers C over the suggested B.
                'community_votes' => [['answer' => $i === 2 ? 'C' : 'A', 'count' => 9, 'most_voted' => true]],
                'explanation' => $i === 1 ? 'Because <b>OSPF</b> is a link-state protocol.' : '',
                'question_images' => $i === 4 ? ['https://www.examtopics.com/assets/media/a.png'] : ($i === 5 ? ['https://www.examtopics.com/assets/media/gone.png'] : []),
                'answer_images' => [],
                'comments' => [['author' => 'alice', 'content' => 'I think A.', 'upvotes' => 2, 'selected_answer' => 'A']],
                'source_url' => "https://www.examtopics.com/discussions/huawei/view/$i-exam-h12-811-topic-1-question-$i-discussion/",
            ];
        }
        return ['questions' => $qs, 'source' => 'live'];
    }

    private static function images(): ImageSource
    {
        $img = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        return new class ($png) implements ImageSource {
            public function __construct(private string $png)
            {
            }

            public function dataUri(string $url): ?string
            {
                return str_contains($url, 'gone') ? null : 'data:image/png;base64,' . base64_encode($this->png);
            }
        };
    }

    public function testStudyGuideHasLinkedContentsSectionsAndBookmarks(): void
    {
        $r = new PdfRenderer(self::images(), self::tmp());
        $pdf = $r->render(self::dataset(60), self::META, Variant::get('study'));
        self::assertStringStartsWith('%PDF-', $pdf);

        $layout = $r->layout();
        self::assertSame(['s1', 's2', 'appA', 'appB'], array_keys($layout['sections']));
        self::assertSame('01 Questions 1 to 50', $layout['sections']['s1']['title']);
        self::assertSame('02 Questions 51 to 60', $layout['sections']['s2']['title']);
        self::assertSame(2, $layout['sections']['s1']['page'], 'the first section starts after the contents page');
        $pages = array_column($layout['sections'], 'page');
        self::assertSame($pages, array_values(array_unique($pages)), 'every section starts on its own page');
        self::assertSame($pages, (function (array $p) { sort($p); return $p; })($pages));
        self::assertGreaterThan(end($pages), $layout['pages'] + 1);

        // Bookmarks: one per section, opened in the viewer's panel.
        self::assertMatchesRegularExpression('#/Type /Outlines /First \d+ 0 R /Last \d+ 0 R /Count 4#', $pdf);
        self::assertStringContainsString('/PageMode /UseOutlines', $pdf);
        self::assertStringContainsString(self::utf16('Appendix A. Answer key'), $pdf);
        self::assertSame(4, preg_match_all('#/Dest \[\d+ 0 R /XYZ 0 [\d.]+ 0\]#', $pdf));
        // Contents rows link to their sections.
        self::assertGreaterThanOrEqual(8, substr_count($pdf, '/S /GoTo'));
        // The app's fonts are embedded (subset), with DejaVu for characters Poppins lacks.
        foreach (['Poppins-Regular', 'Poppins-SemiBold', 'Poppins-Bold', 'JetBrainsMono-Regular', 'DejaVuSans'] as $font) {
            self::assertMatchesRegularExpression('#/BaseFont /[A-Z]{6}\+' . $font . '#', $pdf, $font);
        }
        self::assertMatchesRegularExpression('#/Subtype\s*/Image#', $pdf);
    }

    public function testCardsCarryFlagsAndAnswersOnlyWhereTheVariantAllows(): void
    {
        $r = new PdfRenderer(self::images(), self::tmp());
        $html = $r->html(self::dataset(6), self::META, Variant::get('study'));
        self::assertStringNotContainsString("\u{2014}", $html, 'no em dashes in the generated text');
        self::assertStringContainsString('<span class="fb">中</span>', $html, 'characters Poppins lacks use the fallback font');
        self::assertStringContainsString('&lt;b&gt;OSPF&lt;/b&gt;', $html, 'scraped text is escaped');
        self::assertSame(1, substr_count($html, '<span class="badge">KEY VARIES</span>') - 1, 'question 2 only (plus the legend)');
        self::assertStringContainsString('Cannot be confirmed without the figure.', $html);
        self::assertStringContainsString('FIGURE MISSING</span> Question image 1: examtopics.com/assets/media/gone.png', $html);
        self::assertStringContainsString('<div class="tf">', $html, 'true or false on one line');
        self::assertStringContainsString('Answer key', $html);
        self::assertStringContainsString('Source index', $html);

        $practice = $r->html(self::dataset(6), self::META, Variant::get('practice'));
        foreach (['class="ans"', 'Answer key', 'KEY VARIES', 'I think A.', 'link-state'] as $hidden) {
            self::assertStringNotContainsString($hidden, $practice, $hidden);
        }
        self::assertStringContainsString('<span class="sec-num">A</span> Source index', $practice);

        $key = $r->html(self::dataset(6), self::META, Variant::get('practice_key'));
        self::assertStringNotContainsString('class="ans"', $key);
        self::assertStringContainsString('<span class="sec-num">A</span> Answer key', $key);
        self::assertStringContainsString('<span class="sec-num">B</span> Source index', $key);

        $discussions = $r->html(self::dataset(6), self::META, Variant::get('discussions'));
        self::assertStringContainsString('I think A.', $discussions);
    }

    public function testPicturesGoWhereTheCachedDatasetMarkedThem(): void
    {
        $data = self::dataset(4);
        $data['questions'][3]['text'] = "//IMG//\nRefer to the exhibit. Which route is used?";
        // As the document store hands questions to the renderer.
        $data['questions'] = array_map([\App\Services\Documents\QuestionView::class, 'normalize'], $data['questions']);
        $html = (new PdfRenderer(self::images(), self::tmp()))->html($data, self::META, Variant::get('study'));
        self::assertStringNotContainsString('//IMG//', $html);
        $card = substr($html, (int) strpos($html, 'data-q="3"'));
        self::assertLessThan(strpos($card, 'Refer to the exhibit'), strpos($card, '<div class="fig">'), 'the exhibit comes first, where it was marked');
    }

    public function testExamsWithSeveralTopicsGetASectionPerTopic(): void
    {
        $r = new PdfRenderer(null, self::tmp());
        $r->render(self::dataset(9, 3), self::META, Variant::get('practice'));
        $titles = array_column($r->layout()['sections'], 'title');
        self::assertSame(['01 Topic 2', '02 Topic 3', '03 Topic 1', 'Appendix A. Source index'], $titles);
    }

    private static function utf16(string $s): string
    {
        return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
