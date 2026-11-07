<?php /** @var array $institution @var array $membership @var string $tab @var list<array> $classes @var bool $archived @var bool $all @var bool $manage @var list<array> $programmes */
use App\Services\InstitutionStructure;
$base = '/institutions/' . $institution['uuid'] . '/classes';
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<div class="row g-4">
    <div class="<?= $manage ? 'col-xl-8' : 'col-12' ?>">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('collection') ?><?= $archived ? 'Archived classes' : ($all ? 'Classes' : 'Classes you teach') ?></span>
                <?php if ($all): ?><a class="small" href="<?= e(url($base, $archived ? [] : ['status' => 'archived'])) ?>"><?= $archived ? 'Open classes' : 'Archived classes' ?></a><?php endif; ?></div>
            <?php if ($classes === []): ?>
                <div class="card-body"><?= empty_state('collection', $archived ? 'No archived classes' : 'No classes yet', $all ? ($manage ? 'Create the first one here.' : '') : 'An administrator assigns you to the classes you teach.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack" data-table="institution-classes" data-order='[[0,"asc"]]'>
                    <thead><tr><th>Class</th><th>Programme</th><th data-type="num">Students</th><th>Instructors</th></tr></thead>
                    <tbody>
                    <?php foreach ($classes as $c): ?>
                        <tr>
                            <td class="stack-title" data-order="<?= e(strtolower($c['name'] . ' ' . $c['intake'])) ?>"><a class="table-row-link" href="<?= e(url($base . '/' . $c['uuid'])) ?>"><?= e($c['name']) ?></a>
                                <?php if ($c['intake'] !== ''): ?><div class="small text-muted"><?= e($c['intake']) ?></div><?php endif; ?></td>
                            <td class="small" data-label="Programme"><?= $c['programme_name'] !== null ? e($c['programme_name']) : '<span class="text-muted">none</span>' ?></td>
                            <td class="small" data-label="Students" data-order="<?= (int) $c['student_count'] ?>"><?= (int) $c['student_count'] ?></td>
                            <td class="small" data-label="Instructors"><?= $c['instructor_names'] !== null ? e($c['instructor_names']) : '<span class="text-muted">none yet</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($manage): ?>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('plus-circle') ?>New class</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base)) ?>" data-once><?= csrf_field() ?>
                    <?= \App\Core\View::current()->partial('institutions/_class_fields', ['c' => null, 'programmes' => $programmes]) ?>
                    <button class="btn btn-primary" type="submit"><?= icon('plus') ?>Create the class</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
