<?php
/** The institution profile fields, for registering and for editing. @var array<string,mixed> $form @var array<string,string> $errors */
use App\Services\Institutions;
$field = static function (string $name, string $label, string $type = 'text', array $attrs = [], ?string $hint = null) use ($form, $errors): string {
    $id = 'inst-' . $name;
    $bad = isset($errors[$name]);
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . $k . ($v === true ? '' : '="' . e($v) . '"');
    }
    $html = '<div class="mb-3"><label class="form-label" for="' . $id . '">' . e($label) . '</label>';
    if ($type === 'textarea') {
        $html .= '<textarea class="form-control' . ($bad ? ' is-invalid' : '') . '" id="' . $id . '" name="' . $name . '"' . $extra . '>' . e((string) ($form[$name] ?? '')) . '</textarea>';
    } else {
        $html .= '<input class="form-control' . ($bad ? ' is-invalid' : '') . '" id="' . $id . '" name="' . $name . '" type="' . $type . '" value="' . e((string) ($form[$name] ?? '')) . '"' . $extra . '>';
    }
    if ($bad) {
        $html .= '<div class="invalid-feedback">' . e($errors[$name]) . '</div>';
    } elseif ($hint !== null) {
        $html .= '<div class="form-text">' . e($hint) . '</div>';
    }
    return $html . '</div>';
};
$select = static function (string $name, string $label, array $options) use ($form, $errors): string {
    $id = 'inst-' . $name;
    $bad = isset($errors[$name]);
    $html = '<div class="mb-3"><label class="form-label" for="' . $id . '">' . e($label) . '</label><select class="form-select' . ($bad ? ' is-invalid' : '') . '" id="' . $id . '" name="' . $name . '" required><option value="">Choose…</option>';
    foreach ($options as $k => $v) {
        $html .= '<option value="' . e($k) . '"' . ((string) ($form[$name] ?? '') === (string) $k ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $html . '</select>' . ($bad ? '<div class="invalid-feedback">' . e($errors[$name]) . '</div>' : '') . '</div>';
};
?>
<?php if (isset($errors['_'])): ?>
    <div class="alert alert-danger" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($errors['_']) ?></div></div>
<?php elseif ($errors !== []): ?>
    <div class="alert alert-danger" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body">Nothing was saved. <?= e(implode(' ', array_values($errors))) ?></div></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('building') ?>The institution</span></div>
            <div class="card-body">
                <?= $field('name', 'Official name', 'text', ['required' => true, 'maxlength' => '160']) ?>
                <?= $field('short_name', 'Commonly used name (optional)', 'text', ['maxlength' => '80'], 'Shown in menus and page titles when given.') ?>
                <?= $select('type', 'Kind of institution', Institutions::TYPES) ?>
                <?= $field('registration_info', 'Registration or accreditation details (optional)', 'textarea', ['rows' => '2', 'maxlength' => '500'],
                    'For example a registration number and the body that issued it. It helps the reviewer; verification here is not accreditation.') ?>
                <?= $field('intended_use', 'How you plan to use the platform', 'textarea', ['rows' => '3', 'required' => true, 'maxlength' => '1000'],
                    'For example: assessing our CCNA students during the course.') ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('geo-alt') ?>Where and who to contact</span></div>
            <div class="card-body">
                <?= $field('address', 'Physical address', 'text', ['required' => true, 'maxlength' => '255']) ?>
                <div class="row g-3">
                    <div class="col-sm-6"><?= $select('region', 'Region', Institutions::REGIONS) ?></div>
                    <div class="col-sm-6"><?= $field('district', 'District or town', 'text', ['required' => true, 'maxlength' => '80', 'list' => 'inst-districts']) ?></div>
                </div>
                <datalist id="inst-districts"><?php foreach (Institutions::DISTRICTS as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
                <div class="row g-3">
                    <div class="col-sm-6"><?= $field('email', 'Contact email', 'email', ['required' => true, 'maxlength' => '190']) ?></div>
                    <div class="col-sm-6"><?= $field('phone', 'Contact telephone', 'tel', ['required' => true, 'maxlength' => '40']) ?></div>
                </div>
                <?= $field('website', 'Website (optional)', 'url', ['maxlength' => '255', 'placeholder' => 'https://']) ?>
                <div class="row g-3">
                    <div class="col-sm-6"><?= $field('contact_name', 'Contact person', 'text', ['required' => true, 'maxlength' => '120']) ?></div>
                    <div class="col-sm-6"><?= $field('contact_position', 'Their position', 'text', ['required' => true, 'maxlength' => '120']) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
