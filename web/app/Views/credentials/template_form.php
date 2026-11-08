<?php /** @var array $institution @var array $membership @var string $tab @var array|null $template @var array<string,string> $categories @var list<array> $assessments @var list<array> $programmes */
use App\Services\Credentials;
$t = $template;
$base = '/institutions/' . $institution['uuid'] . '/credentials';
$v = static fn(string $k, string $d = ''): string => (string) ($t[$k] ?? $d);
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Credentials' => $base, $t === null ? 'New template' : $t['name'] => null]]) ?>
<form method="post" action="<?= e(url($base . '/templates' . ($t !== null ? '/' . $t['uuid'] : ''))) ?>" class="card" data-once><?= csrf_field() ?>
    <div class="card-header"><span class="card-title"><?= icon('award') ?><?= $t === null ? 'New credential template' : 'Edit template' ?></span></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="category">Kind</label><select class="form-select" id="category" name="category" required>
                <?php foreach ($categories as $k => $label): ?><option value="<?= e($k) ?>"<?= $v('category') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <div class="form-text">Formal qualifications appear only for an institution ExamLib recorded as an authorised awarding body.</div></div>
            <div class="col-md-6"><label class="form-label" for="name">Name (for staff)</label><input class="form-control" id="name" name="name" maxlength="120" required value="<?= e($v('name')) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="title">Title printed on it</label><input class="form-control" id="title" name="title" maxlength="160" required value="<?= e($v('title')) ?>" placeholder="Certificate of Achievement"></div>
            <div class="col-md-6"><label class="form-label" for="validity_months">Valid for (months)</label><input class="form-control" id="validity_months" name="validity_months" inputmode="numeric" maxlength="3" value="<?= e($v('validity_months')) ?>" placeholder="Does not expire"></div>
            <div class="col-12"><label class="form-label" for="body">What it certifies</label><textarea class="form-control" id="body" name="body" rows="2" maxlength="1000" required placeholder="has successfully completed the Certificate in Network Administration"><?= e($v('body')) ?></textarea></div>
            <div class="col-12"><label class="form-label" for="footer">Footer (optional)</label><input class="form-control" id="footer" name="footer" maxlength="300" value="<?= e($v('footer')) ?>"></div>
            <div class="col-md-4"><label class="form-label" for="rule">Who is eligible</label><select class="form-select" id="rule" name="rule" required>
                <?php foreach (Credentials::RULES as $k => $label): ?><option value="<?= e($k) ?>"<?= $v('rule') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="assessment">Assessment (when passing one)</label><select class="form-select" id="assessment" name="assessment"><option value="">None</option>
                <?php foreach ($assessments as $a): ?><option value="<?= e($a['uuid']) ?>"<?= $v('assessment_uuid') === $a['uuid'] ? ' selected' : '' ?>><?= e($a['title']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="min_percent">Minimum mark (%)</label><input class="form-control" id="min_percent" name="min_percent" inputmode="decimal" value="<?= e($v('min_percent')) ?>" placeholder="The pass mark"></div>
            <div class="col-md-6"><label class="form-label" for="programme">Programme (when completing one)</label><select class="form-select" id="programme" name="programme"><option value="">None</option>
                <?php foreach ($programmes as $p): ?><option value="<?= e($p['uuid']) ?>"<?= (int) ($t['programme_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            <?php if ($t !== null): ?><div class="col-md-6"><label class="form-label" for="status">In use</label><select class="form-select" id="status" name="status"><option value="active">Yes</option><option value="retired"<?= $t['status'] === 'retired' ? ' selected' : '' ?>>Retired</option></select></div><?php endif; ?>
            <div class="col-12"><div class="form-check"><input type="hidden" name="requires_approval" value="0"><input class="form-check-input" type="checkbox" id="requires_approval" name="requires_approval" value="1" <?= $t === null || (int) $t['requires_approval'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="requires_approval">A signatory approves each one (recommended for formal and final credentials). Unticked, a signatory may issue directly.</label></div></div>
        </div>
    </div>
    <div class="card-footer"><button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save</button> <a class="btn btn-outline-secondary" href="<?= e(url($base)) ?>">Cancel</a></div>
</form>
