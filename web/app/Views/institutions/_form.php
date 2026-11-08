<?php
/** The institution profile fields, for registering and for editing. @var array<string,mixed> $form @var array<string,string> $errors
 *  @var string|null $prefix put before every field name (the join page's account has its own email field) */
use App\Services\Institutions;
$prefix ??= '';
$field = static function (string $name, string $label, string $type = 'text', array $attrs = [], ?string $hint = null) use ($form, $errors, $prefix): string {
    $id = 'inst-' . $name;
    $field = $prefix . $name;
    $bad = isset($errors[$name]);
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . $k . ($v === true ? '' : '="' . e($v) . '"');
    }
    $html = '<div class="mb-3"><label class="form-label" for="' . $id . '">' . e($label) . '</label>';
    if ($type === 'textarea') {
        $html .= '<textarea class="form-control' . ($bad ? ' is-invalid' : '') . '" id="' . $id . '" name="' . $field . '"' . $extra . '>' . e((string) ($form[$name] ?? '')) . '</textarea>';
    } else {
        $html .= '<input class="form-control' . ($bad ? ' is-invalid' : '') . '" id="' . $id . '" name="' . $field . '" type="' . $type . '" value="' . e((string) ($form[$name] ?? '')) . '"' . $extra . '>';
    }
    if ($bad) {
        $html .= '<div class="invalid-feedback">' . e($errors[$name]) . '</div>';
    } elseif ($hint !== null) {
        $html .= '<div class="form-text">' . e($hint) . '</div>';
    }
    return $html . '</div>';
};
$select = static function (string $name, string $label, array $options) use ($form, $errors, $prefix): string {
    $id = 'inst-' . $name;
    $bad = isset($errors[$name]);
    $html = '<div class="mb-3"><label class="form-label" for="' . $id . '">' . e($label) . '</label><select class="form-select' . ($bad ? ' is-invalid' : '') . '" id="' . $id . '" name="' . $prefix . $name . '" required><option value="">Choose…</option>';
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
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('building') ?>Organisation</span></div>
            <div class="card-body">
                <?= $field('name', 'Official registered name', 'text', ['required' => true, 'maxlength' => '160']) ?>
                <?= $field('short_name', 'Trading or commonly used name (optional)', 'text', ['maxlength' => '80'], 'Shown in menus and page titles when given.') ?>
                <div class="row g-3">
                    <div class="col-sm-8"><?= $select('type', 'Kind of institution', Institutions::TYPES) ?></div>
                    <div class="col-sm-4"><?= $field('year_established', 'Year established', 'text', ['inputmode' => 'numeric', 'maxlength' => '4', 'placeholder' => 'e.g. 2014']) ?></div>
                </div>
                <?= $field('services', 'The training it offers (optional)', 'textarea', ['rows' => '2', 'maxlength' => '1000'], 'For example: Cisco and CompTIA courses, short ICT courses.') ?>
                <?= $field('intended_use', 'How you plan to use the platform', 'textarea', ['rows' => '2', 'required' => true, 'maxlength' => '1000'],
                    'For example: assessing our CCNA students during the course.') ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('patch-check') ?>Registration and accreditation</span></div>
            <div class="card-body">
                <p class="form-text mt-0">Fill in what applies. Each is verified separately.</p>
                <div class="row g-3">
                    <div class="col-sm-7"><?= $field('registration_authority', 'Registration authority', 'text', ['maxlength' => '160']) ?></div>
                    <div class="col-sm-5"><?= $field('registration_number', 'Registration number', 'text', ['maxlength' => '80']) ?></div>
                </div>
                <div class="row g-3">
                    <div class="col-sm-7"><?= $field('accreditation_authority', 'Accrediting body', 'text', ['maxlength' => '160']) ?></div>
                    <div class="col-sm-5"><?= $field('accreditation_reference', 'Accreditation reference', 'text', ['maxlength' => '80']) ?></div>
                </div>
                <?= $field('accreditation_expires', 'Accreditation valid until', 'date') ?>
                <?= $field('registration_info', 'Anything else the reviewer should know (optional)', 'textarea', ['rows' => '2', 'maxlength' => '500']) ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('geo-alt') ?>Where and how to reach it</span></div>
            <div class="card-body">
                <?= $field('address', 'Physical address', 'text', ['required' => true, 'maxlength' => '255']) ?>
                <div class="row g-3">
                    <div class="col-sm-6"><?= $select('region', 'Region', Institutions::REGIONS) ?></div>
                    <div class="col-sm-6"><?= $field('district', 'District or town', 'text', ['required' => true, 'maxlength' => '80', 'list' => 'inst-districts']) ?></div>
                </div>
                <datalist id="inst-districts"><?php foreach (Institutions::DISTRICTS as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
                <div class="row g-3">
                    <div class="col-sm-6"><?= $field('email', 'Official email', 'email', ['required' => true, 'maxlength' => '190']) ?></div>
                    <div class="col-sm-6"><?= $field('phone', 'Official telephone', 'tel', ['required' => true, 'maxlength' => '40']) ?></div>
                </div>
                <?= $field('website', 'Website (optional)', 'url', ['maxlength' => '255', 'placeholder' => 'https://']) ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('person-badge') ?>Authorised representative</span></div>
            <div class="card-body">
                <p class="form-text mt-0">The person applying. A letter of authority helps.</p>
                <?= $field('contact_name', 'Full name', 'text', ['required' => true, 'maxlength' => '120']) ?>
                <div class="row g-3">
                    <div class="col-sm-6"><?= $field('contact_position', 'Position at the institution', 'text', ['required' => true, 'maxlength' => '120']) ?></div>
                    <div class="col-sm-6"><?= $field('contact_phone', 'Telephone', 'tel', ['required' => true, 'maxlength' => '40']) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
