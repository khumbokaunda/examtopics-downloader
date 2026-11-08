<?php /** Public. @var array|null $cert @var string $brand */
use App\Services\Readiness;
?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h4 mb-3">Certificate verification</h1>
        <?php if ($cert === null): ?>
            <div class="alert alert-danger" role="alert"><?= icon('x-octagon') ?><div class="alert-body">No certificate matches this link. Check that it was copied in full.</div></div>
        <?php else: ?>
            <?php if ($cert['status'] === 'revoked'): ?>
                <div class="alert alert-danger" role="alert"><?= icon('x-octagon') ?><div class="alert-body"><strong>Revoked</strong> on <?= e(gmdate('j F Y', (int) strtotime($cert['revoked_at'] . ' UTC'))) ?>. Reason: <?= e((string) $cert['revoke_reason']) ?></div></div>
            <?php else: ?>
                <div class="alert alert-success" role="status"><?= icon('patch-check') ?><div class="alert-body"><strong>Genuine and current.</strong> Issued by <?= e($brand) ?>.</div></div>
            <?php endif; ?>
            <dl class="row mb-3">
                <dt class="col-5">Certificate</dt><dd class="col-7"><?= e($cert['number']) ?></dd>
                <dt class="col-5">Kind</dt><dd class="col-7">Certificate of Demonstrated Examination Readiness</dd>
                <dt class="col-5">Holder</dt><dd class="col-7"><?= e($cert['holder_name']) ?></dd>
                <dt class="col-5">Preparing for</dt><dd class="col-7"><?= e($cert['exam_title']) ?></dd>
                <dt class="col-5">Result</dt><dd class="col-7"><?= e(Readiness::fmt((float) $cert['percent'])) ?>% of <?= (int) $cert['questions'] ?> questions</dd>
                <dt class="col-5">Issued</dt><dd class="col-7"><?= e(gmdate('j F Y', (int) strtotime($cert['issued_at'] . ' UTC'))) ?></dd>
            </dl>
            <p class="small text-muted mb-0"><?= e(Readiness::DISCLAIMER) ?></p>
        <?php endif; ?>
    </div>
</div>
