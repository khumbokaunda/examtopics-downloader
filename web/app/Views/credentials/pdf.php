<?php
/** A credential for PDF (dompdf: inline styles in a <style> block are fine here, nothing is served to a browser).
 *  @var array $credential @var array $institution @var string|null $logo @var string $qr @var string $url */
$c = $credential;
?><!doctype html>
<html><head><meta charset="utf-8"><title><?= e($c['number']) ?></title>
<style>
@page { margin: 28pt; }
body { font-family: "DejaVu Sans", sans-serif; color: #1f2933; }
.frame { border: 3px double #1e3a5f; padding: 30pt 40pt; text-align: center; height: 470pt; box-sizing: border-box; }
.logo { max-height: 60pt; margin-bottom: 8pt; } .issuer { font-size: 13pt; font-weight: bold; letter-spacing: .5pt; }
.title { font-size: 28pt; margin: 22pt 0 8pt; color: #1e3a5f; } .muted { color: #6b7280; font-size: 9.5pt; }
.name { font-size: 20pt; font-weight: bold; margin: 10pt 0; border-bottom: 1px solid #9ca3af; display: inline-block; padding: 0 20pt 4pt; }
.body { font-size: 11.5pt; margin: 8pt 30pt; } table.foot { width: 100%; margin-top: 40pt; } .foot td { vertical-align: bottom; font-size: 9.5pt; }
.sig { border-top: 1px solid #6b7280; padding-top: 3pt; width: 200pt; } .stamp { color: #b91c1c; font-weight: bold; font-size: 14pt; border: 2px solid #b91c1c; padding: 4pt 8pt; display: inline-block; margin-bottom: 8pt; }
</style></head><body>
<div class="frame">
    <?php if ($c['status'] !== 'issued'): ?><div class="stamp"><?= $c['status'] === 'revoked' ? 'REVOKED' : 'REPLACED' ?></div><?php endif; ?>
    <?php if ($logo !== null): ?><div><img class="logo" src="<?= e($logo) ?>" alt=""></div><?php endif; ?>
    <div class="issuer"><?= e((string) ($c['issuer_name'] ?? $institution['name'])) ?></div>
    <div class="title"><?= e($c['title']) ?></div>
    <div class="muted">This is to certify that</div>
    <div class="name"><?= e($c['holder_name']) ?></div>
    <div class="body"><?= e($c['achievement']) ?></div>
    <?php if ($c['programme_name'] !== null || $c['assessment_title'] !== null): ?><div class="muted"><?= e(implode(' · ', array_filter([(string) $c['programme_name'], (string) $c['assessment_title']]))) ?></div><?php endif; ?>
    <div class="muted" style="margin-top:6pt">Achieved <?= e(gmdate('j F Y', (int) strtotime($c['achieved_on'] . ' UTC'))) ?> · Issued <?= e(gmdate('j F Y', (int) strtotime((string) $c['issued_at'] . ' UTC'))) ?><?= $c['expires_on'] !== null ? ' · Valid until ' . e(gmdate('j F Y', (int) strtotime($c['expires_on'] . ' UTC'))) : '' ?></div>
    <table class="foot"><tr>
        <td style="text-align:left"><div class="sig"><strong><?= e((string) $c['signatory_name']) ?></strong><br><?= e((string) $c['signatory_position']) ?></div></td>
        <td style="text-align:right"><img src="<?= e($qr) ?>" alt="" style="width:78pt;height:78pt"><br><span class="muted"><?= e($c['number']) ?></span></td>
    </tr></table>
    <div class="muted" style="margin-top:10pt">Check it at <?= e($url) ?><?= $c['fingerprint'] !== null ? ' · fingerprint ' . e(substr((string) $c['fingerprint'], 0, 16)) : '' ?></div>
</div>
</body></html>
