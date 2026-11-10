<?php
/** An invoice, or a receipt once paid, for dompdf (inline styles are allowed here: this never reaches a browser).
 *  @var array $invoice @var list<array> $payments confirmed @var array{currency:string,issuer:string,instructions:string} $settings @var string $brand */
use App\Services\Billing;
$paid = array_sum(array_map(static fn(array $p): float => (float) $p['amount'], $payments));
$title = $invoice['status'] === 'paid' ? 'Receipt' : 'Invoice';
?><!doctype html>
<html><head><meta charset="utf-8"><title><?= e($title . ' ' . $invoice['number']) ?></title>
<style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1f2937; }
h1 { font-size: 22px; margin: 0 0 4px; }
table { width: 100%; border-collapse: collapse; }
td, th { padding: 6px 4px; vertical-align: top; text-align: left; }
.lines th { border-bottom: 1px solid #9ca3af; font-size: 10px; text-transform: uppercase; color: #6b7280; }
.lines td { border-bottom: 1px solid #e5e7eb; }
.r { text-align: right; }
.muted { color: #6b7280; }
.stamp { font-size: 18px; font-weight: bold; padding: 4px 10px; border: 2px solid; display: inline-block; }
.pre { white-space: pre-line; }
</style></head><body>
<table><tr>
    <td><h1><?= e($title) ?></h1><div class="muted"><?= e($invoice['number']) ?></div></td>
    <td class="r"><strong><?= e($brand) ?></strong><?php if ($settings['issuer'] !== ''): ?><div class="pre muted"><?= e($settings['issuer']) ?></div><?php endif; ?></td>
</tr></table>
<table style="margin-top: 18px"><tr>
    <td style="width: 55%"><div class="muted">To</div><strong><?= e($invoice['institution_name']) ?></strong>
        <div class="pre"><?= e((string) ($invoice['institution_address'] ?? '')) ?></div><div><?= e((string) ($invoice['institution_email'] ?? '')) ?></div></td>
    <td><table>
        <tr><td class="muted">Issued</td><td><?= e(substr((string) $invoice['issued_at'], 0, 10)) ?></td></tr>
        <tr><td class="muted">Due</td><td><?= e((string) $invoice['due_on']) ?></td></tr>
        <?php if ($invoice['status'] === 'paid'): ?><tr><td class="muted">Paid</td><td><?= e(substr((string) $invoice['paid_at'], 0, 10)) ?></td></tr><?php endif; ?>
    </table></td>
</tr></table>
<table class="lines" style="margin-top: 18px">
    <tr><th>Description</th><th>Period</th><th class="r">Amount</th></tr>
    <tr><td><?= e($invoice['description']) ?></td><td><?= $invoice['period_from'] !== null ? e($invoice['period_from'] . ' to ' . $invoice['period_to']) : '' ?></td><td class="r"><?= e(Billing::money($invoice)) ?></td></tr>
    <?php foreach ($payments as $p): ?>
        <tr><td colspan="2">Paid by <?= e(Billing::METHODS[$p['method']] ?? $p['method']) ?> on <?= e($p['paid_on']) ?>, reference <?= e($p['reference']) ?></td><td class="r">-<?= e(Billing::money($invoice, (string) $p['amount'])) ?></td></tr>
    <?php endforeach; ?>
    <tr><td colspan="2"><strong><?= $invoice['status'] === 'void' ? 'Void' : 'To pay' ?></strong></td><td class="r"><strong><?= e(Billing::money($invoice, (string) ($invoice['status'] === 'void' ? 0 : max(0, (float) $invoice['amount'] - $paid)))) ?></strong></td></tr>
</table>
<?php if ($invoice['status'] === 'paid'): ?><p style="margin-top: 18px"><span class="stamp" style="color: #15803d">PAID</span></p>
<?php elseif ($invoice['status'] === 'void'): ?><p style="margin-top: 18px"><span class="stamp" style="color: #6b7280">VOID</span> <?= e((string) $invoice['void_reason']) ?></p>
<?php elseif ($settings['instructions'] !== ''): ?><div style="margin-top: 22px"><strong>How to pay</strong><div class="pre"><?= e($settings['instructions']) ?></div>
    <p class="muted">Use <?= e($invoice['number']) ?> as the reference.</p></div><?php endif; ?>
</body></html>
