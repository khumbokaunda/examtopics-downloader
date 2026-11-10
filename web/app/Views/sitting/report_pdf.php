<?php
/** A candidate's report for PDF (dompdf: inline styles in a <style> block are fine here, nothing is served to a browser).
 *  @var string $institution @var string $title @var array $sitting @var string $policy @var list<array> $questions @var array<int,array> $responses @var list<array> $topics */
use App\Controllers\InstitutionAssessmentController as C;
use App\Services\Access;
$s = $sitting;
?><!doctype html>
<html><head><meta charset="utf-8"><title><?= e($title) ?></title>
<style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #1f2933; }
h1 { font-size: 17pt; margin: 0 0 2pt; } .muted { color: #6b7280; } table { width: 100%; border-collapse: collapse; margin-top: 8pt; }
th, td { text-align: left; padding: 4pt 6pt; border-bottom: 1px solid #e5e7eb; vertical-align: top; } th { font-size: 9pt; color: #6b7280; }
.box { border: 1px solid #d1d5db; padding: 8pt 10pt; margin: 10pt 0; } .big { font-size: 15pt; font-weight: bold; }
</style></head><body>
<h1><?= e($title) ?></h1>
<div class="muted"><?= e($institution) ?></div>
<div class="box">
    <div><strong><?= e(Access::displayName($s)) ?></strong><?= ($s['student_number'] ?? null) !== null ? ' · ' . e($s['student_number']) : '' ?> · attempt <?= (int) $s['attempt'] ?></div>
    <div class="muted">Handed in <?= e(fmt_time((string) $s['submitted_at'])) ?></div>
    <div class="big"><?= e(C::marks($s['score'])) ?> of <?= e(C::marks($s['max_score'])) ?> (<?= e(C::marks($s['percent'])) ?>%)<?= $s['passed'] !== null ? ((int) $s['passed'] ? ': passed' : ': not passed') : '' ?></div>
</div>
<?php if ($topics !== []): ?>
<h2 style="font-size:12pt">By topic</h2>
<table><tr><th>Topic</th><th>Right</th><th>Marks</th><th>%</th></tr>
<?php foreach ($topics as $t): ?><tr><td><?= e($t['topic']) ?></td><td><?= (int) $t['correct'] ?> of <?= (int) $t['questions'] ?></td><td><?= e(C::marks($t['awarded'])) ?> of <?= e(C::marks($t['marks'])) ?></td><td><?= e(C::marks($t['percent'])) ?>%</td></tr><?php endforeach; ?>
</table>
<?php endif; ?>
<?php if ($policy === 'answers'): ?>
<h2 style="font-size:12pt">Answers</h2>
<table><tr><th>#</th><th>Question</th><th>Answered</th><th>Right</th><th>Marks</th></tr>
<?php foreach ($questions as $q): $pos = (int) $q['position']; $order = (string) $q['choice_order'];
    $letter = static fn(string $keys): string => implode(', ', array_map(static fn(string $k): string => ($p = strpos($order, $k)) === false ? $k : chr(65 + $p), str_split($keys)));
    $right = implode('', array_map(static fn(array $c): string => (string) $c['key'], array_filter((array) json_decode((string) $q['choices'], true), static fn(array $c): bool => !empty($c['correct']))));
    $mine = (string) ($responses[$pos]['answer'] ?? ''); $tf = $q['type'] === 'true_false'; ?>
<tr><td><?= $pos ?></td><td><?= e(\App\Services\QuestionBanks::excerpt((string) $q['text'], 160)) ?></td>
    <?php if ($q['type'] === 'essay'): ?>
    <td colspan="2"><?= $mine === '' ? 'none' : nl2br(e(mb_substr($mine, 0, 4000))) ?><?= ($q['marker_feedback'] ?? null) !== null ? '<br><span class="muted">Feedback: ' . e((string) $q['marker_feedback']) . '</span>' : '' ?></td>
    <?php else: ?>
    <td><?= $mine === '' ? 'none' : e($tf ? ($mine === 'T' ? 'True' : 'False') : $letter($mine)) ?></td><td><?= e($tf ? ($right === 'T' ? 'True' : 'False') : $letter($right)) ?></td>
    <?php endif; ?>
    <td><?= e(C::marks($q['awarded'])) ?> / <?= e(C::marks($q['marks'])) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<p class="muted" style="margin-top:14pt;font-size:8.5pt">Report of an assessment set and marked by <?= e($institution) ?>. Letters are those this candidate saw.</p>
</body></html>
