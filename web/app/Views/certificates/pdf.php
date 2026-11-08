<?php /** For dompdf only (never served to a browser). @var array $cert @var string $verifyUrl @var string $brand */
use App\Services\Readiness;
?><!doctype html>
<html><head><meta charset="utf-8"><title><?= e($cert['number']) ?></title>
<style>
@page { margin: 18mm; } body { font-family: "DejaVu Sans", sans-serif; color: #1f2933; text-align: center; }
.frame { border: 3px double #1f6f8b; padding: 18mm 14mm; } .kicker { letter-spacing: 2px; text-transform: uppercase; font-size: 11pt; color: #1f6f8b; }
.name { font-size: 26pt; margin: 6mm 0 2mm; } .exam { font-size: 15pt; margin: 2mm 0 6mm; } .muted { color: #6b7280; font-size: 10pt; }
.small { font-size: 8.5pt; color: #6b7280; margin-top: 10mm; } .revoked { color: #b91c1c; font-size: 14pt; font-weight: bold; }
</style></head><body>
<div class="frame">
    <?php if ($cert['status'] === 'revoked'): ?><div class="revoked">REVOKED</div><?php endif; ?>
    <div class="kicker">Certificate of Demonstrated Examination Readiness</div>
    <div class="muted" style="margin-top:8mm">This is to record that</div>
    <div class="name"><?= e($cert['holder_name']) ?></div>
    <div class="muted">met the stated readiness criteria in preparation for</div>
    <div class="exam"><?= e($cert['exam_title']) ?></div>
    <div><?= e(Readiness::fmt((float) $cert['percent'])) ?>% on a timed assessment of <?= (int) $cert['questions'] ?> questions, <?= e(gmdate('j F Y', (int) strtotime($cert['issued_at'] . ' UTC'))) ?></div>
    <div class="muted" style="margin-top:8mm">Issued by <?= e($brand) ?> · Certificate <?= e($cert['number']) ?></div>
    <div class="muted">Verify: <?= e($verifyUrl) ?></div>
    <div class="small"><?= e(Readiness::DISCLAIMER) ?></div>
</div>
</body></html>
