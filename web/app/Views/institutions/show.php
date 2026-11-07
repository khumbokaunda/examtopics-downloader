<?php
/** @var array $institution @var array $membership @var string $tab @var array<string,int> $roleCounts @var int|null $invitations
 *  @var list<array>|null $activity @var list<array> $reviews @var array<string,int> $counts @var list<array> $myClasses @var list<array> $teaching */
use App\Services\InstitutionAccess;
use App\Services\Institutions;
$base = '/institutions/' . $institution['uuid'];
$status = $institution['status'];
$owner = $membership['role'] === InstitutionAccess::OWNER;
$people = array_sum($roleCounts);
$staff = $people - ($roleCounts['student'] ?? 0);
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>

<?php if ($status === 'pending'): ?>
    <div class="alert alert-warning" role="status"><?= icon('hourglass-split') ?><div class="alert-body">Waiting for a platform reviewer. Until it is verified you can correct the profile, and people cannot be invited yet. You are told in your inbox when it is decided.</div></div>
<?php elseif ($status === 'rejected'): ?>
    <div class="alert alert-danger d-flex flex-wrap align-items-center gap-2" role="status"><?= icon('x-octagon') ?><div class="alert-body flex-grow-1">Not verified<?= $institution['status_note'] ? ': ' . e($institution['status_note']) : '' ?>. Correct the profile, then send it again.</div>
        <?php if (InstitutionAccess::can($membership, 'institution.manage')): ?>
        <form method="post" action="<?= e(url($base . '/resubmit')) ?>" data-once><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" type="submit"><?= icon('send') ?>Send for verification again</button></form>
        <?php endif; ?>
    </div>
<?php elseif ($status === 'suspended'): ?>
    <div class="alert alert-danger" role="status"><?= icon('slash-circle') ?><div class="alert-body">Suspended by the platform<?= $institution['status_note'] ? ': ' . e($institution['status_note']) : '' ?>. Its members cannot open it until it is reinstated.</div></div>
<?php endif; ?>

<div class="kpi-strip">
    <?php foreach ([
        ['People', number_format($people), 'people', 'primary'],
        ['Staff', number_format($staff), 'person-badge', 'info'],
        ['Students', number_format($roleCounts['student'] ?? 0), 'mortarboard', 'success'],
        ['Classes', number_format($counts['classes']), 'collection', 'neutral'],
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
