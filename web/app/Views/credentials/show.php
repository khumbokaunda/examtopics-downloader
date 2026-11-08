<?php /** @var array $institution @var array $membership @var string $tab @var array $credential @var list<array> $events @var bool $canManage @var bool $canSign @var string $verifyUrl */
use App\Services\Access;
use App\Services\Credentials;
$c = $credential;
$st = Credentials::state($c);
$base = '/institutions/' . $institution['uuid'] . '/credentials';
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Credentials' => $base, $c['number'] => null]]) ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('award') ?><?= e($c['title']) ?></span><?= chip($st === 'expired' ? 'Expired' : (Credentials::STATUSES[$st] ?? $st), $st === 'issued' ? 'success' : ($st === 'revoked' ? 'danger' : 'neutral')) ?></div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-sm-4">Number</dt><dd class="col-sm-8 mono"><?= e($c['number']) ?></dd>
                    <dt class="col-sm-4">Holder</dt><dd class="col-sm-8"><?= e($c['holder_name']) ?></dd>
                    <dt class="col-sm-4">Certifies</dt><dd class="col-sm-8"><?= e($c['achievement']) ?></dd>
                    <?php if ($c['assessment_title'] !== null): ?><dt class="col-sm-4">Assessment</dt><dd class="col-sm-8"><?= e($c['assessment_title']) ?><?= $c['percent'] !== null ? ', ' . e(rtrim(rtrim((string) $c['percent'], '0'), '.')) . '%' : '' ?></dd><?php endif; ?>
                    <?php if ($c['programme_name'] !== null): ?><dt class="col-sm-4">Programme</dt><dd class="col-sm-8"><?= e($c['programme_name']) ?></dd><?php endif; ?>
                    <dt class="col-sm-4">Achieved</dt><dd class="col-sm-8"><?= e((string) $c['achieved_on']) ?></dd>
                    <dt class="col-sm-4">Issued</dt><dd class="col-sm-8"><?= $c['issued_at'] !== null ? e(fmt_time((string) $c['issued_at'])) : 'Not yet' ?><?= $c['expires_on'] !== null ? ' · valid until ' . e((string) $c['expires_on']) : '' ?></dd>
                    <?php if ($c['signatory_name'] !== null): ?><dt class="col-sm-4">Signed by</dt><dd class="col-sm-8"><?= e($c['signatory_name']) ?>, <?= e((string) $c['signatory_position']) ?></dd><?php endif; ?>
                    <?php if ($c['revoke_reason'] !== null): ?><dt class="col-sm-4">Revoked</dt><dd class="col-sm-8"><?= e(fmt_time((string) $c['revoked_at'])) ?>: <?= e($c['revoke_reason']) ?></dd><?php endif; ?>
                    <?php if ($c['fingerprint'] !== null): ?><dt class="col-sm-4">Fingerprint</dt><dd class="col-sm-8 mono small text-break"><?= e($c['fingerprint']) ?></dd><?php endif; ?>
                </dl>
            </div>
            <?php if (in_array($c['status'], ['issued', 'revoked', 'replaced'], true)): ?>
            <div class="card-footer d-flex flex-wrap gap-2"><a class="btn btn-sm btn-primary" href="<?= e(url($base . '/' . $c['uuid'] . '/pdf')) ?>"><?= icon('file-earmark-pdf') ?>PDF</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e($verifyUrl) ?>" target="_blank" rel="noopener"><?= icon('shield-check') ?>Verification page</a></div>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('clock-history') ?>History</span></div>
            <ul class="list-rows">
                <?php foreach ($events as $ev): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text"><div class="row-title fw-normal"><?= e(ucfirst($ev['action'])) ?><?= $ev['note'] !== null ? ': ' . e($ev['note']) : '' ?></div>
                        <div class="row-meta"><?= e(fmt_time((string) $ev['created_at'])) ?><?= $ev['username'] !== null ? ' · ' . e(Access::displayName($ev)) : '' ?></div></div></div></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-xl-5">
        <?php if ($canManage && $c['status'] === 'issued'): ?>
        <form method="post" action="<?= e(url($base . '/' . $c['uuid'] . '/revoke')) ?>" class="card mb-4" data-confirm="Anyone verifying it will see it is revoked, and why." data-confirm-title="Revoke <?= e($c['number']) ?>?" data-confirm-button="Revoke" data-confirm-tone="danger"><?= csrf_field() ?>
            <div class="card-header"><span class="card-title"><?= icon('x-octagon') ?>Revoke</span></div>
            <div class="card-body"><label class="form-label small" for="reason">Reason (shown on the verification page)</label><input class="form-control form-control-sm mb-2" id="reason" name="reason" maxlength="500" required>
                <button class="btn btn-sm btn-outline-danger" type="submit">Revoke</button></div>
        </form>
        <?php if ($canSign): ?>
        <form method="post" action="<?= e(url($base . '/' . $c['uuid'] . '/correct')) ?>" class="card" data-once><?= csrf_field() ?>
            <div class="card-header"><span class="card-title"><?= icon('pencil-square') ?>Issue a corrected version</span></div>
            <div class="card-body">
                <p class="small">Replaces this one. The original stays, marked replaced.</p>
                <div class="mb-2"><label class="form-label small" for="holder_name">Holder's name</label><input class="form-control form-control-sm" id="holder_name" name="holder_name" maxlength="160" required value="<?= e($c['holder_name']) ?>"></div>
                <div class="mb-2"><label class="form-label small" for="achievement">What it certifies</label><textarea class="form-control form-control-sm" id="achievement" name="achievement" rows="2" maxlength="1000" required><?= e($c['achievement']) ?></textarea></div>
                <div class="mb-2"><label class="form-label small" for="why">What was corrected</label><input class="form-control form-control-sm" id="why" name="reason" maxlength="500" required></div>
                <button class="btn btn-sm btn-primary" type="submit">Issue the corrected version</button>
            </div>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
