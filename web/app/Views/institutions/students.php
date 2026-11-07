<?php /** @var array $institution @var array $membership @var string $tab @var list<array> $students @var bool $manage */
use App\Repositories\StructureRepository;
use App\Services\Access;
$base = '/institutions/' . $institution['uuid'] . '/students';
$tone = ['active' => 'success', 'suspended' => 'warning', 'completed' => 'info', 'withdrawn' => 'neutral'];
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('mortarboard') ?><?= count($students) ?> <?= count($students) === 1 ? 'student' : 'students' ?></span>
        <?php if ($manage): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url($base . '/import')) ?>"><?= icon('filetype-csv') ?>Import from a spreadsheet</a><?php endif; ?></div>
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
