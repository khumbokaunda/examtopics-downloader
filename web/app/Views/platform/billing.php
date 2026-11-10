<?php /** @var list<array> $invoices @var ?string $status @var list<array> $waiting @var array{currency:string,issuer:string,instructions:string} $settings */
use App\Services\Billing;
$v = \App\Core\View::current();
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Billing</h1>
        <p class="page-subtitle">Invoices, and payments institutions reported. Issue an invoice from an institution's page.</p>
    </div>
    <div class="page-actions">
        <nav class="segmented" aria-label="Status">
            <?php foreach (['issued' => 'Unpaid', 'paid' => 'Paid', 'void' => 'Void', 'all' => 'All'] as $k => $label): ?>
                <a class="<?= ($status ?? 'all') === $k ? 'is-active' : '' ?>" href="<?= e(url('/platform/billing', ['status' => $k])) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>
<div class="row g-4">
    <div class="col-xl-8">
        <?php if ($waiting !== []): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('hourglass-split') ?>Payments to check</span><?= chip((string) count($waiting), 'warning') ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($waiting as $p): ?>
                    <li class="list-group-item">
                        <div class="small"><a href="<?= e(url('/platform/invoices/' . $p['invoice_uuid'])) ?>"><?= e($p['number']) ?></a> · <?= e($p['institution_name']) ?>:
                            <strong><?= e($p['currency'] . ' ' . number_format((float) $p['amount'], 2)) ?></strong> by <?= e(Billing::METHODS[$p['method']] ?? $p['method']) ?>,
                            reference <span class="mono"><?= e($p['reference']) ?></span>, paid <?= e($p['paid_on']) ?></div>
                        <form class="d-flex flex-wrap gap-2 mt-2" method="post" action="<?= e(url('/platform/payments/' . $p['uuid'])) ?>"><?= csrf_field() ?>
                            <input class="form-control form-control-sm w-auto" name="note" maxlength="255" placeholder="Note (needed to refuse)" aria-label="Note">
                            <button class="btn btn-sm btn-success" type="submit" name="decision" value="confirm"><?= icon('check2') ?>Confirm</button>
                            <button class="btn btn-sm btn-outline-danger" type="submit" name="decision" value="reject">Not received</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <div class="card">
            <?php if ($invoices === []): ?>
                <div class="card-body"><?= empty_state('receipt', 'No invoices here', 'Issue one from an institution\'s page.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack" data-table="invoices" data-order='[[3,"desc"]]'>
                    <thead><tr><th>Invoice</th><th>Institution</th><th class="text-end">Amount</th><th>Issued</th><th>Due</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $i): ?>
                        <tr>
                            <td class="stack-title"><a class="table-row-link" href="<?= e(url('/platform/invoices/' . $i['uuid'])) ?>"><?= e($i['number']) ?></a></td>
                            <td data-label="Institution"><?= e($i['institution_name']) ?></td>
                            <td class="text-end" data-label="Amount" data-order="<?= e((string) $i['amount']) ?>"><?= e(Billing::money($i)) ?></td>
                            <td data-label="Issued" data-order="<?= e((string) $i['issued_at']) ?>"><?= e(fmt_time((string) $i['issued_at'])) ?></td>
                            <td data-label="Due"><?= e((string) $i['due_on']) ?></td>
                            <td data-label="Status"><?= $v->partial('billing/_status', ['invoice' => $i]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <form class="card" method="post" action="<?= e(url('/platform/billing/settings')) ?>"><?= csrf_field() ?>
            <div class="card-header"><span class="card-title"><?= icon('wallet2') ?>Payment details</span></div>
            <div class="card-body">
                <div class="mb-3"><label class="form-label" for="b-currency">Currency</label><input class="form-control" id="b-currency" name="currency" maxlength="3" required value="<?= e($settings['currency']) ?>">
                    <div class="form-text">Used for new invoices.</div></div>
                <div class="mb-3"><label class="form-label" for="b-issuer">From</label><textarea class="form-control" id="b-issuer" name="issuer" rows="3" maxlength="500" placeholder="Business name, address, TPIN"><?= e($settings['issuer']) ?></textarea>
                    <div class="form-text">At the top of every invoice.</div></div>
                <div class="mb-3"><label class="form-label" for="b-how">How to pay</label><textarea class="form-control" id="b-how" name="instructions" rows="5" maxlength="1000" placeholder="Airtel Money: 099 ... (name)&#10;Bank: account number, branch"><?= e($settings['instructions']) ?></textarea>
                    <div class="form-text">On every invoice and on each institution's billing page.</div></div>
                <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save</button>
            </div>
        </form>
    </div>
</div>
