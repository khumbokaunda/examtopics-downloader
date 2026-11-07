<?php /** @var array|null $b @var list<array> $programmes */ $id = $b['uuid'] ?? 'new'; ?>
<div class="mb-3"><label class="form-label" for="bname-<?= e($id) ?>">Name</label>
    <input class="form-control" id="bname-<?= e($id) ?>" name="name" value="<?= e((string) ($b['name'] ?? '')) ?>" maxlength="160" required placeholder="CCNA 1: Introduction to Networks"></div>
<div class="mb-3"><label class="form-label" for="bsubject-<?= e($id) ?>">Subject (optional)</label>
    <input class="form-control" id="bsubject-<?= e($id) ?>" name="subject" value="<?= e((string) ($b['subject'] ?? '')) ?>" maxlength="120" placeholder="Networking"></div>
<div class="mb-3"><label class="form-label" for="bprog-<?= e($id) ?>">Programme</label>
    <select class="form-select" id="bprog-<?= e($id) ?>" name="programme"><option value="">None</option>
        <?php foreach ($programmes as $p): ?><option value="<?= e($p['uuid']) ?>" <?= ($b['programme_uuid'] ?? '') === $p['uuid'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
    </select></div>
<div class="mb-3"><label class="form-label" for="bdesc-<?= e($id) ?>">Description (optional)</label>
    <textarea class="form-control" id="bdesc-<?= e($id) ?>" name="description" rows="2" maxlength="1000"><?= e((string) ($b['description'] ?? '')) ?></textarea></div>
