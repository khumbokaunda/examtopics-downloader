<?php
/**
 * The institution profile fields, for registering and for editing.
 *
 * @var array<string,mixed> $form @var array<string,string> $errors
 * @var string|null $prefix put before every field name (the join page's account has its own email field)
 * @var array{shown:array<string,bool>,custom:list<array>,values:array<string,string>}|null $extras from InstitutionRequirements::form()
 * @var bool|null $wizard show the sections as steps, one at a time (register and join)
 * @var list<array{icon:string,title:string,html:string}>|null $lead sections before the profile (the join page's account)
 * @var string|null $finish the submit button, on the wizard's last step
 */
use App\Services\InstitutionRequirements;
use App\Services\Institutions;
$prefix ??= '';
$extras ??= ['shown' => [], 'custom' => [], 'values' => []];
$wizard ??= false;
$lead ??= [];
$finish ??= '';
$on = static fn(string $key): bool => $extras['shown'][$key] ?? true;
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

$custom = static function (array $f) use ($extras, $errors, $prefix): string {
    $id = 'inst-c-' . $f['uuid'];
    $key = 'custom.' . $f['uuid'];
    $bad = isset($errors[$key]);
    $value = (string) ($extras['values'][$f['uuid']] ?? '');
    $name = $prefix . 'custom[' . $f['uuid'] . ']';
    $req = (int) $f['required'] === 1 ? ' required' : '';
    $cls = ($bad ? ' is-invalid' : '');
    $html = '<div class="mb-3"' . ($f['applies_to'] !== null ? ' data-applies="' . e((string) $f['applies_to']) . '"' : '') . '><label class="form-label" for="' . $id . '">'
        . e($f['label']) . ((int) $f['required'] === 1 ? '' : ' (optional)') . '</label>';
    $html .= match ($f['field_type']) {
        'textarea' => '<textarea class="form-control' . $cls . '" id="' . $id . '" name="' . e($name) . '" rows="2" maxlength="2000"' . $req . '>' . e($value) . '</textarea>',
        'select' => '<select class="form-select' . $cls . '" id="' . $id . '" name="' . e($name) . '"' . $req . '><option value="">Choose…</option>'
            . implode('', array_map(static fn(string $o): string => '<option' . ($o === $value ? ' selected' : '') . '>' . e($o) . '</option>', explode("\n", (string) $f['options'])))
            . '</select>',
        default => '<input class="form-control' . $cls . '" id="' . $id . '" name="' . e($name) . '" type="' . e(['number' => 'number', 'date' => 'date', 'url' => 'url', 'email' => 'email'][$f['field_type']] ?? 'text')
            . '" value="' . e($value) . '" maxlength="255"' . ($f['field_type'] === 'number' ? ' step="any"' : '') . ($f['field_type'] === 'url' ? ' placeholder="https://"' : '') . $req . '>',
    };
    if ($bad) {
        $html .= '<div class="invalid-feedback">' . e($errors[$key]) . '</div>';
    } elseif ((string) ($f['help'] ?? '') !== '') {
        $html .= '<div class="form-text">' . e((string) $f['help']) . '</div>';
    }
    return $html . '</div>';
};
$sections = [];
$sections['organisation'] = ['icon' => 'building', 'title' => 'Organisation', 'html' =>
    $field('name', 'Official registered name', 'text', ['required' => true, 'maxlength' => '160'])
    . ($on('short_name') ? $field('short_name', 'Short name (optional)', 'text', ['maxlength' => '80'], 'Shown in menus.') : '')
    . '<div class="row g-3"><div class="' . ($on('year_established') ? 'col-sm-8' : 'col-12') . '">' . $select('type', 'Kind of institution', Institutions::TYPES) . '</div>'
    . ($on('year_established') ? '<div class="col-sm-4">' . $field('year_established', 'Year established', 'text', ['inputmode' => 'numeric', 'maxlength' => '4', 'placeholder' => 'e.g. 2014']) . '</div>' : '')
    . '</div>'
    . ($on('services') ? $field('services', 'Training offered (optional)', 'textarea', ['rows' => '2', 'maxlength' => '1000'], 'For example: Cisco and CompTIA courses.') : '')
    . $field('intended_use', 'How you will use the platform', 'textarea', ['rows' => '2', 'required' => true, 'minlength' => '10', 'maxlength' => '1000'], 'For example: assessing our CCNA students.')];
$sections['contact'] = ['icon' => 'geo-alt', 'title' => 'Contact', 'html' =>
    $field('address', 'Physical address', 'text', ['required' => true, 'maxlength' => '255'])
    . '<div class="row g-3"><div class="col-sm-6">' . $select('region', 'Region', Institutions::REGIONS) . '</div><div class="col-sm-6">'
    . $field('district', 'District or town', 'text', ['required' => true, 'maxlength' => '80', 'list' => 'inst-districts']) . '</div></div>'
    . '<datalist id="inst-districts">' . implode('', array_map(static fn(string $d): string => '<option value="' . e($d) . '">', Institutions::DISTRICTS)) . '</datalist>'
    . '<div class="row g-3"><div class="col-sm-6">' . $field('email', 'Official email', 'email', ['required' => true, 'maxlength' => '190']) . '</div><div class="col-sm-6">'
    . $field('phone', 'Official telephone', 'tel', ['required' => true, 'maxlength' => '40']) . '</div></div>'
    . ($on('website') ? $field('website', 'Website (optional)', 'url', ['maxlength' => '255', 'placeholder' => 'https://']) : '')];
$sections['representative'] = ['icon' => 'person-badge', 'title' => 'Representative', 'html' =>
    '<p class="form-text mt-0">The person applying.</p>'
    . $field('contact_name', 'Full name', 'text', ['required' => true, 'maxlength' => '120'])
    . '<div class="row g-3"><div class="col-sm-6">' . $field('contact_position', 'Position', 'text', ['required' => true, 'maxlength' => '120']) . '</div><div class="col-sm-6">'
    . $field('contact_phone', 'Telephone', 'tel', ['required' => true, 'maxlength' => '40']) . '</div></div>'];
$reg = '';
if ($on('registration')) {
    $reg .= '<div class="row g-3"><div class="col-sm-7">' . $field('registration_authority', 'Registration authority', 'text', ['maxlength' => '160']) . '</div><div class="col-sm-5">'
        . $field('registration_number', 'Registration number', 'text', ['maxlength' => '80']) . '</div></div>';
}
if ($on('accreditation')) {
    $reg .= '<div class="row g-3"><div class="col-sm-7">' . $field('accreditation_authority', 'Accrediting body', 'text', ['maxlength' => '160']) . '</div><div class="col-sm-5">'
        . $field('accreditation_reference', 'Accreditation reference', 'text', ['maxlength' => '80']) . '</div></div>'
        . $field('accreditation_expires', 'Accreditation valid until', 'date');
}
if ($on('registration_info')) {
    $reg .= $field('registration_info', 'Notes for the reviewer (optional)', 'textarea', ['rows' => '2', 'maxlength' => '500']);
}
if ($reg !== '') {
    $sections['registration'] = ['icon' => 'patch-check', 'title' => 'Registration', 'html' => '<p class="form-text mt-0">Fill in what applies.</p>' . $reg];
}
if ($extras['custom'] !== []) {
    $sections['details'] = ['icon' => 'list-check', 'title' => 'More details', 'html' => implode('', array_map($custom, $extras['custom']))];
}
$card = static fn(array $sec, string $class = ''): string => '<div class="card' . $class . '"><div class="card-header"><span class="card-title">' . icon($sec['icon']) . e($sec['title'])
    . '</span></div><div class="card-body">' . $sec['html'] . '</div></div>';
?>
<?php if (isset($errors['_'])): ?>
    <div class="alert alert-danger" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($errors['_']) ?></div></div>
<?php elseif ($errors !== []): ?>
    <div class="alert alert-danger" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body">Nothing was saved. <?= e(implode(' ', array_values($errors))) ?></div></div>
<?php endif; ?>
<?php if ($wizard):
    $steps = array_merge($lead, array_values(array_intersect_key($sections, array_flip(['organisation', 'contact', 'representative', 'registration', 'details']))));
    $steps[] = ['icon' => 'clipboard-check', 'title' => 'Review', 'html' => '<div class="wizard-review" data-wizard-review></div>' . $finish, 'review' => true]; ?>
<div class="wizard" data-wizard>
    <ol class="wizard-steps" aria-label="Steps">
        <?php foreach ($steps as $n => $st): ?>
            <li class="wizard-step"><button type="button" class="wizard-step-btn" data-wizard-go="<?= $n ?>"><span class="wizard-dot"><span class="wizard-num"><?= $n + 1 ?></span><?= icon('check-lg') ?></span><span class="wizard-label"><?= e($st['title']) ?></span></button></li>
        <?php endforeach; ?>
    </ol>
    <div class="wizard-bar" aria-hidden="true"><span class="wizard-bar-fill"></span></div>
    <div class="wizard-viewport"><div class="wizard-track">
        <?php foreach ($steps as $n => $st): ?>
            <section class="wizard-panel" data-wizard-panel data-title="<?= e($st['title']) ?>"<?= !empty($st['review']) ? ' data-wizard-last' : '' ?>><?= $card($st) ?></section>
        <?php endforeach; ?>
    </div></div>
    <div class="wizard-nav">
        <button class="btn btn-outline-secondary" type="button" data-wizard-back><?= icon('arrow-left') ?>Back</button>
        <span class="wizard-count small text-muted" data-wizard-count aria-live="polite"></span>
        <button class="btn btn-primary" type="button" data-wizard-next>Next<?= icon('arrow-right') ?></button>
    </div>
</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-lg-6">
        <?= $card($sections['organisation'], ' mb-4') ?>
        <?= isset($sections['registration']) ? $card($sections['registration'], ' mb-4') : '' ?>
        <?= isset($sections['details']) ? $card($sections['details'], ' mb-4') : '' ?>
    </div>
    <div class="col-lg-6">
        <?= $card($sections['contact'], ' mb-4') ?>
        <?= $card($sections['representative']) ?>
    </div>
</div>
<?php endif; ?>
