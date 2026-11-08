<?php /** @var array $institution @var array $membership @var string $tab @var array $template @var list<array> $rows @var bool $direct */
use App\Services\Access;
$t = $template;
$base = '/institutions/' . $institution['uuid'] . '/credentials';
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Credentials' => $base, $t['name'] => null]]) ?>
<form method="post" action="<?= e(url($base . '/templates/' . $t['uuid'] . '/issue')) ?>" class="card" data-once><?= csrf_field() ?>
    <div class="card-header"><span class="card-title"><?= icon('people') ?>Eligible for <?= e($t['title']) ?></span></div>
    <div class="card-body small">
        <?= $t['rule'] === 'assessment' ? 'Students who passed ' . e((string) $t['assessment_title']) . ', with results published' . ($t['min_percent'] !== null ? ', at ' . e(rtrim(rtrim((string) $t['min_percent'], '0'), '.')) . '% or more' : '') . '.'
            : ($t['rule'] === 'programme' ? 'Students recorded as having completed ' . e((string) $t['programme_name']) . '.' : 'Choose the students it is for.') ?>
        <?= $direct ? 'As a signatory for this kind, you issue them now, signed by you.' : 'They wait for a signatory to approve them.' ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card-body"><?= empty_state('people', 'Nobody is eligible yet', '', null, true) ?></div>
    <?php else: ?>
    <ul class="list-rows">
        <?php foreach ($rows as $r): ?>
            <li class="request-row"><div class="row-main"><div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="members[]" value="<?= e($r['membership_uuid']) ?>" id="m-<?= e($r['membership_uuid']) ?>" <?= $r['held'] === null ? 'checked' : '' ?>>
                <label class="form-check-label" for="m-<?= e($r['membership_uuid']) ?>"><strong><?= e(Access::displayName($r)) ?></strong><?= $r['student_number'] !== null ? ' <span class="mono small text-muted">' . e($r['student_number']) . '</span>' : '' ?>
                <span class="small text-muted"><?= $r['percent'] !== null ? '· ' . e(rtrim(rtrim((string) $r['percent'], '0'), '.')) . '%' : '' ?><?= $r['achieved_on'] !== null ? ' · ' . e((string) $r['achieved_on']) : '' ?></span>
                <?= $r['held'] !== null ? chip($r['held']['status'] === 'issued' ? 'Holds ' . $r['held']['number'] : 'Waiting for approval', 'neutral') : '' ?></label></div></div></li>
        <?php endforeach; ?>
    </ul>
    <div class="card-footer">
        <?php if ($t['rule'] === 'manual'): ?><div class="mb-2"><label class="form-label small" for="note">Added to what it certifies (optional)</label><input class="form-control form-control-sm" id="note" name="note" maxlength="400"></div><?php endif; ?>
        <div class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="allow_duplicate" value="1" id="dup"><label class="form-check-label" for="dup">Issue again to those who already hold it (a deliberate duplicate, recorded as such)</label></div>
        <button class="btn btn-primary" type="submit"><?= icon('award') ?><?= $direct ? 'Issue' : 'Ask for approval' ?></button>
    </div>
    <?php endif; ?>
</form>
