<?php /** @var array $institution @var array $membership @var string $tab @var list<array> $courses @var bool $archived @var bool $create
 *  @var list<array> $classes @var list<array> $modules @var list<array> $teachers */
use App\Services\InstitutionStructure;
$base = '/institutions/' . $institution['uuid'] . '/courses';
$day = static fn(?string $d): string => $d !== null ? (new DateTimeImmutable($d))->format('j M Y') : '';
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<div class="row g-4">
    <div class="<?= $create ? 'col-xl-8' : 'col-12' ?>">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('journal-text') ?><?= $archived ? 'Archived courses' : 'Courses' ?></span>
                <?php if ($create): ?><a class="small" href="<?= e(url($base, $archived ? [] : ['status' => 'archived'])) ?>"><?= $archived ? 'Active courses' : 'Archived courses' ?></a><?php endif; ?></div>
            <?php if ($courses === []): ?>
                <div class="card-body"><?= empty_state('journal-text', $archived ? 'No archived courses' : 'No courses yet', $create ? 'Start one for a class here.' : 'Your courses show here.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack" data-table="institution-courses" data-order='[[0,"asc"]]'>
                    <thead><tr><th>Course</th><th>Class</th><th>Dates</th><th data-type="num">Learners</th><th data-type="num">Assessments</th></tr></thead>
                    <tbody>
                    <?php foreach ($courses as $c): ?>
                        <tr>
                            <td class="stack-title" data-order="<?= e(strtolower($c['title'])) ?>"><a class="table-row-link" href="<?= e(url($base . '/' . $c['uuid'])) ?>"><?= e($c['title']) ?></a>
                                <?php if ($c['lead_name'] !== null): ?><div class="small text-muted"><?= e($c['lead_name']) ?></div><?php endif; ?></td>
                            <td class="small" data-label="Class"><?= e(InstitutionStructure::label((string) $c['class_name'], (string) $c['class_intake'])) ?></td>
                            <td class="small" data-label="Dates" data-order="<?= e((string) $c['starts_on']) ?>"><?= $c['starts_on'] === null && $c['ends_on'] === null ? '<span class="text-muted">not set</span>'
                                : e(trim($day($c['starts_on']) . ' to ' . $day($c['ends_on']), ' to')) ?></td>
                            <td class="small" data-label="Learners" data-order="<?= (int) $c['learner_count'] ?>"><?= (int) $c['learner_count'] ?></td>
                            <td class="small" data-label="Assessments" data-order="<?= (int) $c['assessment_count'] ?>"><?= (int) $c['assessment_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($create): ?>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('plus-circle') ?>New course</span></div>
            <div class="card-body">
                <?php if ($classes === []): ?>
                    <p class="small text-muted mb-0">A course is for a class. <?= \App\Services\InstitutionAccess::can($membership, 'classes.manage') ? 'Create a class first.' : 'You are not an instructor of any class yet.' ?></p>
                <?php else: ?>
                <form method="post" action="<?= e(url($base)) ?>" data-once><?= csrf_field() ?>
                    <?= \App\Core\View::current()->partial('institutions/_course_fields', ['c' => null, 'classes' => $classes, 'modules' => $modules, 'teachers' => $teachers]) ?>
                    <button class="btn btn-primary" type="submit"><?= icon('plus') ?>Create the course</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
