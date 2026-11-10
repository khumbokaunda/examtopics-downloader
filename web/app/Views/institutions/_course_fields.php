<?php
/** The fields of a course, for creating and editing. @var array|null $c @var list<array> $classes (creating only) @var list<array> $modules @var list<array> $teachers */
use App\Services\Access;
use App\Services\InstitutionStructure;
$id = $c !== null ? 'c-' . $c['uuid'] : 'new-course';
?>
<div class="mb-3"><label class="form-label" for="<?= e($id) ?>-title">Title</label>
    <input class="form-control" id="<?= e($id) ?>-title" name="title" maxlength="160" required value="<?= e((string) ($c['title'] ?? '')) ?>"></div>
<?php if ($c === null): ?>
<div class="mb-3"><label class="form-label" for="<?= e($id) ?>-class">Class</label>
    <select class="form-select" id="<?= e($id) ?>-class" name="class" required>
        <option value="">Choose…</option>
        <?php foreach ($classes as $k): ?><option value="<?= e($k['uuid']) ?>"><?= e(InstitutionStructure::label((string) $k['name'], (string) $k['intake'])) ?> (<?= (int) $k['student_count'] ?>)</option><?php endforeach; ?>
    </select>
    <div class="form-text">Its students are the course's learners.</div></div>
<?php endif; ?>
<div class="row g-3 mb-3">
    <div class="col-sm-6"><label class="form-label" for="<?= e($id) ?>-starts">Starts</label><input class="form-control" type="date" id="<?= e($id) ?>-starts" name="starts_on" value="<?= e((string) ($c['starts_on'] ?? '')) ?>"></div>
    <div class="col-sm-6"><label class="form-label" for="<?= e($id) ?>-ends">Ends</label><input class="form-control" type="date" id="<?= e($id) ?>-ends" name="ends_on" value="<?= e((string) ($c['ends_on'] ?? '')) ?>"></div>
</div>
<div class="mb-3"><label class="form-label" for="<?= e($id) ?>-pass">Pass mark, % (optional)</label>
    <input class="form-control" type="number" id="<?= e($id) ?>-pass" name="pass_percent" min="0" max="100" step="0.01" value="<?= e($c !== null && $c['pass_percent'] !== null ? \App\Services\Gradebook::fmt((float) $c['pass_percent']) : '') ?>"></div>
<div class="mb-3"><label class="form-label" for="<?= e($id) ?>-lead">Lead instructor (optional)</label>
    <select class="form-select" id="<?= e($id) ?>-lead" name="lead">
        <option value="">None</option>
        <?php foreach ($teachers as $t): ?><option value="<?= e($t['uuid']) ?>"<?= ($c['lead_uuid'] ?? '') === $t['uuid'] ? ' selected' : '' ?>><?= e(Access::displayName($t)) ?></option><?php endforeach; ?>
    </select></div>
<?php if ($modules !== []): ?>
<div class="mb-3"><label class="form-label" for="<?= e($id) ?>-module">Module (optional)</label>
    <select class="form-select" id="<?= e($id) ?>-module" name="module">
        <option value="">None</option>
        <?php foreach ($modules as $mo): ?><option value="<?= e($mo['uuid']) ?>"<?= ($c['module_uuid'] ?? '') === $mo['uuid'] ? ' selected' : '' ?>><?= e($mo['programme_name'] . ': ' . ($mo['code'] ? $mo['code'] . ' ' : '') . $mo['name']) ?></option><?php endforeach; ?>
    </select></div>
<?php endif; ?>
<div class="mb-3"><label class="form-label" for="<?= e($id) ?>-summary">Summary (optional)</label>
    <textarea class="form-control" id="<?= e($id) ?>-summary" name="summary" rows="2" maxlength="1000"><?= e((string) ($c['summary'] ?? '')) ?></textarea></div>
