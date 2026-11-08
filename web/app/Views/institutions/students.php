<?php /** @var array $institution @var array $membership @var string $tab @var list<array> $students @var bool $manage @var list<array> $waiting
 *  @var list<array> $requests @var array<string,int> $states */
use App\Repositories\StructureRepository;
use App\Services\Access;
use App\Services\Enrolment;
$base = '/institutions/' . $institution['uuid'] . '/students';
$tone = ['active' => 'success', 'suspended' => 'warning', 'completed' => 'info', 'withdrawn' => 'neutral'];
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<div class="kpi-strip">
    <?php foreach (Enrolment::STATES as $k => $label): if (!$manage && in_array($k, ['invited', 'pending_registration', 'pending_acceptance'], true)) { continue; } ?>
        <div class="kpi" data-tone="<?= e(Enrolment::TONES[$k]) ?>"><span class="kpi-text"><span class="kpi-value d-block"><?= (int) ($states[$k] ?? 0) ?></span><span class="kpi-label d-block"><?= e($label) ?></span></span></div>
    <?php endforeach; ?>
</div>
<?php if ($requests !== []): ?>
<div class="card mb-4">
    <div class="card-header"><span class="card-title"><?= icon('person-plus') ?>Requests to join</span></div>
    <ul class="list-rows">
        <?php foreach ($requests as $r): ?>
            <li class="request-row"><div class="row-main"><span class="avatar"><?= e(initials(Access::displayName($r))) ?></span><div class="row-text">
                <div class="row-title"><?= e(Access::displayName($r)) ?></div>
                <div class="row-meta"><?= e((string) $r['email']) ?> · code <span class="mono"><?= e($r['code']) ?></span><?= $r['label'] !== null ? ' (' . e($r['label']) . ')' : '' ?> · <?= e(fmt_time((string) $r['created_at'])) ?></div>
            </div></div>
            <div class="request-actions"><form method="post" action="<?= e(url($base . '/requests/' . $r['uuid'])) ?>" class="d-flex gap-2" data-once><?= csrf_field() ?>
                <button class="btn btn-sm btn-success" type="submit" name="decision" value="approve"><?= icon('check2') ?>Approve</button>
                <button class="btn btn-sm btn-outline-danger" type="submit" name="decision" value="decline"><?= icon('x') ?>Decline</button></form></div></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php if ($waiting !== []): ?>
<div class="card mb-4">
    <div class="card-header"><span class="card-title"><?= icon('envelope') ?>Waiting to join</span></div>
    <ul class="list-rows">
        <?php foreach ($waiting as $w): ?>
            <li class="request-row"><div class="row-main"><div class="row-text">
                <div class="row-title"><?= e((string) $w['email']) ?> <?= chip(Enrolment::STATES[$w['state']], Enrolment::TONES[$w['state']]) ?><?= $w['student_number'] !== '' ? ' <span class="small mono text-muted">' . e($w['student_number']) . '</span>' : '' ?></div>
                <div class="row-meta">Sent <?= (int) $w['sent_count'] ?> <?= (int) $w['sent_count'] === 1 ? 'time' : 'times' ?>, last <?= e(fmt_time((string) ($w['last_sent_at'] ?? $w['created_at']))) ?> · link works until <?= e(fmt_time((string) $w['expires_at'])) ?></div>
            </div></div>
            <div class="request-actions d-flex gap-2">
                <form method="post" action="<?= e(url($base . '/invitations/' . (int) $w['id'] . '/resend')) ?>" data-once><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" type="submit"><?= icon('arrow-repeat') ?>Resend</button></form>
                <form method="post" action="<?= e(url($base . '/invitations/' . (int) $w['id'] . '/cancel')) ?>" data-once><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit"><?= icon('x') ?>Cancel</button></form>
            </div></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('mortarboard') ?><?= count($students) ?> <?= count($students) === 1 ? 'student' : 'students' ?></span>
        <?php if ($manage): ?><span class="d-flex gap-2"><a class="btn btn-sm btn-outline-primary" href="<?= e(url($base . '/codes')) ?>"><?= icon('key') ?>Enrolment codes</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url($base . '/import')) ?>"><?= icon('filetype-csv') ?>Import from a spreadsheet</a></span><?php endif; ?></div>
    <?php if ($students === []): ?>
        <div class="card-body"><?= empty_state('mortarboard', 'No students yet', 'Invite students on the People page, or import many at once from a spreadsheet.', $manage ? ['Import students', url($base . '/import')] : null, true) ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="institution-students" data-order='[[0,"asc"]]'>
            <thead><tr><th>Student</th><th>Number</th><th>Programme</th><th>Classes</th><th>Standing</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s): $status = $s['student_status'] ?? 'active'; ?>
                <tr>
                    <td class="stack-title" data-order="<?= e(strtolower(Access::displayName($s))) ?>"><a class="table-row-link" href="<?= e(url($base . '/' . $s['membership_uuid'])) ?>"><?= e(Access::displayName($s)) ?></a>
                        <?php if ($manage && $s['email'] !== null): ?><div class="small text-muted"><?= e($s['email']) ?></div><?php endif; ?></td>
                    <td class="small mono" data-label="Number"><?= $s['student_number'] !== null ? e($s['student_number']) : '<span class="text-muted">none</span>' ?></td>
                    <td class="small" data-label="Programme"><?= $s['programme_name'] !== null ? e($s['programme_name']) : '<span class="text-muted">none</span>' ?></td>
                    <td class="small" data-label="Classes"><?= $s['class_names'] !== null ? e($s['class_names']) : '<span class="text-muted">none</span>' ?></td>
                    <td data-label="Standing"><?= chip(StructureRepository::STUDENT_STATUSES[$status] ?? $status, $tone[$status] ?? 'neutral') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
