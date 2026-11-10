<?php /** @var array|null $a @var string $timezone @var bool $rubric */
use App\Controllers\InstitutionAssessmentController as AC;
use App\Services\Coursework;
$id = $a !== null ? 'as-' . $a['uuid'] : 'new-assignment';
$chosen = explode(',', (string) ($a['file_kinds'] ?? 'pdf,word'));
?>
<div class="mb-3"><label class="form-label" for="<?= e($id) ?>-title">Title</label><input class="form-control" id="<?= e($id) ?>-title" name="title" maxlength="160" required value="<?= e((string) ($a['title'] ?? '')) ?>"></div>
<div class="mb-3"><label class="form-label" for="<?= e($id) ?>-instructions">Instructions</label><textarea class="form-control" id="<?= e($id) ?>-instructions" name="instructions" rows="4" maxlength="10000"><?= e((string) ($a['instructions'] ?? '')) ?></textarea></div>
<div class="row g-3 mb-3">
    <div class="col-sm-7"><label class="form-label" for="<?= e($id) ?>-due">Due (<?= e($timezone) ?>)</label><input class="form-control" type="datetime-local" id="<?= e($id) ?>-due" name="due_at" value="<?= e(AC::localInput($a['due_at'] ?? null, $timezone)) ?>"></div>
    <div class="col-sm-5"><label class="form-label" for="<?= e($id) ?>-max">Out of</label><input class="form-control" type="number" id="<?= e($id) ?>-max" name="max_marks" min="0.01" step="0.01" required value="<?= e(\App\Services\Gradebook::fmt((float) ($a['max_marks'] ?? 100))) ?>"<?= $rubric ? ' readonly' : '' ?>>
        <?php if ($rubric): ?><div class="form-text">The rubric's total.</div><?php endif; ?></div>
</div>
<div class="mb-3"><label class="form-label" for="<?= e($id) ?>-late">Work handed in late</label><select class="form-select" id="<?= e($id) ?>-late" name="late_policy">
    <?php foreach (Coursework::LATE as $k => $label): ?><option value="<?= e($k) ?>"<?= ($a['late_policy'] ?? 'accept') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
<fieldset class="mb-3"><legend class="form-label">Files accepted</legend><div class="d-flex flex-wrap gap-3">
    <?php foreach (Coursework::KINDS as $k => $kind): ?><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="file_kinds[]" value="<?= e($k) ?>"<?= in_array($k, $chosen, true) ? ' checked' : '' ?>> <?= e($kind['label']) ?></label><?php endforeach; ?>
</div></fieldset>
<div class="row g-3 mb-3">
    <div class="col-6"><label class="form-label" for="<?= e($id) ?>-files">Files at most</label><input class="form-control" type="number" id="<?= e($id) ?>-files" name="max_files" min="1" max="10" value="<?= (int) ($a['max_files'] ?? 1) ?>"></div>
    <div class="col-6"><label class="form-label" for="<?= e($id) ?>-mb">MB each at most</label><input class="form-control" type="number" id="<?= e($id) ?>-mb" name="max_mb" min="1" max="50" value="<?= (int) ($a['max_mb'] ?? 10) ?>"></div>
</div>
