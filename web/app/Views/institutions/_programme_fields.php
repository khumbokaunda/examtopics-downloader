<?php /** @var array|null $p @var list<array> $departments @var string $id */ ?>
<div class="row g-2 mb-2">
    <div class="col-sm-7"><label class="form-label small" for="pname-<?= e($id) ?>">Name</label>
        <input class="form-control form-control-sm" id="pname-<?= e($id) ?>" name="name" value="<?= e((string) ($p['name'] ?? '')) ?>" maxlength="160" required placeholder="Diploma in Networking"></div>
    <div class="col-sm-5"><label class="form-label small" for="pcode-<?= e($id) ?>">Code (optional)</label>
        <input class="form-control form-control-sm" id="pcode-<?= e($id) ?>" name="code" value="<?= e((string) ($p['code'] ?? '')) ?>" maxlength="32"></div>
</div>
<div class="mb-2"><label class="form-label small" for="pdept-<?= e($id) ?>">Department</label>
    <select class="form-select form-select-sm" id="pdept-<?= e($id) ?>" name="department">
        <option value="">None</option>
        <?php foreach ($departments as $d): ?><option value="<?= e($d['uuid']) ?>" <?= (int) ($p['department_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></div>
