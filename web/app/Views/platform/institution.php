<?php /** @var array $institution @var list<array> $reviews @var list<array> $owners @var int $memberCount @var list<array> $documents
 *  @var list<array> $acceptances @var list<string> $missing @var list<array> $slots @var list<array{0:string,1:string}> $fields @var string|null $purgeWhy
 *  @var list<array> $invoices @var string $currency for new invoices
 *  @var array|null $plan @var list<array> $plans offered @var array{students:int,staff:int,invited_students:int,invited_staff:int} $usage @var list<array> $planHistory */
use App\Services\Access;
use App\Services\Institutions;
use App\Services\Onboarding;
use App\Services\Policies;
$i = $institution;
$tone = \App\Services\Institutions::TONES;
$decisions = array_filter(Institutions::DECISIONS, static fn(array $d): bool => in_array($i['status'], $d['from'], true));
$rows = [
    'Trading name' => $i['short_name'], 'Kind' => Institutions::TYPES[$i['type']] ?? $i['type'], 'Established' => $i['year_established'],
    'Training offered' => $i['services'], 'Planned use' => $i['intended_use'], 'Address' => $i['address'],
    'Region and district' => (Institutions::REGIONS[$i['region']] ?? $i['region']) . ', ' . $i['district'],
    'Official email' => $i['email'], 'Official telephone' => $i['phone'], 'Website' => $i['website'],
    'Registration' => trim(($i['registration_authority'] ?? '') . ' ' . ($i['registration_number'] !== null ? '(' . $i['registration_number'] . ')' : '')),
    'Accreditation' => trim(($i['accreditation_authority'] ?? '') . ' ' . ($i['accreditation_reference'] !== null ? '(' . $i['accreditation_reference'] . ')' : '')
        . ($i['accreditation_expires'] !== null ? ', valid until ' . $i['accreditation_expires'] : '')),
    'Other notes' => $i['registration_info'],
    'Representative' => $i['contact_name'] . ', ' . $i['contact_position'] . ($i['contact_phone'] !== null ? ' · ' . $i['contact_phone'] : ''),
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
<?php if ($i['deleted_at'] !== null): ?>
    <div class="alert alert-danger" role="status"><?= icon('trash') ?><div class="alert-body">Deleted <?= e(fmt_time((string) $i['deleted_at'])) ?><?= $i['delete_reason'] !== null ? ': ' . e((string) $i['delete_reason']) : '' ?>. Hidden and closed; restore below.</div></div>
<?php endif; ?>
<div class="card mb-4"><div class="card-body"><?= \App\Core\View::current()->partial('institutions/_milestones', ['institution' => $i]) ?></div></div>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('building') ?>Registration</span></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <?php foreach ($fields as [$flabel, $fvalue]) { $rows[$flabel] = $fvalue; } foreach ($rows as $label => $value): ?>
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
        <div class="card mt-4">
            <div class="card-header"><span class="card-title"><?= icon('file-earmark-text') ?>Documents</span></div>
            <?php if ($slots === []): ?>
                <div class="card-body"><?= empty_state('file-earmark', 'None asked for', '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($slots as $slot): $d = $slot['files'][0] ?? null; ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><?= e($slot['label']) ?> <?= (int) $slot['required'] === 1 ? chip('Required', 'warning') : '' ?></div>
                        <div class="row-meta"><?php if ($d !== null): ?><a href="<?= e(url('/platform/institutions/' . $i['uuid'] . '/documents/' . $d['uuid'])) ?>"><?= e($d['filename']) ?></a> · <?= e(fmt_bytes((int) $d['size'])) ?> · <?= e(fmt_time((string) $d['created_at'])) ?><?php else: ?>Not uploaded<?php endif; ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <div class="card mt-4">
            <div class="card-header"><span class="card-title"><?= icon('journal-check') ?>Agreement</span></div>
            <div class="card-body small">
                <?php if ($acceptances === []): ?>Not accepted yet.<?php else: ?>
                <?php foreach (array_slice($acceptances, 0, count(Policies::INSTITUTION)) as $a): ?>
                    <div><?= e(Policies::POLICIES[$a['policy']]['title'] ?? $a['policy']) ?> v<?= e($a['version']) ?> · <?= e(Access::displayName($a)) ?>, <?= e(fmt_time((string) $a['accepted_at'])) ?></div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <form method="post" action="<?= e(url('/platform/institutions/' . $i['uuid'] . '/awarding')) ?>" class="card mb-4" data-once><?= csrf_field() ?>
            <div class="card-header"><span class="card-title"><?= icon('mortarboard') ?>Formal qualifications</span></div>
            <div class="card-body">
                <p class="small">Set only after seeing their authority.</p>
                <div class="toolbar"><label class="visually-hidden" for="awarding_body">Awarding body</label>
                    <input class="form-control form-control-sm" id="awarding_body" name="awarding_body" maxlength="160" value="<?= e((string) ($i['awarding_body'] ?? '')) ?>" placeholder="Not authorised">
                    <button class="btn btn-sm btn-outline-primary" type="submit">Save</button></div>
            </div>
        </form>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('shield-check') ?>Verification</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/platform/institutions/' . $i['uuid'] . '/decision')) ?>" data-once><?= csrf_field() ?>
                    <?php foreach (Onboarding::DIMENSIONS as $k => $d): ?>
                        <div class="mb-2 row g-2 align-items-center"><label class="col-sm-6 col-form-label col-form-label-sm" for="dim-<?= e($k) ?>"><?= e($d['label']) ?></label>
                            <div class="col-sm-6"><select class="form-select form-select-sm" id="dim-<?= e($k) ?>" name="<?= e($k) ?>">
                                <?php foreach ($d['states'] as $sk => $sl): ?><option value="<?= e($sk) ?>"<?= $i[$d['column']] === $sk ? ' selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?>
                            </select></div></div>
                    <?php endforeach; ?>
                    <div class="mb-2"><label class="form-label small" for="dim-note">Note (kept in the history; the owners see it)</label><input class="form-control form-control-sm" id="dim-note" name="note" maxlength="1000"></div>
                    <div class="mb-3"><label class="form-label small" for="dim-evidence">What you checked</label><input class="form-control form-control-sm" id="dim-evidence" name="evidence" maxlength="1000" placeholder="For example: checked the number with TEVETA"></div>
                    <button class="btn btn-sm btn-outline-primary" type="submit" name="decision" value="dimensions"><?= icon('check2') ?>Save verification</button>
                </form>
            </div>
        </div>
        <?php if ($decisions !== []): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('patch-check') ?>Decision</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/platform/institutions/' . $i['uuid'] . '/decision')) ?>" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="note">Reason (the owners see it; needed to decline or suspend)</label>
                        <textarea class="form-control" id="note" name="note" rows="2" maxlength="1000"></textarea></div>
                    <div class="mb-3"><label class="form-label" for="evidence">What you checked (kept with the decision)</label>
                        <textarea class="form-control" id="evidence" name="evidence" rows="2" maxlength="1000" placeholder="For example: phoned the number given, saw the registration certificate"></textarea></div>
                    <?php if ($missing !== [] && in_array($i['status'], ['pending', 'under_review', 'info_requested'], true)): ?><p class="small text-danger"><?= e(implode(' ', $missing)) ?></p><?php endif; ?>
                    <p class="form-text">Verify identity and representative before approving.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($decisions as $k => $d): ?>
                            <button class="btn btn-sm <?= $k === 'verify' ? 'btn-success' : (in_array($k, ['review', 'request_info'], true) ? 'btn-outline-primary' : 'btn-outline-danger') ?>" type="submit" name="decision" value="<?= e($k) ?>"><?= e($i['status'] === 'suspended' && $k === 'verify' ? 'Reinstate' : $d['label']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('tags') ?>Plan</span><?= $plan !== null ? chip($plan['name'], 'primary') : chip('No plan, no limits', 'neutral') ?></div>
            <div class="card-body">
                <dl class="row small mb-3">
                    <?php foreach (['students' => 'Students', 'staff' => 'Staff'] as $k => $label): $max = $plan['max_' . $k] ?? null; ?>
                        <dt class="col-5"><?= $label ?></dt>
                        <dd class="col-7"><?= $usage[$k] ?><?= $max !== null ? ' of ' . (int) $max : '' ?><?= $usage['invited_' . $k] > 0 ? ', ' . $usage['invited_' . $k] . ' invited' : '' ?></dd>
                    <?php endforeach; ?>
                </dl>
                <form method="post" action="<?= e(url('/platform/institutions/' . $i['uuid'] . '/plan')) ?>"><?= csrf_field() ?>
                    <div class="mb-2"><label class="form-label small" for="plan-pick">Change to</label>
                        <select class="form-select form-select-sm" id="plan-pick" name="plan">
                            <option value="">No plan (no limits)</option>
                            <?php foreach ($plans as $p): ?><option value="<?= e($p['uuid']) ?>"<?= $plan !== null && (int) $plan['id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?><?= $p['price'] !== null ? ', ' . e($p['price']) : '' ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="mb-2"><label class="form-label small" for="plan-note">Note</label><input class="form-control form-control-sm" id="plan-note" name="note" maxlength="255" placeholder="For example: paid for the year by bank transfer"></div>
                    <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon('check2') ?>Change plan</button>
                    <?php if ($plans === []): ?><a class="small ms-2" href="<?= e(url('/platform/plans')) ?>">Add plans</a><?php endif; ?>
                </form>
                <?php if ($planHistory !== []): ?>
                    <ul class="list-unstyled small text-muted mt-3 mb-0">
                        <?php foreach ($planHistory as $h): ?><li><?= e(fmt_time((string) $h['changed_at'])) ?>: <?= e($h['plan_name'] ?? 'No plan') ?><?= $h['username'] !== null ? ', by ' . e(Access::displayName($h)) : '' ?><?= $h['note'] !== null ? '. ' . e($h['note']) : '' ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('receipt') ?>Invoices</span></div>
            <div class="card-body">
                <?php if ($invoices !== []): ?>
                    <ul class="list-unstyled small mb-3">
                        <?php foreach ($invoices as $inv): ?><li class="mb-1"><a href="<?= e(url('/platform/invoices/' . $inv['uuid'])) ?>"><?= e($inv['number']) ?></a>
                            <?= e(\App\Services\Billing::money($inv)) ?> <?= \App\Core\View::current()->partial('billing/_status', ['invoice' => $inv]) ?><?= (int) $inv['waiting'] > 0 ? ' ' . chip('payment to check', 'warning') : '' ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <details<?= $invoices === [] ? ' open' : '' ?>><summary class="small fw-semibold">Issue an invoice</summary>
                <form class="mt-2" method="post" action="<?= e(url('/platform/institutions/' . $i['uuid'] . '/invoices')) ?>"><?= csrf_field() ?>
                    <div class="mb-2"><label class="form-label small" for="inv-desc">For</label><input class="form-control form-control-sm" id="inv-desc" name="description" required maxlength="255"
                        value="<?= e($plan !== null ? $plan['name'] . ' plan' : '') ?>"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small" for="inv-amount">Amount (<?= e($currency) ?>)</label><input class="form-control form-control-sm" id="inv-amount" name="amount" inputmode="decimal" required></div>
                        <div class="col-6"><label class="form-label small" for="inv-due">Due</label><input class="form-control form-control-sm" id="inv-due" name="due_on" type="date" required value="<?= e(gmdate('Y-m-d', time() + 14 * 86400)) ?>"></div>
                        <div class="col-6"><label class="form-label small" for="inv-from">Period from</label><input class="form-control form-control-sm" id="inv-from" name="period_from" type="date"></div>
                        <div class="col-6"><label class="form-label small" for="inv-to">to</label><input class="form-control form-control-sm" id="inv-to" name="period_to" type="date"></div>
                    </div>
                    <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon('send') ?>Issue</button>
                    <p class="form-text mb-0">The institution's managers are told. An issued invoice cannot be changed, only made void.</p>
                </form></details>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('clock-history') ?>Decisions so far</span></div>
            <?php if ($reviews === []): ?>
                <div class="card-body"><?= empty_state('clock-history', 'No decision yet', '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($reviews as $r): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <?php $dim = Onboarding::DIMENSIONS[$r['dimension']] ?? null; $lbl = $dim !== null ? $dim['states'] : Institutions::STATUSES; ?>
                        <div class="row-title"><?= $dim !== null ? e($dim['label']) . ': ' : '' ?><?= e($lbl[$r['from_status']] ?? $r['from_status']) ?> → <?= e($lbl[$r['to_status']] ?? $r['to_status']) ?></div>
                        <div class="row-meta"><?= e(fmt_time((string) $r['created_at'])) ?> · <?= e($r['username'] !== null ? Access::displayName($r) : 'someone') ?></div>
                        <?php if ($r['note'] !== null): ?><div class="row-meta">Reason: <?= e($r['note']) ?></div><?php endif; ?>
                        <?php if ($r['evidence'] !== null): ?><div class="row-meta">Checked: <?= e($r['evidence']) ?></div><?php endif; ?>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
            <div class="card mt-4 border-danger-subtle">
            <div class="card-header"><span class="card-title text-danger"><?= icon('exclamation-triangle') ?>Danger zone</span></div>
            <div class="card-body">
                <?php if ($i['deleted_at'] !== null): ?>
                    <form method="post" action="<?= e(url('/platform/institutions/' . $i['uuid'] . '/restore')) ?>" class="mb-3" data-once><?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon('arrow-counterclockwise') ?>Restore</button></form>
                    <?php if ($purgeWhy === null): ?>
                    <form method="post" action="<?= e(url('/platform/institutions/' . $i['uuid'] . '/purge')) ?>" data-confirm="Everything in it is erased. This cannot be undone." data-confirm-title="Remove for good?" data-confirm-button="Remove for good" data-confirm-tone="danger"><?= csrf_field() ?>
                        <label class="form-label small" for="purge-name">Type <strong><?= e($i['name']) ?></strong> to remove it for good</label>
                        <input class="form-control form-control-sm mb-2" id="purge-name" name="name" autocomplete="off" required>
                        <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash') ?>Remove for good</button></form>
                    <?php else: ?><p class="small text-muted mb-0"><?= e((string) $purgeWhy) ?></p><?php endif; ?>
                <?php elseif ($i['status'] === 'verified'): ?>
                    <p class="small mb-0">Suspend it first to delete it.</p>
                <?php else: ?>
                    <form method="post" action="<?= e(url('/platform/institutions/' . $i['uuid'] . '/delete')) ?>" data-confirm="Its people lose access. You can restore it." data-confirm-title="Delete <?= e($i['name']) ?>?" data-confirm-button="Delete" data-confirm-tone="danger"><?= csrf_field() ?>
                        <label class="form-label small" for="del-reason">Reason</label>
                        <input class="form-control form-control-sm mb-2" id="del-reason" name="reason" maxlength="500" required>
                        <label class="form-label small" for="del-name">Type <strong><?= e($i['name']) ?></strong> to confirm</label>
                        <input class="form-control form-control-sm mb-2" id="del-name" name="name" autocomplete="off" required>
                        <button class="btn btn-sm btn-outline-danger" type="submit"><?= icon('trash') ?>Delete</button></form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
