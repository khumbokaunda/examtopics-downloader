<?php /** @var list<array> $rows @var string $q */
use App\Services\Readiness;
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Certificates</h1>
        <p class="page-subtitle">Readiness certificates issued.</p>
    </div>
</header>
<form class="toolbar mb-3" method="get" action="<?= e(url('/platform/certificates')) ?>">
    <div class="search-field"><?= icon('search') ?><label class="visually-hidden" for="cq">Search</label><input class="form-control" id="cq" name="q" value="<?= e($q) ?>" placeholder="Number, holder or exam"></div>
    <button class="btn btn-outline-secondary" type="submit">Search</button>
</form>
<div class="card">
    <?php if ($rows === []): ?>
        <div class="card-body"><?= empty_state('award', 'No certificates', '', null, true) ?></div>
    <?php else: ?>
    <ul class="list-rows">
        <?php foreach ($rows as $c): ?>
            <li class="request-row">
                <div class="row-main"><span class="icon-bubble" data-tone="<?= $c['status'] === 'issued' ? 'success' : 'danger' ?>"><?= icon('award') ?></span><div class="row-text">
                    <div class="row-title"><a href="<?= e(url('/certificates/' . $c['uuid'])) ?>"><?= e($c['number']) ?></a> · <?= e($c['holder_name']) ?> <?= $c['status'] === 'revoked' ? chip('Revoked', 'danger') : '' ?></div>
                    <div class="row-meta"><?= e($c['exam_title']) ?> · <?= e(Readiness::fmt((float) $c['percent'])) ?>% · <?= e(fmt_time((string) $c['issued_at'])) ?><?= $c['status'] === 'revoked' ? ' · ' . e((string) $c['revoke_reason']) : '' ?></div>
                </div></div>
                <?php if ($c['status'] === 'issued'): ?>
                <div class="request-actions"><button class="btn btn-sm btn-outline-danger" type="button" data-toggle-panel="rev-<?= e($c['uuid']) ?>"><?= icon('x-octagon') ?>Revoke</button></div>
                <form method="post" action="<?= e(url('/platform/certificates/' . $c['uuid'] . '/revoke')) ?>" class="request-answer-body" id="rev-<?= e($c['uuid']) ?>" hidden data-once><?= csrf_field() ?>
                    <label class="form-label small" for="reason-<?= e($c['uuid']) ?>">Reason (shown on the verification page)</label>
                    <textarea class="form-control form-control-sm mb-2" id="reason-<?= e($c['uuid']) ?>" name="reason" rows="2" maxlength="1000" required></textarea>
                    <button class="btn btn-sm btn-danger" type="submit">Revoke</button>
                </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
