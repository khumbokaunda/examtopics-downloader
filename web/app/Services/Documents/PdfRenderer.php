<?php

declare(strict_types=1);

namespace App\Services\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF via dompdf, from HTML built here out of the canonical dataset (never from Markdown).
 *
 * Why dompdf rather than the Go tool's -type pdf: that path (mdtopdf/gofpdf core fonts)
 * cannot render text outside Latin-1, has no images, and only converts its own legacy
 * Markdown. dompdf ships DejaVu Sans (broad Unicode), supports CSS paged media, and keeps
 * one rendering pipeline in the web app for every variant.
 */
final class PdfRenderer
{
    public function __construct(private ?ImageFetcher $images = null, private string $tempDir = '')
    {
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array{provider:string,exam:string,exam_code:string,version:string,collected_at:string,status:string} $meta
     */
    public function render(array $dataset, array $meta, Variant $variant): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('a4');
        $options->setDpi(96);
        if ($this->tempDir !== '') {
            $options->setTempDir($this->tempDir);
            $options->setFontCache($this->tempDir);
        }

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($dataset, $meta, $variant), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $title = $meta['provider'] . ' ' . $meta['exam'] . ': ' . $variant->label();
        $dompdf->addInfo('Title', $title);
        $dompdf->addInfo('Subject', 'Collected ' . $meta['collected_at'] . ', version ' . $meta['version']);
        $dompdf->addInfo('Creator', 'Examtopics');

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            $canvas->get_width() - 120, $canvas->get_height() - 28,
            'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.4, 0.4, 0.4]
        );
        $canvas->page_text(42, $canvas->get_height() - 28, $this->ascii($meta['provider'] . ' ' . $meta['exam']), $font, 7.5, [0.4, 0.4, 0.4]);
        return (string) $dompdf->output();
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array{provider:string,exam:string,exam_code:string,version:string,collected_at:string,status:string} $meta
     */
    public function html(array $dataset, array $meta, Variant $variant): string
    {
        $questions = array_values(array_filter((array) ($dataset['questions'] ?? []), 'is_array'));
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = static fn(string $s): string => nl2br($h(trim($s)), false);

        $b = [];
        $b[] = '<!doctype html><html><head><meta charset="utf-8"><title>' . $h($meta['provider'] . ' ' . $meta['exam']) . '</title>';
        $b[] = '<style>' . self::CSS . '</style></head><body>';
        $b[] = '<div class="cover"><div class="kicker">' . $h($meta['provider']) . '</div>';
        $b[] = '<h1>' . $h($meta['exam']) . '</h1><div class="subtitle">' . $h($variant->label()) . '</div>';
        $b[] = '<table class="meta">';
        foreach ([
            'Exam code' => $meta['exam_code'],
            'Questions' => count($questions) . ($meta['status'] === 'partial' ? ' (partial collection)' : ''),
            'Collected' => $meta['collected_at'],
            'Source' => ($dataset['source'] ?? '') === 'github-cache' ? 'Public cached ExamTopics dataset' : 'ExamTopics discussion pages',
            'Version' => $meta['version'],
        ] as $k => $v) {
            $b[] = '<tr><th>' . $h($k) . '</th><td>' . $h((string) $v) . '</td></tr>';
        }
        $b[] = '</table>';
        if ($meta['status'] === 'partial') {
            $b[] = '<p class="warn">Partial collection: some questions could not be fetched, so this document is incomplete.</p>';
        }
        if (!$variant->showAnswers()) {
            $b[] = '<p class="note">' . ($variant->answerKey()
                ? 'Practice mode: answers are in the answer key at the end.'
                : 'Practice mode: answers, explanations and discussions are omitted.') . '</p>';
        }
        $b[] = '</div>';

        foreach ($questions as $q) {
            $b[] = '<div class="q">';
            $b[] = '<div class="q-core">';
            $meta2 = QuestionView::meta($q);
            $b[] = '<div class="q-head"><span class="q-num">Question ' . (int) $q['number'] . '</span>'
                . ($meta2 !== '' ? '<span class="q-meta">' . $h($meta2) . '</span>' : '') . '</div>';
            $b[] = '<div class="q-text">' . $text((string) ($q['text'] ?? '')) . '</div>';
            $b[] = $this->images((array) ($q['question_images'] ?? []), 'Question image', $h);
            if (($q['choices'] ?? []) !== []) {
                $b[] = '<table class="choices">';
                foreach ((array) $q['choices'] as $c) {
                    if (is_array($c)) {
                        $b[] = '<tr><td class="letter">' . $h((string) ($c['letter'] ?? '')) . '.</td><td>' . $text((string) ($c['text'] ?? '')) . '</td></tr>';
                    }
                }
                $b[] = '</table>';
            }
            $b[] = '</div>';

            if ($variant->showAnswers()) {
                $answer = QuestionView::answerLine($q);
                $b[] = '<div class="answer"><strong>Answer:</strong> ' . ($answer !== null ? $h($answer) : 'not available');
                $votes = QuestionView::votesLine($q);
                if ($votes !== null) {
                    $b[] = '<div class="votes">Community votes: ' . $h($votes) . '</div>';
                }
                $b[] = '</div>';
                $b[] = $this->images((array) ($q['answer_images'] ?? []), 'Answer image', $h);
                if (trim((string) ($q['explanation'] ?? '')) !== '') {
                    $b[] = '<div class="explanation"><div class="label">Explanation</div>' . $text((string) $q['explanation']) . '</div>';
                }
                if ($variant->showComments()) {
                    $comments = QuestionView::comments($q);
                    if ($comments !== []) {
                        $b[] = '<div class="discussion"><div class="label">Discussion</div>';
                        foreach ($comments as $c) {
                            $head = array_filter([
                                $c['author'] !== '' ? '<strong>' . $h($c['author']) . '</strong>' : null,
                                $c['timestamp'] !== '' ? $h($c['timestamp']) : null,
                                $c['upvotes'] !== null ? $c['upvotes'] . ' upvote' . ($c['upvotes'] === 1 ? '' : 's') : null,
                            ]);
                            $b[] = '<div class="comment">' . ($head ? '<div class="c-head">' . implode(' · ', $head) . '</div>' : '')
                                . '<div>' . $text($c['content']) . '</div></div>';
                        }
                        $b[] = '</div>';
                    }
                }
            }
            $b[] = '</div>';
        }

        if ($variant->answerKey()) {
            $b[] = '<div class="key"><h2>Answer key</h2><table class="keytable"><tr><th>Question</th><th>Answer</th><th>Question</th><th>Answer</th><th>Question</th><th>Answer</th></tr>';
            $rows = array_chunk($questions, 3);
            foreach ($rows as $row) {
                $b[] = '<tr>';
                foreach ($row as $q) {
                    $a = trim((string) ($q['answer'] ?? ''));
                    $b[] = '<td class="num">' . (int) $q['number'] . '</td><td>' . ($a === '' ? 'n/a' : $h($a)) . '</td>';
                }
                $b[] = str_repeat('<td></td><td></td>', 3 - count($row)) . '</tr>';
            }
            $b[] = '</table></div>';
        }
        $b[] = '</body></html>';
        return implode("\n", $b);
    }

    /** @param list<mixed> $urls */
    private function images(array $urls, string $label, callable $h): string
    {
        $out = [];
        foreach ($urls as $i => $url) {
            $url = (string) $url;
            $data = $this->images?->dataUri($url);
            if ($data !== null) {
                $out[] = '<div class="img"><img src="' . $h($data) . '" alt="' . $h($label . ' ' . ($i + 1)) . '"></div>';
            } else {
                // Not embedded (images disabled, not allowed, or unavailable): keep the reference.
                $out[] = '<div class="img-missing">[' . $h($label . ' ' . ($i + 1)) . ': ' . $h($url) . ']</div>';
            }
        }
        return implode('', $out);
    }

    private function ascii(string $s): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '?', $s);
    }

    private const CSS = <<<'CSS'
@page { margin: 18mm 16mm 20mm 16mm; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; line-height: 1.38; color: #1d2327; }
.cover { margin-bottom: 10mm; }
.kicker { text-transform: uppercase; letter-spacing: 1px; color: #5b6770; font-size: 8.5pt; }
h1 { font-size: 20pt; margin: 2pt 0 2pt 0; }
.subtitle { font-size: 11pt; color: #3d4a52; margin-bottom: 6pt; }
table.meta { border-collapse: collapse; margin-top: 6pt; }
table.meta th { text-align: left; color: #5b6770; font-weight: normal; padding: 1pt 10pt 1pt 0; }
table.meta td { padding: 1pt 0; }
.warn { border-left: 3pt solid #c77c02; padding: 4pt 8pt; background: #fff6e5; }
.note { border-left: 3pt solid #3b6ea8; padding: 4pt 8pt; background: #eef4fb; }
.q { border-top: 0.6pt solid #c9d1d6; padding-top: 6pt; margin-top: 8pt; }
.q-core { page-break-inside: avoid; }
.q-head { margin-bottom: 3pt; }
.q-num { font-weight: bold; font-size: 10.5pt; }
.q-meta { color: #5b6770; font-size: 8pt; margin-left: 6pt; }
.q-text { margin-bottom: 4pt; }
table.choices { border-collapse: collapse; margin: 2pt 0 4pt 0; width: 100%; }
table.choices td { vertical-align: top; padding: 1.5pt 0; }
table.choices td.letter { width: 16pt; font-weight: bold; }
.answer { background: #edf7ef; border-left: 3pt solid #2f8a46; padding: 3pt 7pt; margin: 3pt 0; page-break-inside: avoid; }
.votes { color: #3d4a52; font-size: 8.5pt; }
.explanation { margin: 4pt 0; padding: 3pt 7pt; border-left: 3pt solid #8a96a0; }
.label { font-weight: bold; font-size: 8.5pt; text-transform: uppercase; color: #5b6770; margin-bottom: 2pt; }
.discussion { margin-top: 4pt; }
.comment { border-left: 2pt solid #d4dade; padding: 1pt 6pt; margin: 3pt 0; font-size: 8.5pt; page-break-inside: avoid; }
.c-head { color: #5b6770; }
.img img { max-width: 100%; max-height: 120mm; margin: 3pt 0; }
.img-missing { color: #5b6770; font-size: 8pt; word-wrap: break-word; }
.key { page-break-before: always; }
h2 { font-size: 14pt; }
table.keytable { border-collapse: collapse; width: 100%; }
table.keytable th, table.keytable td { border: 0.5pt solid #c9d1d6; padding: 2pt 5pt; text-align: left; }
table.keytable td.num { color: #5b6770; width: 12%; }
CSS;
}
