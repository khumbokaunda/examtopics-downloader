<?php /** @var array $institution @var array $membership @var string $tab @var array $programme @var list<array> $modules @var array<int,list<array>> $assessments */
$p = $programme;
$base = '/institutions/' . $institution['uuid'] . '/structure/programmes/' . $p['uuid'];
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Programmes' => '/institutions/' . $institution['uuid'] . '/structure', $p['name'] => null]]) ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('list-ol') ?>Modules</span></div>
            <?php if ($modules === []): ?>
                <div class="card-body"><?= empty_state('list-ol', 'No modules yet', 'Modules are the parts of the programme. Link assessments to them in each assessment\'s settings; passing one, once its results are published, completes the module.', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($modules as $m): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><?= $m['code'] !== null ? '<span class="mono text-muted">' . e($m['code']) . '</span> ' : '' ?><?= e($m['name']) ?> <?= (int) $m['required'] === 1 ? '' : chip('Optional', 'neutral') ?><?= $m['status'] === 'retired' ? ' ' . chip('Retired', 'neutral') : '' ?></div>
                        <div class="row-meta"><?= (int) $m['assessment_count'] ?> <?= (int) $m['assessment_count'] === 1 ? 'assessment' : 'assessments' ?><?= isset($assessments[(int) $m['id']]) ? ': ' . e(implode(', ', array_column($assessments[(int) $m['id']], 'title'))) : '' ?></div>
                    </div></div>
                    <div class="request-actions d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle-panel="mod-<?= e($m['uuid']) ?>"><?= icon('pencil') ?>Edit</button>
                        <form method="post" action="<?= e(url($base . '/modules/' . $m['uuid'] . '/retire')) ?>" data-once><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" type="submit"><?= $m['status'] === 'active' ? 'Retire' : 'Offer again' ?></button></form>
                    </div>
                    <form method="post" action="<?= e(url($base . '/modules/' . $m['uuid'])) ?>" class="request-answer-body" id="mod-<?= e($m['uuid']) ?>" hidden data-once><?= csrf_field() ?>
                        <?= \App\Core\View::current()->partial('institutions/_module_fields', ['m' => $m, 'id' => $m['uuid']]) ?>
                        <button class="btn btn-sm btn-primary" type="submit">Save</button>
                    </form></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <form method="post" action="<?= e(url($base . '/modules')) ?>" class="card-body border-top" data-once><?= csrf_field() ?>
                <p class="fw-semibold small mb-2">Add a module</p>
                <?= \App\Core\View::current()->partial('institutions/_module_fields', ['m' => null, 'id' => 'new']) ?>
                <button class="btn btn-sm btn-primary" type="submit"><?= icon('plus') ?>Add the module</button>
            </form>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('journal-text') ?>About the programme</span></div>
            <form method="post" action="<?= e(url($base . '/details')) ?>" class="card-body" data-once><?= csrf_field() ?>
                <div class="mb-3"><label class="form-label" for="p-description">Description</label><textarea class="form-control" id="p-description" name="description" rows="3" maxlength="2000"><?= e((string) $p['description']) ?></textarea></div>
                <div class="mb-3"><label class="form-label" for="p-duration">Duration</label><input class="form-control" id="p-duration" name="duration" maxlength="60" value="<?= e((string) $p['duration']) ?>" placeholder="For example: 6 months"></div>
                <div class="mb-3"><label class="form-label" for="p-objectives">Learning objectives</label><textarea class="form-control" id="p-objectives" name="objectives" rows="3" maxlength="2000"><?= e((string) $p['objectives']) ?></textarea></div>
                <div class="mb-3"><label class="form-label" for="p-req">Completion requirements</label><textarea class="form-control" id="p-req" name="completion_requirements" rows="2" maxlength="1000" placeholder="For example: every required module, and 80% attendance"><?= e((string) $p['completion_requirements']) ?></textarea>
                    <div class="form-text">Shown to staff. The record marks a student ready to complete when every required module is completed; staff confirm completion.</div></div>
                <div class="mb-3"><label class="form-label" for="p-status">Offered</label><select class="form-select" id="p-status" name="status"><option value="active"<?= $p['status'] === 'active' ? ' selected' : '' ?>>Yes</option><option value="retired"<?= $p['status'] === 'retired' ? ' selected' : '' ?>>No longer offered</option></select></div>
                <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save</button>
            </form>
        </div>
    </div>
</div>
