<?php /** The payments against an invoice. @var array $invoice @var list<array> $payments @var bool $decide whether the reader may confirm them */
use App\Services\Access;
use App\Services\Billing;
?>
<?php if ($payments === []): ?>
    <p class="small text-muted mb-0">No payment reported yet.</p>
<?php else: ?>
<ul class="list-unstyled small mb-0">
    <?php foreach ($payments as $p): ?>
        <li class="mb-2">
            <?= chip(['reported' => 'Being checked', 'confirmed' => 'Confirmed', 'rejected' => 'Not confirmed'][$p['status']] ?? $p['status'], ['reported' => 'warning', 'confirmed' => 'success', 'rejected' => 'danger'][$p['status']] ?? 'neutral') ?>
            <strong><?= e(Billing::money($invoice, (string) $p['amount'])) ?></strong> by <?= e(Billing::METHODS[$p['method']] ?? $p['method']) ?>,
            reference <span class="mono"><?= e($p['reference']) ?></span>, paid <?= e($p['paid_on']) ?>
            <?php if ($p['note'] !== null): ?><span class="text-muted">. <?= e($p['note']) ?></span><?php endif; ?>
            <div class="text-muted">Reported by <?= e($p['reporter_username'] !== null ? Access::displayName(['username' => $p['reporter_username'], 'display_name' => $p['reporter_name']]) : 'someone') ?>, <?= e(fmt_time((string) $p['reported_at'])) ?>
                <?php if ($p['decided_at'] !== null && $p['status'] !== 'reported'): ?> · <?= $p['status'] === 'confirmed' ? 'confirmed' : 'not confirmed' ?> <?= e(fmt_time((string) $p['decided_at'])) ?><?= $p['decision_note'] !== null ? ': ' . e($p['decision_note']) : '' ?><?php endif; ?></div>
            <?php if (!empty($decide) && $p['status'] === 'reported'): ?>
                <form class="d-flex flex-wrap gap-2 mt-1" method="post" action="<?= e(url('/platform/payments/' . $p['uuid'])) ?>"><?= csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($back ?? '') ?>">
                    <input class="form-control form-control-sm w-auto" name="note" maxlength="255" placeholder="Note (needed to refuse)" aria-label="Note">
                    <button class="btn btn-sm btn-success" type="submit" name="decision" value="confirm"><?= icon('check2') ?>Confirm</button>
                    <button class="btn btn-sm btn-outline-danger" type="submit" name="decision" value="reject">Not received</button>
                </form>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
