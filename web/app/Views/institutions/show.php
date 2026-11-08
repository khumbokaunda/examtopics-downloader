<?php
/** @var array $institution @var array $membership @var string $tab @var array<string,int> $roleCounts @var int|null $invitations
 *  @var list<array>|null $setup @var list<array>|null $activity @var list<array> $reviews @var array<string,int> $counts @var list<array> $myClasses @var list<array> $teaching @var array|null $overview */
use App\Services\InstitutionAccess;
use App\Services\Institutions;
$base = '/institutions/' . $institution['uuid'];
$status = $institution['status'];
$owner = $membership['role'] === InstitutionAccess::OWNER;
$people = array_sum($roleCounts);
$staff = $people - ($roleCounts['student'] ?? 0);
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>

<?php $manage = InstitutionAccess::can($membership, 'institution.manage'); ?>
<?php if (in_array($status, ['draft', 'pending', 'under_review', 'info_requested', 'rejected'], true)): ?>
    <div class="alert <?= in_array($status, ['info_requested', 'rejected'], true) ? 'alert-warning' : 'alert-info' ?> d-flex flex-wrap align-items-center gap-2" role="status"><?= icon('file-earmark-check') ?>
        <div class="alert-body flex-grow-1"><?= e(match ($status) {
            'draft' => 'The application has not been sent yet. Add its documents and send it for review.',
            'pending', 'under_review' => 'The application is with the platform\'s reviewers. Until it is approved, people cannot be invited.',
            'info_requested' => 'The reviewer asked for more information: ' . (string) $institution['status_note'],
            default => 'Not approved: ' . (string) $institution['status_note'] . ' Correct the application and send it again.',
        }) ?></div>
        <?php if ($manage): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/application')) ?>"><?= icon('arrow-right') ?>Open the application</a><?php endif; ?>
    </div>
<?php elseif ($status === 'suspended'): ?>
    <div class="alert alert-danger" role="status"><?= icon('slash-circle') ?><div class="alert-body">Suspended by the platform<?= $institution['status_note'] ? ': ' . e($institution['status_note']) : '' ?>. Its members cannot open it until it is reinstated. Its records and certificates are kept.</div></div>
<?php endif; ?>
<?php if ($setup !== null && array_filter($setup, static fn(array $s): bool => !$s['done']) !== []): ?>
    <div class="card mb-4">
        <div class="card-header"><span class="card-title"><?= icon('list-check') ?>Getting started</span><span class="small text-muted"><?= count(array_filter($setup, static fn(array $s): bool => $s['done'])) ?> of <?= count($setup) ?> done</span></div>
        <ul class="list-rows">
            <?php foreach ($setup as $step): ?>
                <li class="request-row"><div class="row-main"><span class="icon-bubble" data-tone="<?= $step['done'] ? 'success' : 'neutral' ?>"><?= icon($step['done'] ? 'check2' : 'circle') ?></span><div class="row-text">
                    <div class="row-title fw-normal"><?= $step['done'] ? e($step['label']) : '<a href="' . e(url($step['href'])) . '">' . e($step['label']) . '</a>' ?></div>
                </div></div></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="kpi-strip">
    <?php foreach ([
        ['People', number_format($people), 'people', 'primary'],
        ['Staff', number_format($staff), 'person-badge', 'info'],
        ['Students', number_format($roleCounts['student'] ?? 0), 'mortarboard', 'success'],
        ['Classes', number_format($counts['classes']), 'collection', 'neutral'],
        ...($overview !== null ? [['Sittings handed in', number_format($overview['sittings']), 'check2-square', 'primary'],
            ['Average score', $overview['average'] !== null ? $overview['average'] . '%' : 'n/a', 'graph-up', 'info'],
            ['Pass rate', $overview['pass_rate'] !== null ? $overview['pass_rate'] . '%' : 'n/a', 'patch-check', 'success']] : []),
        ...($invitations !== null ? [['Invitations waiting', number_format($invitations), 'envelope', $invitations > 0 ? 'warning' : 'neutral']] : []),
    ] as [$label, $value, $iconName, $tone]): ?>
        <div class="kpi" data-tone="<?= e($tone) ?>">
            <span class="kpi-icon"><?= icon($iconName) ?></span>
            <span class="kpi-text"><span class="kpi-value d-block"><?= e($value) ?></span><span class="kpi-label d-block"><?= e($label) ?></span></span>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('people') ?>People by role</span>
                <?php if ($status === 'verified' && InstitutionAccess::can($membership, 'members.view')): ?><a class="small" href="<?= e(url($base . '/members')) ?>">See everyone</a><?php endif; ?></div>
            <ul class="list-rows">
                <?php foreach (InstitutionAccess::ROLES as $role => $r): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><?= e($r['name']) ?> <span class="text-muted fw-normal">· <?= (int) ($roleCounts[$role] ?? 0) ?></span></div>
                        <div class="row-meta"><?= e($r['description']) ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('person') ?>Your membership</span></div>
            <div class="card-body">
                <p class="mb-2"><?= e(InstitutionAccess::roleName((string) $membership['role'])) ?>, since <?= e(fmt_time((string) $membership['joined_at'])) ?>.</p>
                <?php $perms = InstitutionAccess::permissions($membership); ?>
                <p class="small text-muted mb-3"><?= $perms === [] ? 'Your role does not include managing the institution.' : 'You can: ' . e(implode(' ', array_map(static fn(string $p): string => InstitutionAccess::PERMISSIONS[$p], $perms))) ?></p>
                <form method="post" action="<?= e(url($base . '/leave')) ?>" data-confirm="You lose access to <?= e($institution['name']) ?>. What you did there stays in its history." data-confirm-title="Leave this institution?" data-confirm-button="Leave"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-danger" type="submit"><?= icon('box-arrow-left') ?>Leave <?= e($institution['short_name'] ?: $institution['name']) ?></button>
                </form>
            </div>
        </div>
        <?php if ($myClasses !== [] || $teaching !== []): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('collection') ?>Your classes</span></div>
            <ul class="list-rows">
                <?php foreach ($teaching as $c): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><a href="<?= e(url($base . '/classes/' . $c['uuid'])) ?>"><?= e(\App\Services\InstitutionStructure::label($c['name'], $c['intake'])) ?></a> <?= chip('You teach it', 'primary') ?></div>
                        <div class="row-meta"><?= (int) $c['student_count'] ?> students</div>
                    </div></div></li>
                <?php endforeach; ?>
                <?php foreach ($myClasses as $c): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><?= e(\App\Services\InstitutionStructure::label($c['class_name'], $c['intake'])) ?></div>
                        <div class="row-meta">Since <?= e(fmt_time((string) $c['enrolled_at'])) ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php if ($reviews !== []): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('patch-question') ?>Verification</span></div>
            <ul class="list-rows">
                <?php foreach ($reviews as $r): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><?= e(Institutions::STATUSES[$r['to_status']] ?? $r['to_status']) ?></div>
                        <div class="row-meta"><?= e(fmt_time((string) $r['created_at'])) ?><?= $r['note'] !== null ? ' · ' . e($r['note']) : '' ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php if ($activity !== null): ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('activity') ?>Recent activity</span><a class="small" href="<?= e(url($base . '/activity')) ?>">See all</a></div>
            <?php if ($activity === []): ?>
                <div class="card-body"><?= empty_state('activity', 'Nothing yet', '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($activity as $a): ?>
                    <li class="request-row"><div class="row-main"><span class="icon-bubble" data-tone="<?= e($a['tone']) ?>"><?= icon($a['icon']) ?></span><div class="row-text">
                        <div class="row-title fw-normal"><?= e($a['text']) ?></div><div class="row-meta"><?= e(fmt_ago((string) $a['created_at'])) ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
