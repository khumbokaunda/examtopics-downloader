<?php
/** The heading and tabs of an institution's pages. @var array $institution @var array $membership @var string $tab */
use App\Services\InstitutionAccess;
use App\Services\Institutions;
$base = '/institutions/' . $institution['uuid'];
$verified = $institution['status'] === 'verified';
$tone = ['pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger', 'suspended' => 'danger'];
$tabs = array_filter([
    'overview' => [$base, 'Overview', 'grid-1x2', true],
    'members' => [$base . '/members', 'People', 'people', $verified && InstitutionAccess::can($membership, 'members.view')],
    'profile' => [$base . '/profile', 'Profile', 'building-gear', InstitutionAccess::can($membership, 'institution.manage')],
    'activity' => [$base . '/activity', 'Activity', 'activity', $verified && InstitutionAccess::can($membership, 'activity.view')],
], static fn(array $t): bool => $t[3]);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/institutions')) ?>">Institutions</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($institution['short_name'] ?: $institution['name']) ?></li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e($institution['name']) ?></h1>
        <p class="page-subtitle"><span class="chip-group"><?= chip(Institutions::STATUSES[$institution['status']] ?? $institution['status'], $tone[$institution['status']] ?? 'neutral', $verified ? 'patch-check' : null) ?>
            <?= chip('You: ' . InstitutionAccess::roleName((string) $membership['role']), 'neutral', 'person') ?></span>
            <?= e(Institutions::TYPES[$institution['type']] ?? $institution['type']) ?> · <?= e($institution['district']) ?></p>
    </div>
    <?php if (count($tabs) > 1): ?>
    <div class="page-actions">
        <nav class="segmented" aria-label="Institution pages">
            <?php foreach ($tabs as $key => [$href, $label, $iconName]): ?>
                <a class="<?= $tab === $key ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= icon($iconName) ?><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <?php endif; ?>
</header>
