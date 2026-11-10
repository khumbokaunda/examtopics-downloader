<?php
/** A course's grades for PDF (dompdf: a <style> block is fine here, nothing is served to a browser).
 *  @var array $institution @var array $course @var array{items:list<array>,rows:list<array>,final:bool,weight:float} $book @var bool $learner */
use App\Services\Access;
use App\Services\Gradebook;
use App\Services\InstitutionStructure;
$f = static fn(?float $n): string => $n === null ? '' : Gradebook::fmt($n);
?><!doctype html>
<html><head><meta charset="utf-8"><title><?= e($course['title']) ?>: grades</title>
<style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #1f2933; }
h1 { font-size: 15pt; margin: 0 0 2pt; } .muted { color: #6b7280; } table { width: 100%; border-collapse: collapse; margin-top: 10pt; }
th, td { text-align: left; padding: 3pt 5pt; border-bottom: 1px solid #e5e7eb; vertical-align: top; } th { font-size: 8pt; color: #6b7280; } td.n, th.n { text-align: right; }
.feedback { font-size: 8pt; color: #4b5563; }
</style></head><body>
<h1><?= e($course['title']) ?>: grades</h1>
<div class="muted"><?= e($institution['name']) ?> · <?= e(InstitutionStructure::label((string) $course['class_name'], (string) $course['class_intake'])) ?>
    · <?= $book['final'] ? 'Final' : 'Provisional' ?><?= $course['pass_percent'] !== null ? ' · pass mark ' . e($f((float) $course['pass_percent'])) . '%' : '' ?> · <?= e(fmt_time(\App\Core\Database::now())) ?></div>
<?php if ($learner): $r = $book['rows'][0] ?? null; ?>
<table>
    <tr><th>Item</th><th class="n">Weight</th><th class="n">Mark</th><th>Feedback</th></tr>
    <?php foreach ($book['items'] as $g): $cell = $r['cells'][(int) $g['id']] ?? null; ?>
        <tr><td><?= e($g['title']) ?></td><td class="n"><?= e($f((float) $g['weight'])) ?></td>
            <td class="n"><?= $cell === null || $cell['marks'] === null ? 'not out yet' : e($f($cell['marks'])) . ($g['assessment_id'] !== null ? '%' : ' of ' . e($f((float) $g['max_marks']))) ?></td>
            <td class="feedback"><?= e((string) ($cell['feedback'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    <tr><th colspan="2"><?= e(Access::displayName((array) $r)) ?></th><th class="n"><?= $r !== null && $r['grade'] !== null ? e($f($r['grade'])) . '%' : '' ?></th>
        <th><?= $r !== null && $r['passed'] !== null ? ($r['passed'] ? 'Passed' : 'Not passed') : '' ?></th></tr>
</table>
<?php else: ?>
<table>
    <tr><th>Learner</th><?php foreach ($book['items'] as $g): ?><th class="n"><?= e($g['title']) ?><br><?= $g['assessment_id'] !== null ? '%' : 'of ' . e($f((float) $g['max_marks'])) ?> · w <?= e($f((float) $g['weight'])) ?></th><?php endforeach; ?>
        <th class="n">Course %</th><th>Result</th></tr>
    <?php foreach ($book['rows'] as $r): ?>
        <tr><td><?= e(Access::displayName($r)) ?><?= $r['student_number'] !== null ? '<br><span class="muted">' . e($r['student_number']) . '</span>' : '' ?></td>
            <?php foreach ($book['items'] as $g): $cell = $r['cells'][(int) $g['id']] ?? null; ?><td class="n"><?= $cell !== null && $cell['marks'] !== null ? e($f($cell['marks'])) : '' ?></td><?php endforeach; ?>
            <td class="n"><?= $r['grade'] !== null ? e($f($r['grade'])) : '' ?><?= $r['override'] !== null ? ' *' : '' ?></td>
            <td><?= $r['passed'] === null ? '' : ($r['passed'] ? 'Passed' : 'Not passed') ?></td></tr>
    <?php endforeach; ?>
</table>
<?php if (array_filter($book['rows'], static fn(array $r): bool => $r['override'] !== null) !== []): ?><p class="muted">* Set by hand, with a reason on record.</p><?php endif; ?>
<?php endif; ?>
</body></html>
