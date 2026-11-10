<?php /** @var array $institution @var array $membership @var string $tab @var list<array> $invoices @var array<int,list<array>> $payments
 *  @var array{currency:string,issuer:string,instructions:string} $settings @var array|null $plan @var array{students:int,staff:int,invited_students:int,invited_staff:int} $usage */
use App\Services\Billing;
$v = \App\Core\View::current();
$base = '/institutions/' . $institution['uuid'];
?>
<?= $v->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<div class="row g-4">
    <div class="col-xl-8">
        <?php if ($invoices === []): ?>
            <div class="card"><div class="card-body"><?= empty_state('receipt', 'No invoices', 'Invoices for your plan show here.', null, true) ?></div></div>
        <?php endif; ?>
        <?php foreach ($invoices as $i): $due = max(0, (float) $i['amount'] - (float) $i['paid_amount']); ?>
            <div class="card mb-3">
                <div class="card-header"><span class="card-title"><?= icon('receipt') ?><?= e($i['number']) ?></span>
                    <span class="d-flex align-items-center gap-2"><?= $v->partial('billing/_status', ['invoice' => $i]) ?>
                        <a class="btn btn-sm btn-link" href="<?= e(url($base . '/billing/' . $i['uuid'] . '.pdf')) ?>"><?= icon('file-earmark-pdf') ?><?= $i['status'] === 'paid' ? 'Receipt' : 'PDF' ?></a></span></div>
                <div class="card-body">
                    <p class="mb-2"><?= e($i['description']) ?><?= $i['period_from'] !== null ? ' (' . e($i['period_from'] . ' to ' . $i['period_to']) . ')' : '' ?></p>
                    <p class="mb-3"><strong><?= e(Billing::money($i)) ?></strong>
                        <?php if ($i['status'] === 'issued'): ?>· due <?= e((string) $i['due_on']) ?><?= (float) $i['paid_amount'] > 0 ? ' · ' . e(Billing::money($i, (string) $due)) . ' still to pay' : '' ?><?php endif; ?>
                        <?php if ($i['status'] === 'void'): ?><span class="text-muted">· void: <?= e((string) $i['void_reason']) ?></span><?php endif; ?></p>
                    <?= $v->partial('billing/_payments', ['invoice' => $i, 'payments' => $payments[(int) $i['id']] ?? [], 'decide' => false]) ?>
                    <?php if ($i['status'] === 'issued'): ?>
                        <details class="mt-3"<?= (int) $i['waiting'] === 0 && ($payments[(int) $i['id']] ?? []) === [] ? ' open' : '' ?>>
                            <summary class="small fw-semibold">I have paid</summary>
                            <form class="mt-2" method="post" action="<?= e(url($base . '/billing/' . $i['uuid'] . '/payments')) ?>"><?= csrf_field() ?>
                                <?= $v->partial('billing/_pay_fields', ['invoice' => $i, 'prefix' => 'p' . $i['id']]) ?>
                                <button class="btn btn-sm btn-primary mt-2" type="submit"><?= icon('send') ?>Send</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('tags') ?>Your plan</span></div>
            <div class="card-body small">
                <?php if ($plan === null): ?><p class="mb-2">No plan: no limits on students or staff.</p>
                <?php else: ?><p class="mb-2"><strong><?= e($plan['name']) ?></strong><?= $plan['price'] !== null ? ', ' . e($plan['price']) : '' ?></p><?php endif; ?>
                <?php foreach (['students' => 'Students', 'staff' => 'Staff'] as $k => $label): $max = $plan['max_' . $k] ?? null; ?>
                    <div><?= $label ?>: <?= $usage[$k] ?><?= $max !== null ? ' of ' . (int) $max : '' ?><?= $usage['invited_' . $k] > 0 ? ' (' . $usage['invited_' . $k] . ' invited)' : '' ?></div>
                    <?php if ($max !== null): ?><progress class="study-meter mb-2" max="<?= max(1, (int) $max) ?>" value="<?= min($usage[$k], (int) $max) ?>" aria-label="<?= $label ?> used"></progress><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($settings['instructions'] !== ''): ?>
        <div class="card"><div class="card-header"><span class="card-title"><?= icon('wallet2') ?>How to pay</span></div>
            <div class="card-body small text-pre"><?= e($settings['instructions']) ?></div></div>
        <?php endif; ?>
    </div>
</div>
