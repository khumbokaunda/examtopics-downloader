<?php /** The fields of a payment. @var array $invoice @var string $prefix */
use App\Services\Billing;
$due = max(0, (float) $invoice['amount'] - (float) ($invoice['paid_amount'] ?? 0));
?>
<div class="row g-2">
    <div class="col-sm-4"><label class="form-label small" for="<?= e($prefix) ?>-amount">Amount (<?= e($invoice['currency']) ?>)</label>
        <input class="form-control form-control-sm" id="<?= e($prefix) ?>-amount" name="amount" inputmode="decimal" required value="<?= e(number_format($due, 2, '.', '')) ?>"></div>
    <div class="col-sm-4"><label class="form-label small" for="<?= e($prefix) ?>-method">Paid by</label>
        <select class="form-select form-select-sm" id="<?= e($prefix) ?>-method" name="method" required>
            <?php foreach (Billing::METHODS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select></div>
    <div class="col-sm-4"><label class="form-label small" for="<?= e($prefix) ?>-date">Date paid</label>
        <input class="form-control form-control-sm" id="<?= e($prefix) ?>-date" name="paid_on" type="date" required value="<?= e(gmdate('Y-m-d')) ?>"></div>
    <div class="col-sm-6"><label class="form-label small" for="<?= e($prefix) ?>-ref">Reference</label>
        <input class="form-control form-control-sm" id="<?= e($prefix) ?>-ref" name="reference" required maxlength="80" placeholder="Transaction ID or slip number"></div>
    <div class="col-sm-6"><label class="form-label small" for="<?= e($prefix) ?>-note">Note <span class="text-muted">(optional)</span></label>
        <input class="form-control form-control-sm" id="<?= e($prefix) ?>-note" name="note" maxlength="255"></div>
</div>
