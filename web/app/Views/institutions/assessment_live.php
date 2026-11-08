<?php
/** @var array $institution @var array $membership @var string $tab @var array $assessment @var list<array> $rows @var bool $canIntervene @var list<array> $invigilators @var list<array> $staff */
use App\Controllers\InvigilationController;
use App\Services\Access;
$a = $assessment;
$base = '/institutions/' . $institution['uuid'] . '/assessments/' . $a['uuid'];
$label = ['not_started' => ['Not started', 'neutral'], 'writing' => ['Writing', 'primary'], 'paused' => ['Paused', 'warning'], 'handed_in' => ['Handed in', 'success']];
$watching = array_flip(array_map(static fn(array $p): string => (string) $p['uuid'], $invigilators));
$counts = array_count_values(array_column($rows, 'state'));
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Assessments' => '/institutions/' . $institution['uuid'] . '/assessments', $a['title'] => $base, 'Live' => null]]) ?>
<div class="kpi-strip">
    <?php foreach ([['Candidates', count($rows), 'people', 'neutral'], ['Writing', ($counts['writing'] ?? 0), 'pencil', 'primary'], ['Paused', ($counts['paused'] ?? 0), 'pause-circle', 'warning'],
        ['Handed in', ($counts['handed_in'] ?? 0), 'check2-square', 'success']] as [$l, $v, $ic, $t]): ?>
        <div class="kpi" data-tone="<?= e($t) ?>"><span class="kpi-icon"><?= icon($ic) ?></span><span class="kpi-text"><span class="kpi-value d-block"><?= (int) $v ?></span><span class="kpi-label d-block"><?= e($l) ?></span></span></div>
    <?php endforeach; ?>
</div>
<div class="card mb-4" data-live data-live-url="<?= e(url('/api/institutions/' . $institution['uuid'] . '/assessments/' . $a['uuid'] . '/live')) ?>">
    <div class="card-header"><span class="card-title"><?= icon('broadcast') ?>Live</span><span class="small text-muted" data-live-state>Updates every 15 seconds</span></div>
    <?php if ($rows === []): ?>
        <div class="card-body"><?= empty_state('people', 'Nobody to watch', 'The assessment reaches no students yet.', null, true) ?></div>
    <?php else: ?>
    <ul class="list-rows">
        <?php foreach ($rows as $r): [$st, $tone] = $label[$r['state']]; $live = in_array($r['state'], ['writing', 'paused'], true); ?>
            <li class="request-row" data-member="<?= e($r['membership_uuid']) ?>">
                <div class="row-main"><span class="avatar"><?= e(initials(Access::displayName($r))) ?></span><div class="row-text">
                    <div class="row-title"><?= e(Access::displayName($r)) ?><?= $r['student_number'] !== null ? ' <span class="text-muted fw-normal small mono">' . e($r['student_number']) . '</span>' : '' ?>
                        <span data-field="state"><?= chip($st, $tone) ?></span>
                        <span data-field="level"><?= match ($r['level']) { 'review' => chip('Worth a look', 'warning'), 'technical' => chip('Technical', 'info'), default => '' } ?></span></div>
                    <div class="row-meta">
                        <span data-field="progress"><?= $r['id'] !== null ? (int) $r['answered'] . ' of ' . (int) $r['question_count'] . ' answered' : '' ?></span>
                        <span data-field="time"><?= $live ? ' · ' . intdiv((int) $r['seconds_left'], 60) . ' min left' : '' ?></span>
                        <span data-field="connection"><?= $live && $r['state'] === 'writing' ? ($r['connected'] ? ' · connected' : ' · not heard from in 2 minutes') : '' ?></span>
                    </div>
                </div></div>
                <?php if ($r['id'] !== null): ?>
                <div class="request-actions">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle-panel="obs-<?= e($r['uuid']) ?>"><?= icon('eye') ?>Observe</button>
                    <?php if ($canIntervene && $live): ?><button class="btn btn-sm btn-outline-warning" type="button" data-toggle-panel="act-<?= e($r['uuid']) ?>"><?= icon('sliders') ?>Act</button><?php endif; ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/results/' . $r['uuid'])) ?>"><?= icon('file-text') ?>Sitting</a>
                </div>
                <form method="post" action="<?= e(url($base . '/live/' . $r['uuid'] . '/observe')) ?>" class="request-answer-body" id="obs-<?= e($r['uuid']) ?>" hidden data-once><?= csrf_field() ?>
                    <div class="toolbar"><label class="visually-hidden" for="cat-<?= e($r['uuid']) ?>">Kind</label>
                        <select class="form-select form-select-sm" id="cat-<?= e($r['uuid']) ?>" name="category"><?php foreach (InvigilationController::categories() as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
                        <label class="visually-hidden" for="note-<?= e($r['uuid']) ?>">What you observed</label>
                        <input class="form-control form-control-sm" id="note-<?= e($r['uuid']) ?>" name="note" maxlength="2000" placeholder="What you observed (optional for identity checked)">
                        <button class="btn btn-sm btn-primary" type="submit">Record</button></div>
                </form>
                <?php if ($canIntervene && $live): ?>
                <div class="request-answer-body" id="act-<?= e($r['uuid']) ?>" hidden>
                    <form method="post" action="<?= e(url($base . '/live/' . $r['uuid'] . '/intervene')) ?>" class="d-inline" data-once><?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-warning" type="submit" name="action" value="<?= $r['state'] === 'paused' ? 'resume' : 'pause' ?>"><?= icon($r['state'] === 'paused' ? 'play-circle' : 'pause-circle') ?><?= $r['state'] === 'paused' ? 'Resume' : 'Pause' ?></button>
                    </form>
                    <form method="post" action="<?= e(url($base . '/live/' . $r['uuid'] . '/intervene')) ?>" class="toolbar mt-2" data-once><?= csrf_field() ?><input type="hidden" name="action" value="extend">
                        <label class="visually-hidden" for="min-<?= e($r['uuid']) ?>">Minutes</label><input class="form-control form-control-sm w-auto" type="number" min="1" max="240" id="min-<?= e($r['uuid']) ?>" name="minutes" value="10">
                        <label class="visually-hidden" for="why-<?= e($r['uuid']) ?>">Why</label><input class="form-control form-control-sm" id="why-<?= e($r['uuid']) ?>" name="reason" maxlength="1000" placeholder="Why (for example: power cut)" required>
                        <button class="btn btn-sm btn-outline-primary" type="submit">Give extra time</button></form>
                    <form method="post" action="<?= e(url($base . '/live/' . $r['uuid'] . '/intervene')) ?>" class="toolbar mt-2" data-confirm="The sitting is handed in now and marked with the answers it has." data-confirm-title="End this sitting?" data-confirm-button="End it" data-confirm-tone="danger"><?= csrf_field() ?><input type="hidden" name="action" value="terminate">
                        <label class="visually-hidden" for="end-<?= e($r['uuid']) ?>">Why</label><input class="form-control form-control-sm" id="end-<?= e($r['uuid']) ?>" name="reason" maxlength="1000" placeholder="Why it is ended" required>
                        <button class="btn btn-sm btn-outline-danger" type="submit">End the sitting</button></form>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('person-badge') ?>Invigilators</span></div>
    <div class="card-body">
        <?php if ($staff !== []): ?>
        <form method="post" action="<?= e(url($base . '/invigilators')) ?>" data-once><?= csrf_field() ?>
            <?php foreach ($staff as $p): ?><div class="form-check"><input class="form-check-input" type="checkbox" id="inv-<?= e($p['uuid']) ?>" name="invigilators[]" value="<?= e($p['uuid']) ?>" <?= isset($watching[$p['uuid']]) ? 'checked' : '' ?>>
                <label class="form-check-label" for="inv-<?= e($p['uuid']) ?>"><?= e(Access::displayName($p)) ?> <span class="text-muted">· <?= e(\App\Services\InstitutionAccess::roleName((string) $p['role'])) ?></span></label></div><?php endforeach; ?>
            <button class="btn btn-sm btn-primary mt-2" type="submit"><?= icon('check2') ?>Save invigilators</button>
        </form>
        <?php else: ?>
            <p class="small mb-0"><?= $invigilators === [] ? 'None assigned.' : e(implode(', ', array_map(static fn(array $p): string => Access::displayName($p), $invigilators))) ?></p>
        <?php endif; ?>
    </div>
</div>
