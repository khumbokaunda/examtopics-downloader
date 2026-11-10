<?php /** @var array $invoice @var list<array> $payments */
use App\Services\Billing;
$v = \App\Core\View::current();
$confirmed = array_sum(array_map(static fn(array $p): float => $p['status'] === 'confirmed' ? (float) $p['amount'] : 0.0, $payments));
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/platform/billing')) ?>">Billing</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($invoice['number']) ?></li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e($invoice['number']) ?> <?= $v->partial('billing/_status', ['invoice' => $invoice]) ?></h1>
        <p class="page-subtitle"><a href="<?= e(url('/platform/institutions/' . $invoice['institution_uuid'])) ?>"><?= e($invoice['institution_name']) ?></a> · <?= e($invoice['description']) ?></p>
    </div>
    <div class="page-actions"><a class="btn btn-outline-secondary" href="<?= e(url('/platform/invoices/' . $invoice['uuid'] . '.pdf')) ?>"><?= icon('file-earmark-pdf') ?>PDF</a></div>
</header>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Amount</dt><dd class="col-7"><?= e(Billing::money($invoice)) ?></dd>
                    <dt class="col-5">Confirmed paid</dt><dd class="col-7"><?= e(Billing::money($invoice, (string) $confirmed)) ?></dd>
                    <?php if ($invoice['period_from'] !== null): ?><dt class="col-5">Period</dt><dd class="col-7"><?= e($invoice['period_from'] . ' to ' . $invoice['period_to']) ?></dd><?php endif; ?>
                    <dt class="col-5">Due</dt><dd class="col-7"><?= e((string) $invoice['due_on']) ?></dd>
                    <dt class="col-5">Issued</dt><dd class="col-7"><?= e(fmt_time((string) $invoice['issued_at'])) ?></dd>
                    <?php if ($invoice['status'] === 'void'): ?><dt class="col-5">Void</dt><dd class="col-7"><?= e(fmt_time((string) $invoice['voided_at'])) ?>: <?= e((string) $invoice['void_reason']) ?></dd><?php endif; ?>
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('cash-coin') ?>Payments</span></div>
            <div class="card-body"><?= $v->partial('billing/_payments', ['invoice' => $invoice, 'payments' => $payments, 'decide' => true, 'back' => 'invoice']) ?></div>
        </div>
    </div>
    <div class="col-lg-5">
        <?php if ($invoice['status'] === 'issued'): ?>
        <form class="card mb-4" method="post" action="<?= e(url('/platform/invoices/' . $invoice['uuid'] . '/payments')) ?>"><?= csrf_field() ?>
            <div class="card-header"><span class="card-title"><?= icon('plus-lg') ?>Record a payment received</span></div>
            <div class="card-body"><?= $v->partial('billing/_pay_fields', ['invoice' => $invoice + ['paid_amount' => $confirmed], 'prefix' => 'r']) ?>
                <button class="btn btn-sm btn-primary mt-3" type="submit"><?= icon('check2') ?>Record</button></div>
        </form>
        <form class="card" method="post" action="<?= e(url('/platform/invoices/' . $invoice['uuid'] . '/void')) ?>" data-confirm="The invoice stays on record, marked void." data-confirm-title="Make <?= e($invoice['number']) ?> void?" data-confirm-button="Make void" data-confirm-tone="danger"><?= csrf_field() ?>
            <div class="card-header"><span class="card-title"><?= icon('x-octagon') ?>Make void</span></div>
            <div class="card-body"><label class="form-label small" for="void-reason">Reason</label><input class="form-control form-control-sm" id="void-reason" name="reason" required maxlength="255">
                <button class="btn btn-sm btn-outline-danger mt-2" type="submit">Make void</button></div>
        </form>
        <?php endif; ?>
    </div>
</div>
