<?php /** @var array $institution @var array $membership @var string $tab @var list<array> $codes @var list<array> $programmes @var list<array> $classes */
use App\Services\InstitutionStructure;
$base = '/institutions/' . $institution['uuid'] . '/students/codes';
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Students' => '/institutions/' . $institution['uuid'] . '/students', 'Enrolment codes' => null]]) ?>
<div class="row g-4">
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('key') ?>New code</span></div>
            <div class="card-body">
                <p class="small">Students with an ExamLib account enter the code at <span class="mono">/enrol</span>. A code shows only that its holder may ask to join; it does not prove who they are. Without approval, anyone holding it joins, so share such codes only where you can see who uses them.</p>
                <form method="post" action="<?= e(url($base)) ?>" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="label">What it is for (staff only)</label><input class="form-control" id="label" name="label" maxlength="120" placeholder="For example: January 2027 intake, room 4"></div>
                    <div class="row g-3">
                        <div class="col-sm-6 mb-3"><label class="form-label" for="programme">Programme</label><select class="form-select" id="programme" name="programme"><option value="">None</option>
                            <?php foreach ($programmes as $p): ?><option value="<?= e($p['uuid']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-sm-6 mb-3"><label class="form-label" for="class">Class</label><select class="form-select" id="class" name="class"><option value="">None</option>
                            <?php foreach ($classes as $c): ?><option value="<?= e($c['uuid']) ?>"><?= e(InstitutionStructure::label((string) $c['name'], (string) $c['intake'])) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-6 mb-3"><label class="form-label" for="max_uses">Most uses</label><input class="form-control" id="max_uses" name="max_uses" inputmode="numeric" maxlength="5" placeholder="No limit"></div>
                        <div class="col-sm-6 mb-3"><label class="form-label" for="expires_on">Works until</label><input class="form-control" type="date" id="expires_on" name="expires_on"></div>
                    </div>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="requires_approval" name="requires_approval" value="1" checked>
                        <label class="form-check-label" for="requires_approval">Approve each request before they join</label></div>
                    <button class="btn btn-primary" type="submit"><?= icon('plus-lg') ?>Make the code</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('list-ul') ?>Codes</span></div>
            <?php if ($codes === []): ?>
                <div class="card-body"><?= empty_state('key', 'No codes yet', 'Make one to let students in a room join without collecting their addresses first.', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($codes as $c): $open = $c['revoked_at'] === null && ($c['expires_at'] === null || (string) $c['expires_at'] > gmdate('Y-m-d H:i:s')) && ($c['max_uses'] === null || (int) $c['uses'] < (int) $c['max_uses']); ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><span class="mono"><?= e($c['code']) ?></span> <?= $open ? chip('Works', 'success') : chip($c['revoked_at'] !== null ? 'Withdrawn' : 'Used up or expired', 'neutral') ?>
                            <?= (int) $c['requires_approval'] === 1 ? chip('Needs approval', 'info') : chip('Joins at once', 'warning') ?><?= (int) $c['pending'] > 0 ? ' ' . chip((int) $c['pending'] . ' waiting', 'warning') : '' ?></div>
                        <div class="row-meta"><?= $c['label'] !== null ? e($c['label']) . ' · ' : '' ?><?= $c['programme_name'] !== null ? e($c['programme_name']) . ' · ' : '' ?><?= $c['class_name'] !== null ? e(InstitutionStructure::label((string) $c['class_name'], (string) $c['class_intake'])) . ' · ' : '' ?>used <?= (int) $c['uses'] ?><?= $c['max_uses'] !== null ? ' of ' . (int) $c['max_uses'] : '' ?><?= $c['expires_at'] !== null ? ' · until ' . e(substr((string) $c['expires_at'], 0, 10)) : '' ?></div>
                    </div></div>
                    <?php if ($c['revoked_at'] === null): ?><div class="request-actions"><form method="post" action="<?= e(url($base . '/' . $c['uuid'] . '/revoke')) ?>" data-once><?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-danger" type="submit"><?= icon('x-circle') ?>Withdraw</button></form></div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
