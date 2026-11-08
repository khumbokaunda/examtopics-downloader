<?php
/** @var array $institution @var array $membership @var string $tab @var list<array> $members @var list<array> $invitations @var bool $manage @var bool $showRemoved */
use App\Services\Access;
use App\Services\InstitutionAccess;
$base = '/institutions/' . $institution['uuid'];
$isOwner = $membership['role'] === InstitutionAccess::OWNER;
$offer = array_filter(InstitutionAccess::ROLES, static fn(string $k): bool => $k !== InstitutionAccess::OWNER || $isOwner, ARRAY_FILTER_USE_KEY);
$mine = InstitutionAccess::permissions($membership);
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>

<div class="row g-4">
    <div class="<?= $manage ? 'col-xl-8' : 'col-12' ?>">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('people') ?><?= $showRemoved ? 'Everyone, including people who left' : 'People' ?></span>
                <?php if ($manage): ?><a class="small" href="<?= e(url($base . '/members', $showRemoved ? [] : ['removed' => '1'])) ?>"><?= $showRemoved ? 'Only current people' : 'Include people who left' ?></a><?php endif; ?></div>
            <ul class="list-rows">
                <?php foreach ($members as $p): $active = $p['status'] === 'active'; $self = (int) $p['id'] === (int) $membership['id'];
                    $editable = $manage && $active && !$self && ($p['role'] !== InstitutionAccess::OWNER || $isOwner); $extras = InstitutionAccess::extras($p); ?>
                    <li class="request-row">
                        <div class="row-main">
                            <span class="avatar"><?= e(initials(Access::displayName($p))) ?></span>
                            <div class="row-text">
                                <div class="row-title"><?= e(Access::displayName($p)) ?><?= $self ? ' <span class="text-muted fw-normal">(you)</span>' : '' ?>
                                    <span class="chip-group"><?= chip(InstitutionAccess::roleName((string) $p['role']), $p['role'] === InstitutionAccess::OWNER ? 'primary' : 'neutral', 'shield') ?>
                                    <?php if ($extras !== []): ?><?= chip('+' . count($extras), 'info') ?><?php endif; ?>
                                    <?php if (!$active): ?><?= chip('Left ' . fmt_time((string) $p['removed_at']), 'neutral') ?><?php endif; ?></span></div>
                                <div class="row-meta"><?= e($p['username']) ?><?php if ($manage && $p['email'] !== null): ?> · <?= e($p['email']) ?><?php endif; ?> · joined <?= e(fmt_time((string) $p['joined_at'])) ?></div>
                            </div>
                        </div>
                        <?php if ($editable): ?>
                        <div class="request-actions">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle-panel="member-<?= e($p['uuid']) ?>"><?= icon('person-gear') ?>Role</button>
                            <form method="post" action="<?= e(url($base . '/members/' . $p['uuid'] . '/remove')) ?>" data-confirm="<?= e(Access::displayName($p)) ?> loses access to <?= e($institution['name']) ?>. What they did here stays in its history." data-confirm-title="Remove this person?" data-confirm-button="Remove"><?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit"><?= icon('person-dash') ?>Remove</button>
                            </form>
                        </div>
                        <form method="post" action="<?= e(url($base . '/members/' . $p['uuid'] . '/role')) ?>" class="request-answer-body" id="member-<?= e($p['uuid']) ?>" hidden data-once><?= csrf_field() ?>
                            <label class="form-label small" for="role-<?= e($p['uuid']) ?>">Role</label>
                            <select class="form-select form-select-sm mb-2" id="role-<?= e($p['uuid']) ?>" name="role">
                                <?php foreach ($offer as $k => $r): ?><option value="<?= e($k) ?>" <?= $p['role'] === $k ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
                            </select>
                            <div class="small text-muted mb-1">Also allow, on top of the role (owners have everything):</div>
                            <?php foreach (InstitutionAccess::PERMISSIONS as $perm => $text): $given = in_array($perm, InstitutionAccess::ROLE_PERMISSIONS[$p['role']] ?? [], true); ?>
                                <div class="form-check small">
                                    <input class="form-check-input" type="checkbox" id="perm-<?= e($p['uuid'] . '-' . $perm) ?>" name="permissions[]" value="<?= e($perm) ?>"
                                        <?= in_array($perm, $extras, true) || $given ? 'checked' : '' ?> <?= $given || !in_array($perm, $mine, true) ? 'disabled' : '' ?>>
                                    <label class="form-check-label" for="perm-<?= e($p['uuid'] . '-' . $perm) ?>"><?= e($text) ?><?= $given ? ' <span class="text-muted">(the role gives it)</span>' : '' ?></label>
                                </div>
                            <?php endforeach; ?>
                            <button class="btn btn-sm btn-primary mt-2" type="submit"><?= icon('check2') ?>Save</button>
                        </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php if ($manage): ?>
    <div class="col-xl-4">
        <div class="card mb-4" id="invite">
            <div class="card-header"><span class="card-title"><?= icon('envelope-plus') ?>Invite someone</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base . '/invitations')) ?>" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="invite-email">Email address</label>
                        <input class="form-control" id="invite-email" name="email" type="email" maxlength="190" required></div>
                    <div class="mb-3"><label class="form-label" for="invite-role">Role</label>
                        <select class="form-select" id="invite-role" name="role">
                            <?php foreach ($offer as $k => $r): ?><option value="<?= e($k) ?>" <?= $k === InstitutionAccess::STUDENT ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
                        </select></div>
                    <p class="form-text">Lasts <?= \App\Services\Institutions::INVITE_DAYS ?> days.</p>
                    <button class="btn btn-primary" type="submit"><?= icon('send') ?>Send the invitation</button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('envelope') ?>Waiting to be accepted</span></div>
            <?php if ($invitations === []): ?>
                <div class="card-body"><?= empty_state('envelope', 'No open invitations', '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($invitations as $v): ?>
                    <li class="request-row">
                        <div class="row-main"><div class="row-text">
                            <div class="row-title"><?= e($v['email']) ?></div>
                            <div class="row-meta"><?= e(InstitutionAccess::roleName((string) $v['role'])) ?> · until <?= e(fmt_time((string) $v['expires_at'])) ?></div>
                        </div></div>
                        <div class="request-actions">
                            <form method="post" action="<?= e(url($base . '/invitations/' . (int) $v['id'] . '/revoke')) ?>" data-once><?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= icon('x-circle') ?>Withdraw</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
