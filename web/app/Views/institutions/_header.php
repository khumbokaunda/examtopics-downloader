<?php
/** The heading and tabs of an institution's pages. @var array $institution @var array $membership @var string $tab @var array<string,?string>|null $crumb deeper pages */
use App\Services\InstitutionAccess;
use App\Services\Institutions;
$base = '/institutions/' . $institution['uuid'];
$verified = $institution['status'] === 'verified';
$tone = ['pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger', 'suspended' => 'danger'];
$tabs = array_filter([
    'overview' => [$base, 'Overview', 'grid-1x2', true],
    'members' => [$base . '/members', 'People', 'people', $verified && InstitutionAccess::can($membership, 'members.view')],
    'students' => [$base . '/students', 'Students', 'mortarboard', $verified && (InstitutionAccess::can($membership, 'students.manage') || InstitutionAccess::can($membership, 'classes.view'))],
    'classes' => [$base . '/classes', 'Classes', 'collection', $verified && (InstitutionAccess::can($membership, 'classes.view') || in_array($membership['role'], \App\Services\InstitutionStructure::TEACHING_ROLES, true))],
    'banks' => [$base . '/banks', 'Question banks', 'database', $verified && array_filter(['question_banks.view', 'question_banks.create', 'question_banks.edit', 'question_banks.publish'],
        static fn(string $p): bool => InstitutionAccess::can($membership, $p)) !== []],
    'assessments' => [$base . '/assessments', 'Assessments', 'clipboard-check', $verified && array_filter(['assessments.view', 'assessments.create', 'assessments.assign', 'assessments.schedule'],
        static fn(string $p): bool => InstitutionAccess::can($membership, $p)) !== []],
    'structure' => [$base . '/structure', 'Programmes', 'diagram-3', $verified && InstitutionAccess::can($membership, 'departments.manage')],
    'profile' => [$base . '/profile', 'Profile', 'building-gear', InstitutionAccess::can($membership, 'institution.manage')],
    'activity' => [$base . '/activity', 'Activity', 'activity', $verified && InstitutionAccess::can($membership, 'activity.view')],
], static fn(array $t): bool => $t[3]);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/institutions')) ?>">Institutions</a></li>
    <?php if (isset($crumb)): ?>
        <li class="breadcrumb-item"><a href="<?= e(url($base)) ?>"><?= e($institution['short_name'] ?: $institution['name']) ?></a></li>
        <?php foreach ($crumb as $label => $href): ?>
            <?php if ($href !== null): ?><li class="breadcrumb-item"><a href="<?= e(url($href)) ?>"><?= e($label) ?></a></li><?php else: ?><li class="breadcrumb-item active" aria-current="page"><?= e($label) ?></li><?php endif; ?>
        <?php endforeach; ?>
    <?php else: ?>
    <li class="breadcrumb-item active" aria-current="page"><?= e($institution['short_name'] ?: $institution['name']) ?></li>
    <?php endif; ?>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e($institution['name']) ?></h1>
        <p class="page-subtitle"><span class="chip-group"><?= chip(Institutions::STATUSES[$institution['status']] ?? $institution['status'], $tone[$institution['status']] ?? 'neutral', $verified ? 'patch-check' : null) ?>
            <?= chip('You: ' . InstitutionAccess::roleName((string) $membership['role']), 'neutral', 'person') ?></span>
            <?= e(Institutions::TYPES[$institution['type']] ?? $institution['type']) ?> · <?= e($institution['district']) ?></p>
    </div>
</header>
<?php if (count($tabs) > 1): ?>
<nav class="segmented institution-tabs" aria-label="Institution pages">
    <?php foreach ($tabs as $key => [$href, $label, $iconName]): ?>
        <a class="<?= $tab === $key ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= icon($iconName) ?><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
