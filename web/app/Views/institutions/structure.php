<?php /** @var array $institution @var array $membership @var string $tab @var list<array> $departments @var list<array> $programmes */
$base = '/institutions/' . $institution['uuid'] . '/structure';
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('journal-text') ?>Programmes</span></div>
            <?php if ($programmes === []): ?>
                <div class="card-body"><?= empty_state('journal-text', 'No programmes yet', 'A programme is a course of study, such as a diploma. Classes and students can belong to one.', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($programmes as $p): ?>
                    <li class="request-row">
                        <div class="row-main"><div class="row-text">
                            <div class="row-title"><a href="<?= e(url($base . '/programmes/' . $p['uuid'])) ?>"><?= e($p['name']) ?></a><?= $p['code'] !== null ? ' <span class="text-muted fw-normal mono">' . e($p['code']) . '</span>' : '' ?></div>
                            <div class="row-meta"><?= e($p['department_name'] ?? 'No department') ?> · <?= (int) $p['class_count'] ?> classes · <?= (int) $p['student_count'] ?> students</div>
                        </div></div>
                        <div class="request-actions">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle-panel="prog-<?= e($p['uuid']) ?>"><?= icon('pencil') ?>Edit</button>
                            <form method="post" action="<?= e(url($base . '/programmes/' . $p['uuid'] . '/delete')) ?>" data-confirm="Remove the programme <?= e($p['name']) ?>?" data-confirm-button="Remove"><?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Remove <?= e($p['name']) ?>"><?= icon('trash3') ?></button>
                            </form>
                        </div>
                        <form method="post" action="<?= e(url($base . '/programmes/' . $p['uuid'])) ?>" class="request-answer-body" id="prog-<?= e($p['uuid']) ?>" hidden data-once><?= csrf_field() ?>
                            <?= \App\Core\View::current()->partial('institutions/_programme_fields', ['p' => $p, 'departments' => $departments, 'id' => $p['uuid']]) ?>
                            <button class="btn btn-sm btn-primary" type="submit"><?= icon('check2') ?>Save</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('plus-circle') ?>Add a programme</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base . '/programmes')) ?>" data-once><?= csrf_field() ?>
                    <?= \App\Core\View::current()->partial('institutions/_programme_fields', ['p' => null, 'departments' => $departments, 'id' => 'new']) ?>
                    <button class="btn btn-primary" type="submit"><?= icon('plus') ?>Add the programme</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('diagram-3') ?>Departments</span></div>
            <?php if ($departments !== []): ?>
            <ul class="list-rows">
                <?php foreach ($departments as $d): ?>
                    <li class="request-row">
                        <div class="row-main"><div class="row-text">
                            <div class="row-title"><?= e($d['name']) ?></div><div class="row-meta"><?= (int) $d['programme_count'] ?> programmes</div>
                        </div></div>
                        <div class="request-actions">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle-panel="dept-<?= e($d['uuid']) ?>"><?= icon('pencil') ?>Rename</button>
                            <form method="post" action="<?= e(url($base . '/departments/' . $d['uuid'] . '/delete')) ?>" data-confirm="Remove the department <?= e($d['name']) ?>?" data-confirm-button="Remove"><?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Remove <?= e($d['name']) ?>"><?= icon('trash3') ?></button>
                            </form>
                        </div>
                        <form method="post" action="<?= e(url($base . '/departments/' . $d['uuid'])) ?>" class="request-answer-body" id="dept-<?= e($d['uuid']) ?>" hidden data-once><?= csrf_field() ?>
                            <label class="form-label small" for="dname-<?= e($d['uuid']) ?>">Name</label>
                            <input class="form-control form-control-sm mb-2" id="dname-<?= e($d['uuid']) ?>" name="name" value="<?= e($d['name']) ?>" maxlength="120" required>
                            <button class="btn btn-sm btn-primary" type="submit"><?= icon('check2') ?>Save</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <div class="card-body">
                <form method="post" action="<?= e(url($base . '/departments')) ?>" class="toolbar" data-once><?= csrf_field() ?>
                    <label class="visually-hidden" for="dept-new">New department</label>
                    <input class="form-control flex-grow-1 w-auto" id="dept-new" name="name" maxlength="120" placeholder="New department, such as ICT" required>
                    <button class="btn btn-outline-primary" type="submit"><?= icon('plus') ?>Add</button>
                </form>
            </div>
        </div>
    </div>
</div>
