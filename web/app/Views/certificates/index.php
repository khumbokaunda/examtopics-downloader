<?php /** @var list<array> $rows */ ?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">My certificates</h1>
        <p class="page-subtitle">Certificates of demonstrated readiness you earned on timed mock exams. Each has a link anyone can use to check it.</p>
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
