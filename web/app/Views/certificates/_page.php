<?php
/**
 * One certificate page, A4 portrait, for dompdf (never served to a browser, so inline styles are
 * fine here). The artwork comes from CertificateArt in the site's brand colour; the words are
 * the caller's. No signature lines: who awarded it is printed as a line of text.
 *
 * @var array{page:string,seal:string} $art
 * @var string $primary the brand colour, #RRGGBB
 * @var string|null $logo a data: URI
 * @var string $issuer
 * @var string $title e.g. "Certificate of Completion"
 * @var string $name the holder
 * @var string $lead the line before the name
 * @var list<array{0:string,1:bool}> $lines what it is for: [text, emphasised]
 * @var string|null $awardedBy
 * @var list<array{0:string,1:string}> $details label and value
 * @var string $qr a data: URI @var string $verify the address to check it
 * @var string|null $note small print at the foot
 * @var string|null $stamp REVOKED or REPLACED
 */
// "Certificate of Completion" prints as a large CERTIFICATE over OF COMPLETION.
$big = $title;
$sub = '';
if (preg_match('/^\s*(certificate)\s+(of\s.+)$/iu', $title, $m) === 1) {
    $big = $m[1];
    $sub = $m[2];
}
$bigSize = mb_strlen($big) <= 12 ? 40 : (mb_strlen($big) <= 22 ? 28 : 22);
$spacing = mb_strlen($big) <= 12 ? 9 : 2;
$nameSize = mb_strlen($name) <= 22 ? 32 : (mb_strlen($name) <= 34 ? 25 : 20);
$issuerSpacing = mb_strlen($issuer) <= 30 ? 2.5 : 1.2;
$detailSize = count($details) >= 4 ? 9 : 10.5;
?><!doctype html>
<html><head><meta charset="utf-8"><title><?= e($title) ?></title>
<style>
@page { margin: 0; size: A4 portrait; }
html, body { margin: 0; padding: 0; }
body { font-family: "DejaVu Sans", sans-serif; color: #1f2933; }
.art { position: absolute; top: 0; left: 0; width: 210mm; height: 297mm; }
.content { position: absolute; top: 24mm; left: 30mm; width: 150mm; text-align: center; }
.logo { max-height: 18mm; max-width: 60mm; }
.issuer { margin-top: 3mm; font-size: 9.5pt; font-weight: bold; letter-spacing: <?= $issuerSpacing ?>pt; text-transform: uppercase; color: #374151; }
.big { margin-top: 9mm; font-size: <?= $bigSize ?>pt; font-weight: bold; letter-spacing: <?= $spacing ?>pt; text-transform: uppercase; color: <?= e($primary) ?>; line-height: 1.05; }
.sub { margin-top: 1.5mm; font-size: 14pt; letter-spacing: 4pt; text-transform: uppercase; color: #4b5563; }
.seal { margin-top: 7mm; width: 30mm; }
.lead { margin-top: 6mm; font-size: 9pt; font-weight: bold; letter-spacing: 2pt; text-transform: uppercase; color: #6b7280; }
.name { margin-top: 4mm; font-family: "DejaVu Serif", serif; font-style: italic; font-weight: bold; font-size: <?= $nameSize ?>pt; color: <?= e($primary) ?>; line-height: 1.15; }
.rule { margin: 3mm auto 0; width: 120mm; height: 0; border-top: 1.4pt solid <?= e($primary) ?>; }
.line { margin-top: 3mm; font-size: 11.5pt; line-height: 1.5; color: #374151; }
.line.em { font-size: 14pt; font-weight: bold; color: #111827; margin-top: 2mm; }
.by { margin-top: 5mm; font-size: 10pt; color: #4b5563; }
.foot { position: absolute; left: 34mm; width: 142mm; bottom: 24mm; text-align: center; }
table.details { width: 100%; border-collapse: collapse; margin-bottom: 6mm; }
.details td { text-align: center; padding: 0 1mm; vertical-align: top; }
.details .label { font-size: 7pt; letter-spacing: 1.5pt; text-transform: uppercase; color: #6b7280; }
.details .value { margin-top: 1mm; font-size: <?= $detailSize ?>pt; font-weight: bold; color: #111827; white-space: nowrap; }
table.check { margin: 0 auto; border-collapse: collapse; }
.check td { vertical-align: middle; text-align: left; padding: 0 2mm; }
.check img { width: 22mm; height: 22mm; }
.check .t { font-size: 8pt; color: #4b5563; line-height: 1.45; }
.check .t strong { color: #111827; font-size: 8.5pt; }
.note { margin-top: 4mm; font-size: 6.8pt; line-height: 1.4; color: #6b7280; }
.stamp { position: absolute; top: 120mm; left: 0; width: 210mm; text-align: center; font-size: 64pt; font-weight: bold; letter-spacing: 6pt; color: #b91c1c; opacity: .16; transform: rotate(-24deg); }
</style></head><body>
<img class="art" src="<?= e($art['page']) ?>" alt="">
<div class="content">
    <?php if ($logo !== null): ?><div><img class="logo" src="<?= e($logo) ?>" alt=""></div><?php endif; ?>
    <div class="issuer"><?= e($issuer) ?></div>
    <div class="big"><?= e($big) ?></div>
    <?php if ($sub !== ''): ?><div class="sub"><?= e($sub) ?></div><?php endif; ?>
    <div><img class="seal" src="<?= e($art['seal']) ?>" alt=""></div>
    <div class="lead"><?= e($lead) ?></div>
    <div class="name"><?= e($name) ?></div>
    <div class="rule"></div>
    <?php foreach ($lines as [$text, $em]): ?><div class="line<?= $em ? ' em' : '' ?>"><?= e($text) ?></div><?php endforeach; ?>
    <?php if ($awardedBy !== null): ?><div class="by"><?= e($awardedBy) ?></div><?php endif; ?>
</div>
<div class="foot">
    <table class="details"><tr>
        <?php foreach ($details as [$label, $value]): ?><td><div class="label"><?= e($label) ?></div><div class="value"><?= e($value) ?></div></td><?php endforeach; ?>
    </tr></table>
    <table class="check"><tr>
        <td><img src="<?= e($qr) ?>" alt=""></td>
        <td class="t"><strong>Check this certificate</strong><br>Scan the code, or open<br><?= e($verify) ?></td>
    </tr></table>
    <?php if ($note !== null && $note !== ''): ?><div class="note"><?= e($note) ?></div><?php endif; ?>
</div>
<?php if ($stamp !== null): ?><div class="stamp"><?= e($stamp) ?></div><?php endif; ?>
</body></html>
