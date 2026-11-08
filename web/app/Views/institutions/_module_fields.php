<?php /** @var array|null $m @var string $id */ ?>
<div class="row g-2">
    <div class="col-sm-3 mb-2"><label class="form-label small" for="mc-<?= e($id) ?>">Code</label><input class="form-control form-control-sm" id="mc-<?= e($id) ?>" name="code" maxlength="32" value="<?= e((string) ($m['code'] ?? '')) ?>"></div>
    <div class="col-sm-9 mb-2"><label class="form-label small" for="mn-<?= e($id) ?>">Name</label><input class="form-control form-control-sm" id="mn-<?= e($id) ?>" name="name" maxlength="160" required value="<?= e((string) ($m['name'] ?? '')) ?>"></div>
</div>
<div class="mb-2"><label class="form-label small" for="md-<?= e($id) ?>">Description</label><input class="form-control form-control-sm" id="md-<?= e($id) ?>" name="description" maxlength="1000" value="<?= e((string) ($m['description'] ?? '')) ?>"></div>
<div class="form-check small mb-2"><input class="form-check-input" type="checkbox" id="mr-<?= e($id) ?>" name="required" value="1" <?= $m === null || (int) $m['required'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="mr-<?= e($id) ?>">Required to complete the programme</label></div>
