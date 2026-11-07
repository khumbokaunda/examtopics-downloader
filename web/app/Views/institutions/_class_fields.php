<?php /** @var array|null $c @var list<array> $programmes */ $id = $c['uuid'] ?? 'new'; ?>
<div class="mb-3"><label class="form-label" for="cname-<?= e($id) ?>">Name</label>
    <input class="form-control" id="cname-<?= e($id) ?>" name="name" value="<?= e((string) ($c['name'] ?? '')) ?>" maxlength="120" required placeholder="CCNA 1"></div>
<div class="mb-3"><label class="form-label" for="cintake-<?= e($id) ?>">Intake or academic year (optional)</label>
    <input class="form-control" id="cintake-<?= e($id) ?>" name="intake" value="<?= e((string) ($c['intake'] ?? '')) ?>" maxlength="40" placeholder="January 2027"></div>
<div class="mb-3"><label class="form-label" for="cprog-<?= e($id) ?>">Programme</label>
    <select class="form-select" id="cprog-<?= e($id) ?>" name="programme">
        <option value="">None</option>
        <?php foreach ($programmes as $p): ?><option value="<?= e($p['uuid']) ?>" <?= ($c['programme_uuid'] ?? '') === $p['uuid'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
    </select></div>
