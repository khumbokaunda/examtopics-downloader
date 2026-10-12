<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Services\Documents\Pdf\FontCoverage;
use App\Services\Documents\Pdf\PdfCanvas;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Dompdf\Options;

/**
 * PDF via dompdf, from HTML built here out of the canonical dataset (never from Markdown).
 *
 * The layout is an editorial study guide in the app's own type and colours: Poppins and
 * JetBrains Mono, the teal accent with a deep teal-navy for titles and answers, and amber
 * only for warnings. Page 1 has the title, an "About this guide" box and a linked contents
 * list whose page numbers are filled in after layout. Each section starts on a new page and
 * is a PDF bookmark; question cards are kept whole; appendices hold the answer key and the
 * source of every question.
 *
 * Why dompdf rather than the Go tool's -type pdf: that path (mdtopdf/gofpdf core fonts)
 * cannot render text outside Latin-1, has no images, and only converts its own legacy
 * Markdown. dompdf embeds TrueType fonts (subset), supports CSS paged media, and keeps one
 * rendering pipeline in the web app for every variant.
 */
final class PdfRenderer
{
    /** Questions per section when the exam has a single topic. */
    private const SECTION_SIZE = 50;

    /**
     * Comments per question in the discussions PDF. dompdf keeps the whole document in
     * memory (about 1 MB per question with a dozen comments), so a 1,000-question exam with
     * every comment could exceed any sensible limit; the most upvoted ones are kept.
     */
    private const PDF_COMMENTS = 10;

    // Print palette, from the app's theme tokens (default teal accent).
    private const TEAL = [0x1C, 0x72, 0x93];
    private const NAVY = [0x12, 0x40, 0x56];
    private const MUTED = [0x6B, 0x75, 0x80];
    private const LEADER = [0xB9, 0xC1, 0xC9];

    /** @var array{pages:int,sections:array<string,array{title:string,page:int}>} */
    private array $layout = ['pages' => 0, 'sections' => []];

    public function __construct(private ?ImageSource $images = null, private string $tempDir = '')
    {
    }

    public static function fontDir(): string
    {
        return dirname(__DIR__, 3) . '/resources/fonts';
    }

    /**
     * Where each section landed in the last render, for tests and diagnostics.
     *
     * @return array{pages:int,sections:array<string,array{title:string,page:int}>}
     */
    public function layout(): array
    {
        return $this->layout;
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array{provider:string,exam:string,exam_code:string,version:string,collected_at:string,status:string} $meta
     */
    public function render(array $dataset, array $meta, Variant $variant, ?callable $progress = null): string
    {
        class_exists(PdfCanvas::class); // dompdf looks the backend class up without autoloading
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('a4');
        $options->setDpi(96);
        $options->setPdfBackend(PdfCanvas::class);
        $options->setChroot([self::fontDir()]);
        if ($this->tempDir !== '') {
            $fonts = $this->tempDir . '/fonts';
            if (!is_dir($fonts)) {
                @mkdir($fonts, 0750, true);
            }
            $options->setTempDir($this->tempDir);
            $options->setFontCache($fonts);
            $options->setFontDir($fonts);
        }

        $dompdf = new Dompdf($options);
        $this->registerFonts($dompdf);

        // Record where marked elements land: section headings (bookmarks, contents page
        // numbers) and the contents cells the numbers are written into.
        $marks = [];
        $total = count(array_filter((array) ($dataset['questions'] ?? []), 'is_array'));
        $done = 0;
        if ($progress !== null) {
            $progress(0, $total);
        }
        $dompdf->setCallbacks([[
            'event' => 'begin_frame',
            'f' => static function (Frame $frame, Canvas $canvas) use (&$marks, &$done, $total, $progress): void {
                $node = $frame->get_node();
                if (!$node instanceof \DOMElement) {
                    return;
                }
                // Pages are laid out in order, so the last question reached is the progress.
                if ($progress !== null && $node->hasAttribute('data-q') && (int) $node->getAttribute('data-q') > $done) {
                    $done = (int) $node->getAttribute('data-q');
                    $progress($done, $total);
                }
                if (!$node->hasAttribute('data-mark')) {
                    return;
                }
                $key = $node->getAttribute('data-mark');
                if (!isset($marks[$key])) {
                    $box = $frame->get_border_box();
                    $marks[$key] = ['page' => $canvas->get_page_number(), 'x' => (float) $box['x'], 'y' => (float) $box['y'],
                        'w' => (float) $box['w'], 'h' => (float) $box['h'],
                        'pageObject' => $canvas instanceof PdfCanvas ? $canvas->currentPageObjectId() : 0];
                }
            },
        ]]);

        $doc = $this->document($dataset, $meta, $variant);
        $dompdf->loadHtml($doc['html'], 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dompdf->addInfo('Title', $meta['provider'] . ' ' . $meta['exam'] . ': ' . $variant->label());
        $dompdf->addInfo('Subject', 'Collected ' . $meta['collected_at'] . ', version ' . $meta['version']);
        $dompdf->addInfo('Creator', 'Examtopics');

        $canvas = $dompdf->getCanvas();
        $sectionPages = [];
        foreach ($doc['sections'] as $key => $title) {
            if (isset($marks['sec:' . $key])) {
                $sectionPages[$key] = (int) $marks['sec:' . $key]['page'];
            }
        }

        $tocTitles = $doc['toc'];
        $canvas->page_script(function (int $page, int $count, Canvas $c, FontMetrics $fm) use ($marks, $sectionPages, $tocTitles): void {
            $numFont = $fm->getFont('Poppins SemiBold') ?? $fm->getFont('DejaVu Sans');
            $footFont = $fm->getFont('Poppins') ?? $fm->getFont('DejaVu Sans');
            if ($page > 1) {
                $label = (string) $page;
                $w = $fm->getTextWidth($label, $footFont, 8);
                $c->text($c->get_width() - 65.2 - $w, $c->get_height() - 46, $label, $footFont, 8, self::rgb(self::MUTED));
            }
            foreach ($sectionPages as $key => $target) {
                $cell = $marks['toc:' . $key] ?? null;
                if ($cell === null || $cell['page'] !== $page) {
                    continue;
                }
                $label = (string) $target;
                $w = $fm->getTextWidth($label, $numFont, 9.5);
                $top = $cell['y'] + ($cell['h'] - 14) / 2 - 1.5;
                $c->text($cell['x'] + $cell['w'] - $w, $top, $label, $numFont, 9.5, self::rgb(self::NAVY));
                // The leader runs from the end of the title to just before the number.
                $title = $marks['tt:' . $key] ?? null;
                $from = $title !== null && $title['page'] === $page
                    ? $title['x'] + $fm->getTextWidth((string) ($tocTitles[$key] ?? ''), $footFont, 10) + 6 : $cell['x'] - 40;
                $to = $cell['x'] + $cell['w'] - $w - 5;
                if ($to > $from) {
                    $baseline = $top + 11;
                    $c->line($from, $baseline, $to, $baseline, self::rgb(self::LEADER), 0.9, [0.01, 2.6], 'round');
                }
            }
        });

        if ($canvas instanceof PdfCanvas) {
            foreach ($doc['sections'] as $key => $title) {
                $m = $marks['sec:' . $key] ?? null;
                if ($m !== null && $m['pageObject'] > 0) {
                    $canvas->outline()->addBookmark($title, $m['pageObject'], (float) $canvas->get_height() - (float) $m['y'] + 8);
                }
            }
        }

        $this->layout = ['pages' => (int) $canvas->get_page_count(), 'sections' => []];
        foreach ($doc['sections'] as $key => $title) {
            $this->layout['sections'][$key] = ['title' => $title, 'page' => $sectionPages[$key] ?? 0];
        }
        return (string) $dompdf->output();
    }

    /**
     * The HTML that render() lays out.
     *
     * @param array<string,mixed> $dataset
     * @param array{provider:string,exam:string,exam_code:string,version:string,collected_at:string,status:string} $meta
     */
    public function html(array $dataset, array $meta, Variant $variant): string
    {
        return $this->document($dataset, $meta, $variant)['html'];
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array{provider:string,exam:string,exam_code:string,version:string,collected_at:string,status:string} $meta
     * @return array{html:string,sections:array<string,string>,toc:array<string,string>} sections: key => bookmark title; toc: key => contents title
     */
    private function document(array $dataset, array $meta, Variant $variant): array
    {
        $questions = array_values(array_filter((array) ($dataset['questions'] ?? []), 'is_array'));
        $showAnswers = $variant->showAnswers();
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $poppins = self::fontDir() . '/Poppins-Regular.ttf';
        // Escaped, with runs Poppins cannot draw handed to the fallback font.
        $t = static fn(string $s): string => FontCoverage::wrap($h($s), $poppins);
        $text = static fn(string $s): string => nl2br($t(trim($s)), false);

        // Images and flags first: the section intros and the contents count them.
        $cards = [];
        foreach ($questions as $i => $q) {
            $qImages = $this->embed((array) ($q['question_images'] ?? []));
            $aImages = $showAnswers ? $this->embed((array) ($q['answer_images'] ?? [])) : [];
            $cImages = [];
            foreach ((array) ($q['choices'] ?? []) as $c) {
                $cImages[(string) ($c['letter'] ?? '')] = is_array($c) ? $this->embed((array) ($c['images'] ?? [])) : [];
            }
            $all = array_merge($qImages, $aImages, ...array_values($cImages));
            $missing = array_filter($all, static fn(array $im) => $im['uri'] === null) !== [];
            $cards[$i] = [
                'q' => $q, 'qImages' => $qImages, 'aImages' => $aImages, 'cImages' => $cImages,
                'figureMissing' => $missing,
                'figures' => count($all),
                'keyVaries' => self::keyVaries($q),
            ];
        }

        $sections = $this->sections($questions);
        $withKey = $showAnswers || $variant->answerKey();
        $bookmarks = [];
        $toc = [];
        foreach ($sections as $s) {
            $bookmarks[$s['key']] = $s['number'] . ' ' . $s['title'];
            $toc[$s['key']] = $s['title'];
        }
        if ($withKey) {
            $bookmarks['appA'] = 'Appendix A. Answer key';
            $toc['appA'] = 'Answer key';
        }
        $bookmarks['appB'] = ($withKey ? 'Appendix B' : 'Appendix A') . '. Source index';
        $toc['appB'] = 'Source index';

        $b = [];
        $b[] = '<!doctype html><html><head><meta charset="utf-8"><title>' . $h($meta['provider'] . ' ' . $meta['exam']) . '</title>';
        $b[] = '<style>' . self::CSS . '</style></head><body>';

        // Page 1: title, about, contents.
        $kicker = $meta['provider'] . ' · ' . strtoupper($meta['exam_code']);
        $b[] = '<div class="kicker">' . $t($kicker) . '</div>';
        $b[] = '<h1 class="title">' . $t($meta['exam']) . '</h1>';
        $b[] = '<div class="subtitle">' . $h($variant->label()) . ' · ' . count($questions) . ' questions · collected '
            . $h(substr($meta['collected_at'], 0, 10)) . '</div>';
        $b[] = $this->about($dataset, $meta, $variant, count($questions), $h);

        $b[] = '<div class="label toc-label">Contents</div>';
        foreach ($sections as $s) {
            $b[] = $this->tocRow($s['key'], $s['number'], $s['title'], $h);
        }
        if ($withKey) {
            $b[] = $this->tocRow('appA', 'A', 'Answer key', $h);
        }
        $b[] = $this->tocRow('appB', $withKey ? 'B' : 'A', 'Source index', $h);

        // Sections.
        $position = 0;
        foreach ($sections as $s) {
            $inSection = array_map(static fn(int $i) => $cards[$i], $s['indexes']);
            $figures = count(array_filter($inSection, static fn(array $c) => $c['figures'] > 0));
            $missing = count(array_filter($inSection, static fn(array $c) => $c['figureMissing']));
            $varies = $showAnswers ? count(array_filter($inSection, static fn(array $c) => $c['keyVaries'])) : 0;
            $intro = [count($inSection) . ' question' . (count($inSection) === 1 ? '' : 's')];
            if ($figures > 0) {
                $intro[] = $figures . ' with figures' . ($missing > 0 ? ' (' . $missing . ' missing)' : '');
            }
            if ($varies > 0) {
                $intro[] = 'the key varies on ' . $varies;
            }
            $b[] = '<div class="section">';
            $b[] = '<h2 class="sec-title" id="' . $s['key'] . '" data-mark="sec:' . $s['key'] . '"><span class="sec-num">' . $h($s['number']) . '</span> ' . $h($s['title']) . '</h2>';
            $b[] = '<div class="sec-intro">' . $h(implode(' · ', $intro)) . '</div>';
            foreach ($inSection as $n => $card) {
                $b[] = $this->card($card, $variant, $n === count($inSection) - 1, $h, $t, $text, ++$position);
            }
            $b[] = '</div>';
        }

        if ($withKey) {
            $b[] = $this->answerKey($cards, $showAnswers, $h, $t);
        }
        $b[] = $this->sourceIndex($cards, $withKey ? 'B' : 'A', $h, $t);
        $b[] = '</body></html>';
        return ['html' => implode("\n", $b), 'sections' => $bookmarks, 'toc' => $toc];
    }

    /**
     * Sections: one per topic when the exam has several, else runs of SECTION_SIZE questions.
     *
     * @param list<array<string,mixed>> $questions
     * @return list<array{key:string,number:string,title:string,indexes:list<int>}>
     */
    private function sections(array $questions): array
    {
        $byTopic = [];
        foreach ($questions as $i => $q) {
            $byTopic[(int) ($q['topic'] ?? 0)][] = $i;
        }
        $groups = [];
        if (count($byTopic) > 1) {
            foreach ($byTopic as $topic => $indexes) {
                $chunks = array_chunk($indexes, self::SECTION_SIZE * 2);
                foreach ($chunks as $c => $chunk) {
                    $title = $topic > 0 ? 'Topic ' . $topic : 'Other questions';
                    $groups[] = [$title . (count($chunks) > 1 ? ', part ' . ($c + 1) : ''), $chunk];
                }
            }
        } else {
            foreach (array_chunk(array_keys($questions), self::SECTION_SIZE) as $chunk) {
                $first = (int) ($questions[$chunk[0]]['number'] ?? $chunk[0] + 1);
                $last = (int) ($questions[$chunk[count($chunk) - 1]]['number'] ?? $chunk[count($chunk) - 1] + 1);
                $groups[] = [$first === $last ? 'Question ' . $first : 'Questions ' . $first . ' to ' . $last, $chunk];
            }
        }
        $out = [];
        foreach ($groups as $n => [$title, $indexes]) {
            $out[] = ['key' => 's' . ($n + 1), 'number' => sprintf('%02d', $n + 1), 'title' => $title, 'indexes' => $indexes];
        }
        return $out;
    }

    /** One contents row. Its page number and dotted leader are drawn once the layout is known. */
    private function tocRow(string $key, string $number, string $title, callable $h): string
    {
        return '<table class="toc"><tr><td class="toc-n">' . $h($number) . '</td><td class="toc-t"><a href="#' . $key . '" data-mark="tt:' . $key . '">' . $h($title) . '</a></td>'
            . '<td class="toc-p" data-mark="toc:' . $key . '"><a class="toc-link" href="#' . $key . '">&#160;</a></td></tr></table>';
    }

    /** @param array{provider:string,exam:string,exam_code:string,version:string,collected_at:string,status:string} $meta */
    private function about(array $dataset, array $meta, Variant $variant, int $count, callable $h): string
    {
        $cached = ($dataset['source'] ?? '') === 'github-cache';
        $rows = [];
        $rows[] = ['Source.', $count . ' questions from ' . ($cached ? 'the public cached ExamTopics dataset' : 'the ExamTopics discussion pages')
            . ', collected ' . $meta['collected_at'] . ' (version ' . $meta['version'] . ').'];
        if ($variant->showAnswers()) {
            $rows[] = ['Answers.', 'Each answer is the one ExamTopics suggests when the page shows it, otherwise the answer most voted by the community, with the vote count.'];
        } elseif ($variant->answerKey()) {
            $rows[] = ['Answers.', 'Answers are not shown with the questions, so you can practise. They are listed in Appendix A at the end.'];
        } else {
            $rows[] = ['Answers.', 'Answers, explanations and discussions are left out, so you can practise without them.'];
        }
        if ($variant->showComments()) {
            $rows[] = ['Discussions.', 'Up to the ' . self::PDF_COMMENTS . ' most upvoted comments of each discussion follow the answer, with the answer each commenter selected. The reader on the site shows every comment.'];
        }
        $flags = '<span class="badge">FIGURE MISSING</span> the question refers to an image that could not be included.';
        if ($variant->showAnswers() || $variant->answerKey()) {
            $flags .= ' <span class="badge">KEY VARIES</span> the community vote differs from the suggested answer.';
        }
        $out = '<div class="about"><div class="label about-label">About this guide</div>';
        foreach ($rows as [$lead, $body]) {
            $out .= '<p><strong>' . $h($lead) . '</strong> ' . $h($body) . '</p>';
        }
        $out .= '<p><strong>Flags.</strong> ' . $flags . '</p>';
        if ($meta['status'] === 'partial') {
            $out .= '<p class="warn"><strong>Partial collection.</strong> Some questions could not be fetched, so this guide is incomplete.</p>';
        }
        return $out . '</div>';
    }

    /**
     * @param array{q:array<string,mixed>,qImages:list<array{url:string,uri:?string}>,aImages:list<array{url:string,uri:?string}>,cImages:array<string,list<array{url:string,uri:?string}>>,figureMissing:bool,figures:int,keyVaries:bool} $card
     */
    private function card(array $card, Variant $variant, bool $last, callable $h, callable $t, callable $text, int $position): string
    {
        $q = $card['q'];
        $showAnswers = $variant->showAnswers();
        $answer = self::letters((string) ($q['answer'] ?? ''));
        $flags = [];
        if ($card['figureMissing']) {
            $flags[] = '<span class="badge">FIGURE MISSING</span>';
        }
        if ($showAnswers && $card['keyVaries']) {
            $flags[] = '<span class="badge">KEY VARIES</span>';
        }
        $src = QuestionView::meta($q);
        $b = [];
        $b[] = '<div class="q' . ($last ? ' q-last' : '') . '" data-q="' . $position . '">';
        $b[] = '<div class="q-core">';
        $b[] = '<table class="q-label"><tr><td><span class="q-num">Q' . (int) ($q['number'] ?? 0) . '</span>'
            . ($src !== '' ? '<span class="q-src">' . $h($src) . '</span>' : '') . '</td>'
            . '<td class="q-flags">' . implode(' ', $flags) . '</td></tr></table>';
        $b[] = '<div class="q-text">' . $text((string) ($q['text'] ?? '')) . '</div>';
        $b[] = $this->figures($card['qImages'], 'Question image', $h);

        $choices = array_values(array_filter((array) ($q['choices'] ?? []), 'is_array'));
        if (self::isTrueFalse($choices)) {
            $b[] = '<div class="tf">';
            foreach ($choices as $c) {
                $letter = (string) ($c['letter'] ?? '');
                $hit = $showAnswers && $letter !== '' && str_contains($answer, $letter);
                $b[] = '<span class="opt-l' . ($hit ? ' hit' : '') . '">' . $h($letter) . '</span> <span class="tf-t">' . $t(trim((string) ($c['text'] ?? ''))) . '</span>';
            }
            $b[] = '</div>';
        } elseif ($choices !== []) {
            $b[] = '<table class="opts">';
            foreach ($choices as $c) {
                $letter = (string) ($c['letter'] ?? '');
                $hit = $showAnswers && $letter !== '' && str_contains($answer, $letter);
                $b[] = '<tr><td class="opt-l' . ($hit ? ' hit' : '') . '">' . $h($letter) . '</td><td class="opt-t">' . $text((string) ($c['text'] ?? ''))
                    . $this->figures($card['cImages'][$letter] ?? [], 'Choice ' . $letter, $h) . '</td></tr>';
            }
            $b[] = '</table>';
        }

        if ($showAnswers) {
            $b[] = '<div class="ans">';
            if ($answer !== '') {
                $source = match ($q['answer_source'] ?? '') {
                    'suggested' => 'Suggested by ExamTopics',
                    'community' => 'Most voted by the community',
                    default => '',
                };
                $b[] = '<div class="ans-line"><span class="ans-label">Answer</span> <span class="ans-value">' . $h(implode(', ', str_split($answer))) . '</span>'
                    . ($source !== '' ? ' <span class="ans-src">' . $h($source) . '</span>' : '') . '</div>';
                if ($card['figureMissing']) {
                    $b[] = '<div class="ans-warn">Cannot be confirmed without the figure.</div>';
                }
            } else {
                $note = QuestionView::pictureAnswerNote($q);
                $b[] = '<div class="ans-line"><span class="ans-label">Answer</span> <span class="ans-warn">' . $h($note ?? 'Not available for this question.') . '</span></div>';
            }
            $votes = QuestionView::votesLine($q);
            if ($votes !== null) {
                $b[] = '<div class="ans-votes">Community vote: ' . $t($votes) . '</div>';
            }
            $b[] = $this->figures($card['aImages'], 'Answer image', $h);
            if (trim((string) ($q['explanation'] ?? '')) !== '') {
                $b[] = '<div class="ans-expl">' . $text((string) $q['explanation']) . '</div>';
            }
            $b[] = '</div>';
        }
        $b[] = '</div>'; // q-core: kept on one page

        if ($showAnswers && $variant->showComments()) {
            $comments = self::topComments(QuestionView::comments($q), self::PDF_COMMENTS);
            if ($comments !== []) {
                $b[] = '<div class="disc">';
                foreach ($comments as $n => $c) {
                    $head = array_filter([
                        $c['author'] !== '' ? '<strong>' . $t($c['author']) . '</strong>' : null,
                        $c['timestamp'] !== '' ? $t($c['timestamp']) : null,
                        $c['selected'] !== '' ? 'selected ' . $h($c['selected']) : null,
                        $c['upvotes'] !== null ? $c['upvotes'] . ' upvote' . ($c['upvotes'] === 1 ? '' : 's') : null,
                    ]);
                    $comment = '<div class="cmt' . ($c['reply'] ? ' cmt-reply' : '') . '">'
                        . ($head !== [] ? '<div class="cmt-head">' . implode(' · ', $head) . '</div>' : '')
                        // Comment text from older pages separates every line with a blank one.
                        . '<div class="cmt-body">' . $text((string) preg_replace("/\n\s*\n+/", "\n", $c['content'])) . '</div></div>';
                    // The heading stays with the first comment.
                    $b[] = $n === 0 ? '<div class="disc-start"><div class="label">Discussion</div>' . $comment . '</div>' : $comment;
                }
                $b[] = '</div>';
            }
        }
        $b[] = '</div>';
        return implode("\n", $b);
    }

    /** @param list<array{q:array<string,mixed>,keyVaries:bool}> $cards */
    private function answerKey(array $cards, bool $markVaries, callable $h, callable $t): string
    {
        $b = ['<div class="section">'];
        $b[] = '<h2 class="sec-title" id="appA" data-mark="sec:appA"><span class="sec-num">A</span> Answer key</h2>';
        $b[] = '<div class="sec-intro">' . count($cards) . ' answers, four questions to a row</div>';
        $b[] = '<table class="key"><thead><tr>' . str_repeat('<th class="key-q">Q</th><th class="key-a">Answer</th>', 4) . '</tr></thead><tbody>';
        $varies = false;
        foreach (array_chunk($cards, 4) as $row) {
            $b[] = '<tr>';
            foreach ($row as $card) {
                $a = self::letters((string) ($card['q']['answer'] ?? ''));
                $mark = $markVaries && $card['keyVaries'];
                $varies = $varies || $mark;
                $b[] = '<td class="key-q">' . (int) ($card['q']['number'] ?? 0) . '</td><td class="key-a">'
                    . ($a === '' ? '<span class="muted">n/a</span>' : $h(implode(', ', str_split($a))))
                    . ($mark ? ' <span class="amber">*</span>' : '') . '</td>';
            }
            $b[] = str_repeat('<td class="key-q"></td><td class="key-a"></td>', 4 - count($row)) . '</tr>';
        }
        $b[] = '</tbody></table>';
        if ($varies) {
            $b[] = '<p class="note"><span class="amber">*</span> The community vote differs from the suggested answer.</p>';
        }
        $b[] = '</div>';
        return implode("\n", $b);
    }

    /** @param list<array{q:array<string,mixed>}> $cards */
    private function sourceIndex(array $cards, string $letter, callable $h, callable $t): string
    {
        $b = ['<div class="section">'];
        $b[] = '<h2 class="sec-title" id="appB" data-mark="sec:appB"><span class="sec-num">' . $letter . '</span> Source index</h2>';
        $b[] = '<div class="sec-intro">Where each question was collected from</div>';
        $b[] = '<table class="src"><thead><tr><th class="src-q">Q</th><th class="src-ref">Reference</th><th>Discussion</th></tr></thead><tbody>';
        foreach ($cards as $card) {
            $q = $card['q'];
            $url = (string) ($q['source_url'] ?? '');
            $shown = (string) preg_replace('#^https?://(www\.)?#i', '', $url);
            $link = preg_match('#^https?://[^\s"\'<>]+$#i', $url) === 1
                ? '<a href="' . $h($url) . '">' . $t($shown) . '</a>' : $t($shown);
            $b[] = '<tr><td class="src-q">' . (int) ($q['number'] ?? 0) . '</td><td class="src-ref">' . $h(QuestionView::meta($q) ?: 'n/a')
                . '</td><td class="src-url">' . $link . '</td></tr>';
        }
        $b[] = '</tbody></table></div>';
        return implode("\n", $b);
    }

    /**
     * @param list<array{url:string,uri:?string}> $images
     */
    private function figures(array $images, string $label, callable $h): string
    {
        $out = [];
        foreach ($images as $i => $im) {
            if ($im['uri'] !== null) {
                $out[] = '<div class="fig"><img src="' . $h($im['uri']) . '" alt="' . $h($label . ' ' . ($i + 1)) . '"></div>';
            } else {
                $shown = (string) preg_replace('#^https?://(www\.)?#i', '', $im['url']);
                $out[] = '<div class="fig-missing"><span class="badge">FIGURE MISSING</span> ' . $h($label . ' ' . ($i + 1)) . ': ' . $h($shown) . '</div>';
            }
        }
        return implode('', $out);
    }

    /**
     * @param list<mixed> $urls
     * @return list<array{url:string,uri:?string}>
     */
    private function embed(array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            $url = (string) $url;
            if ($url !== '') {
                $out[] = ['url' => $url, 'uri' => $this->images?->dataUri($url)];
            }
        }
        return $out;
    }

    private function registerFonts(Dompdf $dompdf): void
    {
        $fm = $dompdf->getFontMetrics();
        $families = $fm->getFontFamilies();
        $dir = self::fontDir();
        foreach ([
            ['Poppins', 'normal', 'Poppins-Regular.ttf'],
            ['Poppins', 'bold', 'Poppins-Bold.ttf'],
            ['Poppins Medium', 'normal', 'Poppins-Medium.ttf'],
            ['Poppins SemiBold', 'normal', 'Poppins-SemiBold.ttf'],
            ['JetBrains Mono', 'normal', 'JetBrainsMono-Regular.ttf'],
        ] as [$family, $weight, $file]) {
            $known = $families[strtolower($family)][$weight] ?? null;
            if (is_string($known) && is_file($known . '.ttf') && is_file($known . '.ufm')) {
                continue; // registered by an earlier render
            }
            if (is_file($dir . '/' . $file)) {
                $fm->registerFont(['family' => $family, 'weight' => $weight, 'style' => 'normal'], 'file://' . $dir . '/' . $file);
            }
        }
    }

    /**
     * The most upvoted comments, in page order. A reply is kept only with its kept parent
     * (the comment before it that is not a reply).
     *
     * @param list<array{upvotes:?int,reply:bool}> $comments
     * @return list<array{upvotes:?int,reply:bool}>
     */
    private static function topComments(array $comments, int $limit): array
    {
        if (count($comments) <= $limit) {
            return $comments;
        }
        $ranked = array_keys($comments);
        usort($ranked, static fn(int $a, int $b) => ((int) $comments[$b]['upvotes'] <=> (int) $comments[$a]['upvotes']) ?: $a <=> $b);
        $keep = array_fill_keys(array_slice($ranked, 0, $limit), true);
        $out = [];
        $parentKept = false;
        foreach ($comments as $i => $c) {
            if (!$c['reply']) {
                $parentKept = isset($keep[$i]);
            }
            if (isset($keep[$i]) && (!$c['reply'] || $parentKept)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $choices */
    private static function isTrueFalse(array $choices): bool
    {
        if (count($choices) !== 2) {
            return false;
        }
        $texts = array_map(static fn(array $c) => strtolower(rtrim(trim((string) ($c['text'] ?? '')), '.')), $choices);
        sort($texts);
        return $texts === ['false', 'true'];
    }

    /** Answer letters, upper case, sorted, without duplicates. */
    private static function letters(string $s): string
    {
        $l = array_unique(str_split((string) preg_replace('/[^A-Z]/', '', strtoupper($s))));
        sort($l);
        return implode('', array_filter($l, static fn($c) => $c !== ''));
    }

    /** The community's most-voted answer differs from ExamTopics' suggested answer. */
    private static function keyVaries(array $q): bool
    {
        $suggested = self::letters((string) ($q['suggested_answer'] ?? ''));
        if ($suggested === '') {
            return false;
        }
        $votes = array_values(array_filter((array) ($q['community_votes'] ?? []), 'is_array'));
        if ($votes === []) {
            return false;
        }
        $marked = array_values(array_filter($votes, static fn(array $v) => !empty($v['most_voted'])));
        if ($marked === []) {
            usort($votes, static fn(array $a, array $b) => (int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0));
        }
        $voted = self::letters((string) (($marked[0] ?? $votes[0])['answer'] ?? ''));
        return $voted !== '' && $voted !== $suggested;
    }

    /** @param array{int,int,int} $c @return array{float,float,float} */
    private static function rgb(array $c): array
    {
        return [$c[0] / 255, $c[1] / 255, $c[2] / 255];
    }

    // Print styles. Sizes in points; colours from the app's theme (teal accent), with a deep
    // teal-navy for titles and answers and amber only for warnings.
    private const CSS = <<<'CSS'
@page { margin: 22mm 23mm 24mm 23mm; }
body { font-family: "Poppins", "DejaVu Sans", sans-serif; font-size: 9.5pt; line-height: 1.2; color: #1A2028; }
.fb { font-family: "DejaVu Sans", sans-serif; }
a { color: inherit; text-decoration: none; }
strong { font-family: "Poppins", "DejaVu Sans", sans-serif; font-weight: bold; }
.label { font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; font-size: 7.5pt; letter-spacing: 1.2pt; text-transform: uppercase; color: #1C7293; }
.muted { color: #6B7580; }
.amber { color: #8A5A00; font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; }
.badge { font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; font-size: 6.5pt; letter-spacing: 0.6pt; color: #8A5A00;
    background-color: #FBEFD8; border: 0.5pt solid #E8C27A; border-radius: 2.5pt; padding: 0.5pt 3pt; }

.kicker { font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; font-size: 8.5pt; letter-spacing: 1.6pt; text-transform: uppercase; color: #1C7293; }
h1.title { font-family: "Poppins", "DejaVu Sans", sans-serif; font-weight: bold; font-size: 25pt; line-height: 1.05; color: #124056; margin: 4pt 0 5pt 0; }
.subtitle { font-size: 11.5pt; color: #6B7580; padding-bottom: 9pt; border-bottom: 0.5pt solid #D9DEE3; margin-bottom: 14pt; }

.about { background-color: #F1F7F9; border: 0.75pt solid #C5DEE8; border-radius: 6.5pt; padding: 10pt 13pt 4pt 13pt; margin-bottom: 18pt; }
.about-label { margin-bottom: 5pt; }
.about p { margin: 0 0 6pt 0; font-size: 9pt; color: #2B3540; }
.about strong { color: #124056; }
.about .warn strong { color: #8A5A00; }

.toc-label { margin-bottom: 4pt; }
table.toc { width: 100%; border-collapse: collapse; }
table.toc td { padding: 4pt 0 4pt 0; vertical-align: top; font-size: 10pt; }
td.toc-n { width: 9mm; font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; color: #1C7293; }
td.toc-t { color: #1A2028; padding-right: 14mm; }
td.toc-p { width: 12mm; }
a.toc-link { display: block; }

.section { page-break-before: always; }
h2.sec-title { font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; font-weight: normal; font-size: 19pt; line-height: 1.05; color: #124056; margin: 0 0 3pt 0; }
.sec-num { color: #1C7293; padding-right: 4pt; }
.sec-intro { font-size: 9pt; color: #6B7580; padding-bottom: 7pt; border-bottom: 0.5pt solid #D9DEE3; margin-bottom: 4pt; }

.q { border-bottom: 0.5pt solid #D9DEE3; padding: 9pt 0 9pt 0; }
.q-last { border-bottom: 0; }
.q-core { page-break-inside: avoid; }
table.q-label { width: 100%; border-collapse: collapse; margin-bottom: 3pt; }
table.q-label td { padding: 0; vertical-align: middle; }
.q-num { font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; font-size: 10pt; color: #1C7293; padding-right: 6pt; }
.q-src { font-size: 7.5pt; color: #6B7580; }
td.q-flags { text-align: right; }
.q-text { font-size: 10.5pt; margin-bottom: 5pt; color: #1A2028; }

table.opts { border-collapse: collapse; margin: 0 0 4pt 0; width: 100%; }
table.opts td { vertical-align: top; padding: 1.6pt 0; }
td.opt-l { width: 6mm; padding-left: 3.5mm !important; font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; color: #6B7580; }
.opt-l.hit { color: #1C7293; }
.tf { margin: 0 0 5pt 3.5mm; }
.tf .opt-l { font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; color: #6B7580; }
.tf .tf-t { padding-right: 16pt; }

.ans { border-left: 1.75pt solid #1C7293; padding: 2pt 0 2pt 9pt; margin: 6pt 0 2pt 0; }
.ans-line { margin-bottom: 1pt; }
.ans-label { font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; font-size: 7.5pt; letter-spacing: 1.2pt; text-transform: uppercase; color: #1C7293; padding-right: 5pt; }
.ans-value { font-family: "Poppins", "DejaVu Sans", sans-serif; font-weight: bold; font-size: 10.5pt; color: #124056; }
.ans-src { font-size: 8pt; color: #6B7580; padding-left: 5pt; }
.ans-warn { font-family: "Poppins Medium", "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #8A5A00; }
.ans-votes { font-size: 8pt; color: #6B7580; }
.ans-expl { font-size: 9.5pt; color: #4A5363; margin-top: 4pt; }

.fig { margin: 4pt 0 6pt 0; }
.fig img { max-width: 100%; max-height: 110mm; }
.fig-missing { font-size: 8pt; color: #6B7580; margin: 2pt 0 5pt 0; }

.disc { margin: 7pt 0 0 0; }
.disc .label { margin-bottom: 3pt; }
.disc-start { page-break-inside: avoid; }
.cmt { border-left: 0.75pt solid #D9DEE3; padding: 1pt 0 1pt 7pt; margin: 0 0 5pt 0; font-size: 8.5pt; page-break-inside: avoid; }
.cmt-reply { margin-left: 6mm; }
.cmt-head { font-size: 7.5pt; color: #6B7580; }
.cmt-head strong { color: #2B3540; }
.cmt-body { color: #2B3540; }

table.key { width: 100%; border-collapse: collapse; border-top: 0.75pt solid #124056; border-bottom: 0.75pt solid #124056; margin-top: 6pt; }
table.key th { font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; font-weight: normal; font-size: 7.5pt; letter-spacing: 1.2pt;
    text-transform: uppercase; color: #1C7293; text-align: left; padding: 5pt 4pt; border-bottom: 0.5pt solid #E3E7EC; }
table.key td { padding: 3.5pt 4pt; border-bottom: 0.5pt solid #E3E7EC; font-size: 9pt; }
table.key .key-q { width: 7%; color: #6B7580; }
table.key td.key-a { width: 18%; font-family: "Poppins", "DejaVu Sans", sans-serif; font-weight: bold; color: #124056; }
.note { font-size: 8pt; color: #6B7580; margin-top: 6pt; }

table.src { width: 100%; border-collapse: collapse; border-top: 0.75pt solid #124056; border-bottom: 0.75pt solid #124056; margin-top: 6pt; }
table.src th { font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; font-weight: normal; font-size: 7.5pt; letter-spacing: 1.2pt;
    text-transform: uppercase; color: #1C7293; text-align: left; padding: 5pt 4pt; border-bottom: 0.5pt solid #E3E7EC; }
table.src td { padding: 2.5pt 4pt; border-bottom: 0.5pt solid #E3E7EC; font-size: 8pt; vertical-align: top; }
td.src-q, th.src-q { width: 8%; }
td.src-q { color: #1C7293; font-family: "Poppins SemiBold", "DejaVu Sans", sans-serif; }
td.src-ref, th.src-ref { width: 22%; }
td.src-url { font-family: "JetBrains Mono", "DejaVu Sans Mono", monospace; font-size: 6.5pt; color: #4A5363; word-wrap: break-word; }
td.src-url .fb { font-family: "DejaVu Sans Mono", monospace; }
CSS;
}
