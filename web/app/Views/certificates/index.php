<?php /** @var list<array> $rows @var list<array> $credentials */ ?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">My certificates</h1>
        <p class="page-subtitle">Your credentials and readiness certificates.</p>
    </div>
</header>
<div class="card">
    <?php if ($rows === []): ?>
        <div class="card-body"><?= empty_state('award', 'No certificates yet', 'Finish a timed mock exam of at least ' . \App\Services\Readiness::MIN_QUESTIONS . ' questions that meets every readiness condition: its result page shows the conditions.', null, true) ?></div>
    <?php else: ?>
    <ul class="list-rows">
        <?php foreach ($rows as $c): ?>
            <li class="request-row"><div class="row-main"><span class="icon-bubble" data-tone="<?= $c['status'] === 'issued' ? 'success' : 'danger' ?>"><?= icon('award') ?></span><div class="row-text">
                <div class="row-title"><a href="<?= e(url('/certificates/' . $c['uuid'])) ?>"><?= e($c['exam_title']) ?></a> <?= $c['status'] === 'revoked' ? chip('Revoked', 'danger') : '' ?></div>
                <div class="row-meta"><?= e($c['number']) ?> · <?= e(\App\Services\Readiness::fmt((float) $c['percent'])) ?>% of <?= (int) $c['questions'] ?> questions · <?= e(fmt_time((string) $c['issued_at'])) ?></div>
            </div></div></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php if ($credentials !== []): ?>
<div class="card mt-4">
    <div class="card-header"><span class="card-title"><?= icon('award') ?>From your institutions</span></div>
    <ul class="list-rows">
        <?php foreach ($credentials as $c): $st = \App\Services\Credentials::state($c); ?>
            <li class="request-row"><div class="row-main"><div class="row-text">
                <div class="row-title"><?= e($c['title']) ?> <?= chip($st === 'expired' ? 'Expired' : (\App\Services\Credentials::STATUSES[$st] ?? $st), $st === 'issued' ? 'success' : ($st === 'revoked' ? 'danger' : 'neutral')) ?></div>
                <div class="row-meta"><?= e($c['institution_name']) ?> · <span class="mono"><?= e($c['number']) ?></span> · <?= e(substr((string) $c['issued_at'], 0, 10)) ?></div>
            </div></div>
            <div class="request-actions"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/credentials/' . $c['uuid'] . '/pdf')) ?>"><?= icon('download') ?>PDF</a></div></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
