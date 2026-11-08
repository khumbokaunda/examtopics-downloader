<?php
/** A transcript for PDF, from its stored snapshot (dompdf: inline styles in a <style> block are fine here, nothing is served to a browser).
 *  @var array $transcript @var array $record @var array $institution */
use App\Services\AcademicRecords;
$r = $record;
$t = $transcript;
$pct = static fn(?float $p): string => $p === null ? '' : rtrim(rtrim(number_format($p, 2), '0'), '.') . '%';
?><!doctype html>
<html><head><meta charset="utf-8"><title>Transcript <?= e((string) $t['number']) ?></title>
<style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #1f2933; }
h1 { font-size: 16pt; margin: 0; } h2 { font-size: 11.5pt; margin: 14pt 0 4pt; } .muted { color: #6b7280; }
table { width: 100%; border-collapse: collapse; } th, td { text-align: left; padding: 3pt 5pt; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
th { font-size: 8.5pt; color: #6b7280; } .box { border: 1px solid #d1d5db; padding: 8pt 10pt; margin: 10pt 0; }
.prov { color: #92400e; } .withdrawn { border: 2px solid #b91c1c; color: #b91c1c; padding: 6pt 8pt; margin-bottom: 8pt; font-weight: bold; }
</style></head><body>
<?php if ($t['status'] !== 'issued'): ?><div class="withdrawn">WITHDRAWN on <?= e(substr((string) $t['withdrawn_at'], 0, 10)) ?>: <?= e((string) $t['withdrawn_reason']) ?></div><?php endif; ?>
<h1>Academic transcript</h1>
<div class="muted"><?= e((string) $r['institution']['name']) ?> · transcript <?= e((string) $t['number']) ?> · issued <?= e(gmdate('j F Y', (int) strtotime($t['issued_at'] . ' UTC'))) ?></div>
<div class="box">
    <div><strong><?= e((string) $r['student']['name']) ?></strong><?= $r['student']['number'] !== null ? ' · student number ' . e((string) $r['student']['number']) : '' ?></div>
    <div class="muted"><?= $r['student']['enrolled_on'] !== null ? 'Enrolled ' . e((string) $r['student']['enrolled_on']) : '' ?></div>
</div>
<?php foreach ($r['programmes'] as $p): ?>
<h2><?= e($p['name']) ?><?= $p['code'] !== null ? ' (' . e($p['code']) . ')' : '' ?></h2>
<div class="muted"><?= e(AcademicRecords::OUTCOMES[$p['outcome']] ?? $p['outcome']) ?> · <?= e((string) $p['started_on']) ?><?= $p['ended_on'] !== null ? ' to ' . e((string) $p['ended_on']) : ', ongoing' ?></div>
<?php if ($p['modules'] !== []): ?>
<table><tr><th>Module</th><th>Status</th><th>Result</th></tr>
<?php foreach ($p['modules'] as $m): ?><tr><td><?= e(($m['code'] !== null ? $m['code'] . ' ' : '') . $m['name']) ?><?= $m['required'] ? '' : ' (optional)' ?></td>
    <td><?= $m['completed_at'] !== null ? 'Completed ' . e(substr((string) $m['completed_at'], 0, 10)) : 'Not completed' ?></td><td><?= e($pct($m['percent'] !== null ? (float) $m['percent'] : null)) ?></td></tr><?php endforeach; ?>
</table>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($r['results'] !== []): ?>
<h2>Assessments</h2>
<table><tr><th>Assessment</th><th>Date</th><th>Result</th><th></th></tr>
<?php foreach ($r['results'] as $s): ?><tr><td><?= e($s['title']) ?><?= $s['module'] !== null ? '<br><span class="muted">' . e($s['module']) . '</span>' : '' ?></td><td><?= e(substr((string) $s['date'], 0, 10)) ?></td>
    <td><?= e($pct($s['percent'])) ?><?= $s['passed'] === null ? '' : ($s['passed'] ? ', passed' : ', not passed') ?></td><td><?= $s['final'] ? 'Final' : '<span class="prov">Provisional: results not yet published</span>' ?></td></tr><?php endforeach; ?>
</table>
<?php endif; ?>
<p class="muted" style="margin-top:16pt">Issued by <?= e((string) $r['institution']['name']) ?> through ExamLib. This transcript records what was on file when it was issued; later changes appear only on a newer transcript.
<?= !empty($r['provisional']) ? 'Results marked provisional had not been published by the institution when it was issued and may change.' : 'It contains final (published) results only.' ?></p>
</body></html>
