<?php /** @var list<array> $documents @var list<array> $fields @var list<array> $builtins */
use App\Services\InstitutionRequirements;
use App\Services\Institutions;
$base = '/platform/institution-requirements';
$editor = static function (?array $r, string $kind, string $id) use ($base): string {
    ob_start(); ?>
    <form method="post" action="<?= e(url($base . ($r !== null ? '/' . $r['uuid'] : ''))) ?>" class="req-form" data-once><?= csrf_field() ?>
        <input type="hidden" name="kind" value="<?= e($kind) ?>">
        <div class="row g-2">
            <div class="col-md-6"><label class="form-label small" for="label-<?= e($id) ?>">Name</label><input class="form-control form-control-sm" id="label-<?= e($id) ?>" name="label" maxlength="120" required value="<?= e((string) ($r['label'] ?? '')) ?>"></div>
            <div class="col-md-6"><label class="form-label small" for="help-<?= e($id) ?>">Hint</label><input class="form-control form-control-sm" id="help-<?= e($id) ?>" name="help" maxlength="255" value="<?= e((string) ($r['help'] ?? '')) ?>"></div>
            <?php if ($kind === 'field'): ?>
            <div class="col-md-4"><label class="form-label small" for="type-<?= e($id) ?>">Type</label><select class="form-select form-select-sm" id="type-<?= e($id) ?>" name="field_type">
                <?php foreach (InstitutionRequirements::FIELD_TYPES as $k => $v): ?><option value="<?= e($k) ?>"<?= ($r['field_type'] ?? 'text') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-8"><label class="form-label small" for="opt-<?= e($id) ?>">Choices (for Choice, one per line)</label><textarea class="form-control form-control-sm" id="opt-<?= e($id) ?>" name="options" rows="1"><?= e((string) ($r['options'] ?? '')) ?></textarea></div>
            <?php endif; ?>
            <div class="col-12"><span class="form-label small d-block">For</span><div class="d-flex flex-wrap gap-2">
                <?php $applies = $r !== null && $r['applies_to'] !== null ? explode(',', (string) $r['applies_to']) : array_keys(Institutions::TYPES);
                foreach (Institutions::TYPES as $k => $v): ?><label class="form-check form-check-inline small mb-0"><input class="form-check-input" type="checkbox" name="applies_to[]" value="<?= e($k) ?>"<?= in_array($k, $applies, true) ? ' checked' : '' ?>> <?= e($v) ?></label><?php endforeach; ?></div></div>
            <div class="col-12 d-flex align-items-center gap-3"><label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="required" value="1"<?= (int) ($r['required'] ?? 0) === 1 ? ' checked' : '' ?>> Required</label>
                <button class="btn btn-sm btn-primary" type="submit"><?= icon('check2') ?><?= $r === null ? 'Add' : 'Save' ?></button></div>
        </div>
    </form>
    <?php return (string) ob_get_clean();
};
$list = static function (array $rows, string $kind) use ($base, $editor): string {
    ob_start();
    if ($rows === []): ?><div class="card-body"><?= empty_state('list-check', 'None yet', '', null, true) ?></div><?php else: ?>
    <ul class="list-rows">
        <?php foreach ($rows as $n => $r): ?>
            <li class="req-row<?= (int) $r['active'] === 1 ? '' : ' is-muted' ?>"><div class="row-main"><div class="row-text">
                <div class="row-title"><?= e($r['label']) ?> <?= (int) $r['required'] === 1 ? chip('Required', 'warning') : chip('Optional', 'neutral') ?><?= (int) $r['active'] === 1 ? '' : ' ' . chip('Off', 'neutral') ?></div>
                <div class="row-meta"><?= $kind === 'field' ? e(InstitutionRequirements::FIELD_TYPES[$r['field_type']] ?? $r['field_type']) . ' · ' : '' ?><?= $r['applies_to'] === null ? 'All kinds' : e(implode(', ', array_map(static fn(string $k): string => Institutions::TYPES[$k] ?? $k, explode(',', (string) $r['applies_to'])))) ?></div>
            </div></div>
            <div class="req-actions">
                <form method="post" action="<?= e(url($base . '/' . $r['uuid'] . '/move')) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="up"><button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Move up"<?= $n === 0 ? ' disabled' : '' ?>><?= icon('arrow-up') ?></button></form>
                <form method="post" action="<?= e(url($base . '/' . $r['uuid'] . '/move')) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="down"><button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Move down"<?= $n === count($rows) - 1 ? ' disabled' : '' ?>><?= icon('arrow-down') ?></button></form>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle-panel="edit-<?= e($r['uuid']) ?>"><?= icon('pencil') ?></button>
                <form method="post" action="<?= e(url($base . '/' . $r['uuid'] . '/toggle')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int) $r['active'] === 1 ? 'Turn off' : 'Turn on' ?></button></form>
            </div>
            <div class="request-answer-body req-edit" id="edit-<?= e($r['uuid']) ?>" hidden><?= $editor($r, $kind, $r['uuid']) ?></div></li>
        <?php endforeach; ?>
    </ul>
    <?php endif;
    return (string) ob_get_clean();
};
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/platform/institutions')) ?>">Institutions</a></li>
    <li class="breadcrumb-item active" aria-current="page">What they provide</li>
</ol></nav>
<header class="page-header"><div class="page-header-text"><h1 class="page-title">What institutions provide</h1><p class="page-subtitle">Documents and profile fields.</p></div></header>
<div class="row g-4">
    <div class="col-xl-6">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('file-earmark-text') ?>Documents</span><button class="btn btn-sm btn-outline-primary" type="button" data-toggle-panel="new-document"><?= icon('plus') ?>Add</button></div>
            <div class="card-body border-bottom" id="new-document" hidden><?= $editor(null, 'document', 'nd') ?></div>
            <?= $list($documents, 'document') ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('input-cursor-text') ?>Extra profile fields</span><button class="btn btn-sm btn-outline-primary" type="button" data-toggle-panel="new-field"><?= icon('plus') ?>Add</button></div>
            <div class="card-body border-bottom" id="new-field" hidden><?= $editor(null, 'field', 'nf') ?></div>
            <?= $list($fields, 'field') ?>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('ui-checks') ?>Built-in fields</span></div>
            <ul class="list-rows">
                <?php foreach ($builtins as $b): ?>
                    <li class="req-row"><div class="row-main"><div class="row-text"><div class="row-title fw-normal"><?= e($b['label']) ?></div></div></div>
                        <form method="post" action="<?= e(url($base . '/' . $b['uuid'] . '/toggle')) ?>" class="req-actions"><?= csrf_field() ?>
                            <?= (int) $b['active'] === 1 ? chip('Shown', 'success') : chip('Hidden', 'neutral') ?>
                            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int) $b['active'] === 1 ? 'Hide' : 'Show' ?></button></form></li>
                <?php endforeach; ?>
            </ul>
            <div class="card-body pt-0"><p class="form-text mb-0">Name, kind, address, contacts and representative are always asked.</p></div>
        </div>
    </div>
</div>
