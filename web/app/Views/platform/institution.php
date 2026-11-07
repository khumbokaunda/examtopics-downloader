<?php /** @var array $institution @var list<array> $reviews @var list<array> $owners @var int $memberCount */
use App\Services\Access;
use App\Services\Institutions;
$i = $institution;
$tone = ['pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger', 'suspended' => 'danger'];
$decisions = array_filter(Institutions::DECISIONS, static fn(array $d): bool => in_array($i['status'], $d['from'], true));
$rows = [
    'Commonly used name' => $i['short_name'], 'Kind' => Institutions::TYPES[$i['type']] ?? $i['type'],
    'Registration or accreditation' => $i['registration_info'], 'Address' => $i['address'],
    'Region and district' => (Institutions::REGIONS[$i['region']] ?? $i['region']) . ', ' . $i['district'],
    'Email' => $i['email'], 'Telephone' => $i['phone'], 'Website' => $i['website'],
    'Contact' => $i['contact_name'] . ', ' . $i['contact_position'], 'Planned use' => $i['intended_use'],
];
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/platform/institutions')) ?>">Institutions</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($i['name']) ?></li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e($i['name']) ?></h1>
        <p class="page-subtitle"><?= chip(Institutions::STATUSES[$i['status']] ?? $i['status'], $tone[$i['status']] ?? 'neutral') ?> Registered <?= e(fmt_time((string) $i['created_at'])) ?> · <?= $memberCount ?> <?= $memberCount === 1 ? 'person' : 'people' ?></p>
    </div>
</header>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('building') ?>Registration</span></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <?php foreach ($rows as $label => $value): ?>
                        <dt class="col-sm-4"><?= e($label) ?></dt><dd class="col-sm-8"><?= $value === null || $value === '' ? '<span class="text-muted">not given</span>' : nl2br(e((string) $value)) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('person-badge') ?>Owners</span></div>
            <ul class="list-rows">
                <?php foreach ($owners as $o): ?>
                    <li class="request-row"><div class="row-main"><span class="avatar"><?= e(initials(Access::displayName($o))) ?></span><div class="row-text">
                        <div class="row-title"><?= e(Access::displayName($o)) ?></div><div class="row-meta"><?= e($o['username']) ?><?= $o['email'] !== null ? ' · ' . e($o['email']) : '' ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-xl-5">
        <?php if ($decisions !== []): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('patch-check') ?>Decision</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/platform/institutions/' . $i['uuid'] . '/decision')) ?>" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="note">Reason (the owners see it; needed to decline or suspend)</label>
                        <textarea class="form-control" id="note" name="note" rows="2" maxlength="1000"></textarea></div>
                    <div class="mb-3"><label class="form-label" for="evidence">What you checked (kept with the decision)</label>
                        <textarea class="form-control" id="evidence" name="evidence" rows="2" maxlength="1000" placeholder="For example: phoned the number given, saw the registration certificate"></textarea></div>
                    <p class="form-text">Verifying confirms a reviewer checked the registration. It is not accreditation, and the institution's pages do not say it is.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($decisions as $k => $d): ?>
                            <button class="btn btn-sm <?= $k === 'verify' ? 'btn-success' : 'btn-outline-danger' ?>" type="submit" name="decision" value="<?= e($k) ?>"><?= e($i['status'] === 'suspended' && $k === 'verify' ? 'Reinstate' : $d['label']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('clock-history') ?>Decisions so far</span></div>
            <?php if ($reviews === []): ?>
                <div class="card-body"><?= empty_state('clock-history', 'No decision yet', '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($reviews as $r): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><?= e(Institutions::STATUSES[$r['from_status']] ?? $r['from_status']) ?> → <?= e(Institutions::STATUSES[$r['to_status']] ?? $r['to_status']) ?></div>
                        <div class="row-meta"><?= e(fmt_time((string) $r['created_at'])) ?> · <?= e($r['username'] !== null ? Access::displayName($r) : 'someone') ?></div>
                        <?php if ($r['note'] !== null): ?><div class="row-meta">Reason: <?= e($r['note']) ?></div><?php endif; ?>
                        <?php if ($r['evidence'] !== null): ?><div class="row-meta">Checked: <?= e($r['evidence']) ?></div><?php endif; ?>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
