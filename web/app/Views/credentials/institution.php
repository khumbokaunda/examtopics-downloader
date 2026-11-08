<?php
/** @var array $institution @var array $membership @var string $tab @var list<array> $templates @var list<array> $credentials @var string $status @var list<array> $pending
 *  @var list<array> $signatories @var list<array> $staff @var bool $canManage @var bool $canIssue @var bool $isOwner @var array<string,string> $categories */
use App\Services\Access;
use App\Services\Credentials;
use App\Services\InstitutionAccess;
$base = '/institutions/' . $institution['uuid'] . '/credentials';
$tone = ['pending' => 'warning', 'issued' => 'success', 'declined' => 'neutral', 'revoked' => 'danger', 'replaced' => 'neutral', 'expired' => 'neutral'];
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<?php if ($pending !== []): ?>
<div class="card mb-4">
    <div class="card-header"><span class="card-title"><?= icon('pen') ?>Waiting for your approval</span></div>
    <ul class="list-rows">
        <?php foreach ($pending as $c): ?>
            <li class="request-row"><div class="row-main"><div class="row-text">
                <div class="row-title"><?= e($c['holder_name']) ?> · <?= e($c['title']) ?></div>
                <div class="row-meta"><span class="mono"><?= e($c['number']) ?></span> · <?= e($c['achievement']) ?><?= $c['percent'] !== null ? ' · ' . e(rtrim(rtrim((string) $c['percent'], '0'), '.')) . '%' : '' ?></div>
            </div></div>
            <form method="post" action="<?= e(url($base . '/' . $c['uuid'] . '/decision')) ?>" class="request-actions d-flex gap-2" data-once><?= csrf_field() ?>
                <button class="btn btn-sm btn-success" type="submit" name="decision" value="approve"><?= icon('pen') ?>Approve and sign</button>
                <button class="btn btn-sm btn-outline-danger" type="submit" name="decision" value="decline">Decline</button></form></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('award') ?>Templates</span>
                <?php if ($canManage): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url($base . '/templates/new')) ?>"><?= icon('plus') ?>New template</a><?php endif; ?></div>
            <?php if ($templates === []): ?>
                <div class="card-body"><?= empty_state('award', 'No templates yet', 'A template says what a credential certifies and who is eligible: passing an assessment, completing a programme, or chosen by staff.', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($templates as $t): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><?= e($t['name']) ?> <?= chip(Credentials::CATEGORIES[$t['category']] ?? $t['category'], 'info') ?><?= $t['status'] === 'retired' ? ' ' . chip('Retired', 'neutral') : '' ?></div>
                        <div class="row-meta"><?= e(Credentials::RULES[$t['rule']]) ?><?= $t['assessment_title'] !== null ? ': ' . e($t['assessment_title']) : '' ?><?= $t['programme_name'] !== null ? ': ' . e($t['programme_name']) : '' ?>
                            <?= $t['min_percent'] !== null ? ' at ' . e(rtrim(rtrim((string) $t['min_percent'], '0'), '.')) . '% or more' : '' ?> · <?= (int) $t['requires_approval'] === 1 ? 'a signatory approves each' : 'a signatory may issue directly' ?> · <?= (int) $t['issued_count'] ?> issued<?= (int) $t['pending_count'] > 0 ? ', ' . (int) $t['pending_count'] . ' waiting' : '' ?></div>
                    </div></div>
                    <div class="request-actions d-flex gap-2">
                        <?php if ($canIssue && $t['status'] === 'active'): ?><a class="btn btn-sm btn-primary" href="<?= e(url($base . '/templates/' . $t['uuid'] . '/eligible')) ?>"><?= icon('people') ?>Eligible students</a><?php endif; ?>
                        <?php if ($canManage): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/templates/' . $t['uuid'])) ?>"><?= icon('pencil') ?>Edit</a><?php endif; ?>
                    </div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('journal-check') ?>Register</span>
                <nav class="segmented" aria-label="Status"><a class="<?= $status === '' ? 'is-active' : '' ?>" href="<?= e(url($base)) ?>">All</a>
                    <?php foreach (Credentials::STATUSES as $k => $v): ?><a class="<?= $status === $k ? 'is-active' : '' ?>" href="<?= e(url($base, ['status' => $k])) ?>"><?= e($v) ?></a><?php endforeach; ?></nav></div>
            <?php if ($credentials === []): ?>
                <div class="card-body"><?= empty_state('journal-check', 'Nothing here', '', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack" data-table="institution-credentials" data-order='[[3,"desc"]]'>
                    <thead><tr><th>Holder</th><th>Credential</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($credentials as $c): $st = Credentials::state($c); ?>
                        <tr>
                            <td class="stack-title"><a class="table-row-link" href="<?= e(url($base . '/' . $c['uuid'])) ?>"><?= e($c['holder_name']) ?></a><div class="small text-muted mono"><?= e($c['number']) ?></div></td>
                            <td class="small" data-label="Credential"><?= e($c['title']) ?><div class="text-muted"><?= e($c['template_name']) ?></div></td>
                            <td data-label="Status"><?= chip($st === 'expired' ? 'Expired' : (Credentials::STATUSES[$st] ?? $st), $tone[$st] ?? 'neutral') ?><?= (int) $c['needs_review'] === 1 ? ' ' . chip('Results taken back: review', 'warning') : '' ?></td>
                            <td class="small" data-label="Date" data-order="<?= e((string) ($c['issued_at'] ?? $c['requested_at'])) ?>"><?= e(substr((string) ($c['issued_at'] ?? $c['requested_at']), 0, 10)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('pen') ?>Signatories</span></div>
            <div class="card-body">
                <p class="small">The staff an owner authorised to approve and sign credentials, by kind. Their name and position are printed on what they sign.</p>
                <?php if ($signatories === []): ?><p class="small text-muted">None yet.</p><?php endif; ?>
                <?php foreach ($signatories as $s): ?>
                    <p class="small mb-1"><strong><?= e(Access::displayName($s)) ?></strong>, <?= e($s['position']) ?> <?= (int) $s['active'] === 1 ? '' : chip('No longer signs', 'neutral') ?>
                        <span class="text-muted">· <?= e(implode(', ', array_map(static fn(string $k): string => strtolower(Credentials::CATEGORIES[$k] ?? $k), array_filter(explode(',', (string) $s['categories']))))) ?></span></p>
                <?php endforeach; ?>
                <?php if ($isOwner): ?>
                <form method="post" action="<?= e(url($base . '/signatories')) ?>" class="mt-3" data-once><?= csrf_field() ?>
                    <div class="row g-2">
                        <div class="col-sm-6 mb-2"><label class="form-label small" for="sig-member">Staff member</label><select class="form-select form-select-sm" id="sig-member" name="member" required>
                            <?php foreach ($staff as $p): ?><option value="<?= e($p['uuid']) ?>"><?= e(Access::displayName($p)) ?> (<?= e(InstitutionAccess::roleName((string) $p['role'])) ?>)</option><?php endforeach; ?></select></div>
                        <div class="col-sm-6 mb-2"><label class="form-label small" for="sig-position">Position</label><input class="form-control form-control-sm" id="sig-position" name="position" maxlength="120" placeholder="Principal" required></div>
                    </div>
                    <?php foreach ($categories as $k => $v): ?><div class="form-check small"><input class="form-check-input" type="checkbox" name="categories[]" value="<?= e($k) ?>" id="cat-<?= e($k) ?>" checked><label class="form-check-label" for="cat-<?= e($k) ?>"><?= e($v) ?></label></div><?php endforeach; ?>
                    <div class="form-check small mb-2"><input type="hidden" name="active" value="0"><input class="form-check-input" type="checkbox" name="active" value="1" id="sig-active" checked><label class="form-check-label" for="sig-active">May sign (untick to stop)</label></div>
                    <button class="btn btn-sm btn-primary" type="submit">Save signatory</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('image') ?>Logo</span></div>
            <div class="card-body small">
                <p><?= ($institution['logo_file'] ?? null) !== null ? 'A logo is set and printed on credentials.' : 'No logo yet. Credentials print the institution\'s name without one.' ?></p>
                <?php if ($canManage): ?>
                <form method="post" action="<?= e(url($base . '/logo')) ?>" enctype="multipart/form-data" class="toolbar" data-once><?= csrf_field() ?>
                    <label class="visually-hidden" for="logo">Logo</label><input class="form-control form-control-sm" type="file" id="logo" name="logo" accept="image/png,image/jpeg" required>
                    <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon('upload') ?>Upload</button></form>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('shield-check') ?>What a credential shows</span></div>
            <div class="card-body small">
                <p>Each issued credential has a number, a QR code and a link anyone can open to check it. The page shows the holder, what it certifies, the dates, who signed it, whether it still stands, and <?= e($institution['name']) ?>'s verification as it is: <?= e(implode(', ', array_map(static fn(array $v): string => strtolower($v['label'] . ' ' . $v['text']), array_slice(\App\Services\Onboarding::verification($institution), 0, 3)))) ?>.</p>
                <p class="mb-0"><?= ($institution['awarding_body'] ?? null) !== null ? 'Recorded by ExamLib as authorised to award qualifications of ' . e((string) $institution['awarding_body']) . '.' : 'Formal qualifications need ExamLib to record the institution as an authorised awarding body.' ?></p>
            </div>
        </div>
    </div>
</div>
