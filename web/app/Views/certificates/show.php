<?php /** @var array $cert @var string $verifyUrl */
use App\Services\Readiness;
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/certificates')) ?>">My certificates</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($cert['number']) ?></li>
</ol></nav>
<?php if ($cert['status'] === 'revoked'): ?>
    <div class="alert alert-danger" role="status"><?= icon('x-octagon') ?><div class="alert-body">Revoked <?= e(fmt_time((string) $cert['revoked_at'])) ?>: <?= e((string) $cert['revoke_reason']) ?></div></div>
<?php endif; ?>
<div class="card certificate-card">
    <div class="card-body text-center">
        <p class="certificate-kicker">Certificate of Demonstrated Examination Readiness</p>
        <p class="mb-1 text-muted">This is to record that</p>
        <h1 class="certificate-name"><?= e($cert['holder_name']) ?></h1>
        <p class="mb-1 text-muted">met the stated readiness criteria in preparation for</p>
        <h2 class="certificate-exam"><?= e($cert['exam_title']) ?></h2>
        <p class="mt-3"><?= e(Readiness::fmt((float) $cert['percent'])) ?>% on a timed assessment of <?= (int) $cert['questions'] ?> questions, <?= e(gmdate('j F Y', (int) strtotime($cert['issued_at'] . ' UTC'))) ?></p>
        <p class="small text-muted mb-1">Certificate <?= e($cert['number']) ?> · Verify at</p>
        <p class="small mono certificate-link"><?= e($verifyUrl) ?></p>
        <p class="small text-muted certificate-disclaimer"><?= e(Readiness::DISCLAIMER) ?></p>
    </div>
</div>
<div class="d-flex flex-wrap gap-2 mt-3">
    <a class="btn btn-primary" href="<?= e(url('/certificates/' . $cert['uuid'] . '/pdf')) ?>"><?= icon('file-earmark-pdf') ?>Download as PDF</a>
    <a class="btn btn-outline-secondary" href="<?= e($verifyUrl) ?>"><?= icon('patch-check') ?>Open the verification page</a>
</div>
<div class="card mt-4"><div class="card-body small">
    <h2 class="section-heading">The criteria it was measured against</h2>
    <p class="mb-0">At least <?= (int) Readiness::READY ?>% right on a timed mock exam of at least <?= Readiness::MIN_QUESTIONS ?> questions, with at most <?= (int) Readiness::MAX_UNANSWERED ?>% unanswered,
        and another attempt at the same exam in the <?= Readiness::WINDOW_DAYS ?> days before at <?= (int) Readiness::CONSISTENT ?>% or more.<?php if (!empty($drawn)): ?>
        Both attempts were drawn at random from at least twice as many questions, and shared at most half of them.<?php endif; ?>
        The questions are practice questions collected for this platform.</p>
</div></div>
