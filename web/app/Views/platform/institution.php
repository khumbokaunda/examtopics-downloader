<?php /** @var array $institution @var list<array> $reviews @var list<array> $owners @var int $memberCount @var list<array> $documents
 *  @var list<array> $acceptances @var list<string> $missing */
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
        <div class="card mt-4">
            <div class="card-header"><span class="card-title"><?= icon('file-earmark-text') ?>Documents</span></div>
            <?php if ($documents === []): ?>
                <div class="card-body"><?= empty_state('file-earmark', 'No documents', Onboarding::required($i) === [] ? 'None are required for this kind of institution.' : 'Required: ' . implode(', ', array_map(static fn(string $k): string => strtolower(Onboarding::DOCUMENTS[$k]), Onboarding::required($i))) . '.', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($documents as $d): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><a href="<?= e(url('/platform/institutions/' . $i['uuid'] . '/documents/' . $d['uuid'])) ?>"><?= e($d['filename']) ?></a></div>
                        <div class="row-meta"><?= e(Onboarding::DOCUMENTS[$d['kind']] ?? $d['kind']) ?> · <?= e(fmt_bytes((int) $d['size'])) ?> · <?= e(fmt_time((string) $d['created_at'])) ?> · <span class="mono"><?= e(substr((string) $d['sha256'], 0, 12)) ?></span></div>
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
                    <p class="form-text">Approving needs the organisation's identity and the representative's authority verified (above). Registration and accreditation are shown exactly as set.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($decisions as $k => $d): ?>
                            <button class="btn btn-sm <?= $k === 'verify' ? 'btn-success' : (in_array($k, ['review', 'request_info'], true) ? 'btn-outline-primary' : 'btn-outline-danger') ?>" type="submit" name="decision" value="<?= e($k) ?>"><?= e($i['status'] === 'suspended' && $k === 'verify' ? 'Reinstate' : $d['label']) ?></button>
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
    </div>
</div>
