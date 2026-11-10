<?php
/** The heading and tabs of an institution's pages. @var array $institution @var array $membership @var string $tab @var array<string,?string>|null $crumb deeper pages */
use App\Services\InstitutionAccess;
use App\Services\Institutions;
$base = '/institutions/' . $institution['uuid'];
$verified = $institution['status'] === 'verified';
$tone = \App\Services\Institutions::TONES;
$may = static fn(string ...$any): bool => array_filter($any, static fn(string $p): bool => InstitutionAccess::can($membership, $p)) !== [];
// Every page this member may open, by the group it is shown under. A page appears only when it
// exists and the member may open it; a group with no page is not shown.
$pages = [
    'overview' => ['overview', $base, 'Overview', 'grid-1x2', true],
    'courses' => [$membership['role'] === InstitutionAccess::STUDENT ? 'learning' : 'teaching', $base . '/courses', 'Courses', 'journal-text',
        $verified && ($may('classes.view') || in_array($membership['role'], \App\Services\InstitutionStructure::TEACHING_ROLES, true) || $membership['role'] === InstitutionAccess::STUDENT)],
    'classes' => ['teaching', $base . '/classes', 'Classes', 'collection', $verified && ($may('classes.view') || in_array($membership['role'], \App\Services\InstitutionStructure::TEACHING_ROLES, true))],
    'banks' => ['teaching', $base . '/banks', 'Question banks', 'database', $verified && $may('question_banks.view', 'question_banks.create', 'question_banks.edit', 'question_banks.publish')],
    'assessments' => ['teaching', $base . '/assessments', 'Assessments', 'clipboard-check', $verified && $may('assessments.view', 'assessments.create', 'assessments.assign', 'assessments.schedule')],
    'members' => ['people', $base . '/members', 'Staff and members', 'people', $verified && $may('members.view')],
    'students' => ['people', $base . '/students', 'Students', 'mortarboard', $verified && $may('students.manage', 'classes.view')],
    'structure' => ['academic', $base . '/structure', 'Programmes', 'diagram-3', $verified && $may('departments.manage')],
    'credentials' => ['credentials', $base . '/credentials', 'Credentials', 'award', $verified && $may('credentials.issue', 'credentials.manage')],
    'profile' => ['admin', $base . '/profile', 'Profile', 'building-gear', $may('institution.manage')],
    'application' => ['admin', $base . '/application', 'Application', 'file-earmark-check', $may('institution.manage')],
    'activity' => ['admin', $base . '/activity', 'Activity', 'activity', $verified && $may('activity.view')],
];
$pages = array_filter($pages, static fn(array $p): bool => $p[4]);
$groupNames = ['overview' => ['Overview', 'grid-1x2'], 'learning' => ['Courses', 'journal-text'], 'teaching' => ['Teaching', 'easel'], 'people' => ['People', 'people'], 'academic' => ['Academic', 'diagram-3'],
    'credentials' => ['Credentials', 'award'], 'admin' => ['Administration', 'gear']];
$groups = [];
foreach ($pages as $key => $p) {
    $groups[$p[0]][$key] = $p;
}
$current = $pages[$tab][0] ?? 'overview';
// Until it is approved an institution has only its overview, profile and application: shown flat.
$flat = !$verified;
$others = array_values(array_filter(\App\Core\View::current()->myInstitutions(), static fn(array $o): bool => $o['uuid'] !== $institution['uuid']));
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
        <div class="title-row"><h1 class="page-title"><?= e($institution['name']) ?></h1>
            <?php if ($others !== []): ?>
            <details class="institution-switch">
                <summary class="btn btn-sm btn-outline-secondary" aria-label="Switch institution"><?= icon('arrow-left-right') ?>Switch</summary>
                <div class="institution-switch-menu">
                    <?php foreach ($others as $o): ?><a class="menu-item" href="<?= e(url('/institutions/' . $o['uuid'])) ?>"><?= icon('building') ?><?= e($o['name']) ?></a><?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?></div>
        <p class="page-subtitle"><span class="chip-group"><?= chip(Institutions::STATUSES[$institution['status']] ?? $institution['status'], $tone[$institution['status']] ?? 'neutral', $verified ? 'patch-check' : null) ?>
            <?php if (($institution['registration_status'] ?? '') === 'verified'): ?><?= chip('Registration verified', 'success') ?><?php endif; ?>
            <?php if (($institution['accreditation_status'] ?? '') === 'verified'): ?><?= chip('Accreditation verified', 'success') ?><?php endif; ?>
            <?= chip('You: ' . InstitutionAccess::roleName((string) $membership['role']), 'neutral', 'person') ?></span>
            <?= e(Institutions::TYPES[$institution['type']] ?? $institution['type']) ?> · <?= e($institution['district']) ?></p>
    </div>
</header>
<?php if ($flat && count($pages) > 1): ?>
<nav class="segmented institution-tabs" aria-label="Institution pages">
    <?php foreach ($pages as $key => [, $href, $label, $iconName]): ?>
        <a class="<?= $tab === $key ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= icon($iconName) ?><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php elseif (count($pages) > 1): ?>
<nav class="segmented institution-tabs<?= count($groups[$current] ?? []) > 1 ? ' has-pages' : '' ?>" aria-label="Institution sections">
    <?php foreach ($groups as $g => $in): [$gLabel, $gIcon] = $groupNames[$g]; $first = reset($in); ?>
        <a class="<?= $current === $g ? 'is-active' : '' ?>" href="<?= e(url($first[1])) ?>"<?= $current === $g && count($in) === 1 ? ' aria-current="page"' : '' ?>><?= icon($gIcon) ?><?= e($gLabel) ?></a>
    <?php endforeach; ?>
</nav>
<?php if (count($groups[$current] ?? []) > 1): ?>
<nav class="institution-pages" aria-label="<?= e($groupNames[$current][0]) ?> pages">
    <?php foreach ($groups[$current] as $key => [, $href, $label, $iconName]): ?>
        <a class="<?= $tab === $key ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= icon($iconName) ?><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
