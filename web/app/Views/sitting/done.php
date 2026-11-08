<?php /** @var array $session @var int $answered @var int $total */ ?>
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card">
    <div class="card-body text-center py-5">
        <span class="icon-bubble mb-3" data-tone="success"><?= icon('check2-circle') ?></span>
        <h1 class="h4"><?= $session['status'] === 'auto_submitted' ? 'Time ran out: your answers were handed in' : 'Handed in' ?></h1>
        <p class="mb-1"><?= e($session['title']) ?>, <?= e($session['institution_name']) ?>.</p>
        <p class="text-muted">At <?= e(fmt_time((string) $session['submitted_at'])) ?>. <?= $answered ?> of <?= $total ?> questions answered.</p>
        <?php if ($session['results_published_at'] !== null): ?><p><a class="btn btn-primary" href="<?= e(url('/sit/' . $session['uuid'] . '/result')) ?>"><?= icon('trophy') ?>See your result</a></p>
        <?php else: ?><p class="small text-muted">Your result is shown when the institution publishes it.</p><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= e(url('/my/assessments')) ?>"><?= icon('arrow-left') ?>My assessments</a>
    </div>
</div>
</div></div>
