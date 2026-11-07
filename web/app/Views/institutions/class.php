<?php
/** @var array $institution @var array $membership @var string $tab @var array $class @var list<array> $students @var bool $withEnded @var bool $manage
 *  @var list<array> $instructors @var list<array> $candidates @var list<array> $teachers @var list<array> $otherClasses @var list<array> $programmes */
use App\Services\Access;
use App\Services\InstitutionAccess;
use App\Services\InstitutionStructure;
$base = '/institutions/' . $institution['uuid'] . '/classes/' . $class['uuid'];
$label = InstitutionStructure::label($class['name'], $class['intake']);
$active = count(array_filter($students, static fn(array $s): bool => $s['enrolment_status'] === 'active'));
$teaching = array_flip(array_map(static fn(array $t): string => (string) $t['uuid'], $instructors));
$studentPages = InstitutionAccess::can($membership, 'students.manage') || InstitutionAccess::can($membership, 'classes.view');
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Classes' => '/institutions/' . $institution['uuid'] . '/classes', $label => null]]) ?>
<?php if ($class['status'] === 'archived'): ?>
    <div class="alert alert-info" role="status"><?= icon('archive') ?><div class="alert-body">Archived. Its students and history are kept; it takes no new students.</div></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('mortarboard') ?><?= e($label) ?>: <?= $active ?> <?= $active === 1 ? 'student' : 'students' ?></span>
                <a class="small" href="<?= e(url($base, $withEnded ? [] : ['ended' => '1'])) ?>"><?= $withEnded ? 'Only current students' : 'Include students who left or moved' ?></a></div>
            <?php if ($students === []): ?>
                <div class="card-body"><?= empty_state('mortarboard', 'No students in this class', $manage ? 'Add the institution\'s students below.' : '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($students as $s): $current = $s['enrolment_status'] === 'active'; ?>
                    <li class="request-row">
                        <div class="row-main">
                            <span class="avatar"><?= e(initials(Access::displayName($s))) ?></span>
                            <div class="row-text">
                                <div class="row-title"><?php if ($studentPages && $s['membership_status'] === 'active'): ?><a href="<?= e(url('/institutions/' . $institution['uuid'] . '/students/' . $s['membership_uuid'])) ?>"><?= e(Access::displayName($s)) ?></a><?php else: ?><?= e(Access::displayName($s)) ?><?php endif; ?>
                                    <?php if (!$current): ?><?= chip($s['enrolment_status'] === 'moved' ? 'Moved to ' . ($s['moved_to_name'] ?? 'another class') : 'Left', 'neutral') ?><?php endif; ?></div>
                                <div class="row-meta"><?= $s['student_number'] !== null ? e($s['student_number']) . ' · ' : '' ?>in the class since <?= e(fmt_time((string) $s['enrolled_at'])) ?><?= !$current ? ' until ' . e(fmt_time((string) $s['ended_at'])) : '' ?></div>
                            </div>
                        </div>
                        <?php if ($manage && $current): ?>
                        <div class="request-actions">
                            <?php if ($otherClasses !== []): ?><button class="btn btn-sm btn-outline-secondary" type="button" data-toggle-panel="move-<?= (int) $s['enrolment_id'] ?>"><?= icon('arrow-left-right') ?>Move</button><?php endif; ?>
                            <form method="post" action="<?= e(url($base . '/students/' . (int) $s['enrolment_id'] . '/remove')) ?>" data-confirm="<?= e(Access::displayName($s)) ?> leaves <?= e($label) ?>. Their time in it stays on record." data-confirm-title="Take out of the class?" data-confirm-button="Take out"><?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit"><?= icon('person-dash') ?>Take out</button>
                            </form>
                        </div>
                        <?php if ($otherClasses !== []): ?>
                        <form method="post" action="<?= e(url($base . '/students/' . (int) $s['enrolment_id'] . '/move')) ?>" class="request-answer-body" id="move-<?= (int) $s['enrolment_id'] ?>" hidden data-once><?= csrf_field() ?>
                            <label class="form-label small" for="to-<?= (int) $s['enrolment_id'] ?>">Move to</label>
                            <div class="toolbar"><select class="form-select form-select-sm" id="to-<?= (int) $s['enrolment_id'] ?>" name="to">
                                <?php foreach ($otherClasses as $o): ?><option value="<?= e($o['uuid']) ?>"><?= e(InstitutionStructure::label($o['name'], $o['intake'])) ?></option><?php endforeach; ?>
                            </select><button class="btn btn-sm btn-primary" type="submit"><?= icon('check2') ?>Move</button></div>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php if ($manage && $class['status'] === 'active'): ?>
        <div class="card mt-4">
            <div class="card-header"><span class="card-title"><?= icon('person-plus') ?>Add students</span></div>
            <div class="card-body">
                <?php if ($candidates === []): ?>
                    <p class="mb-0 text-muted"><?= $active === 0 ? 'The institution has no students yet.' : 'Every student of the institution is already in this class.' ?> Invite students on the People page, or import many at once on the Students page.</p>
                <?php else: ?>
                <form method="post" action="<?= e(url($base . '/students')) ?>" data-once><?= csrf_field() ?>
                    <div class="check-list mb-3">
                        <?php foreach ($candidates as $s): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="add-<?= e($s['membership_uuid']) ?>" name="students[]" value="<?= e($s['membership_uuid']) ?>">
                                <label class="form-check-label" for="add-<?= e($s['membership_uuid']) ?>"><?= e(Access::displayName($s)) ?><span class="text-muted"><?= $s['student_number'] !== null ? ' · ' . e($s['student_number']) : '' ?><?= $s['class_names'] !== null ? ' · in ' . e($s['class_names']) : '' ?></span></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= icon('person-plus') ?>Add the ticked students</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('person-badge') ?>Instructors</span></div>
            <?php if ($manage): ?>
            <div class="card-body">
                <form method="post" action="<?= e(url($base . '/instructors')) ?>" data-once><?= csrf_field() ?>
                    <?php if ($teachers === []): ?><p class="text-muted small">Invite an instructor on the People page first.</p><?php endif; ?>
                    <?php foreach ($teachers as $t): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="teach-<?= e($t['uuid']) ?>" name="instructors[]" value="<?= e($t['uuid']) ?>" <?= isset($teaching[$t['uuid']]) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="teach-<?= e($t['uuid']) ?>"><?= e(Access::displayName($t)) ?> <span class="text-muted">· <?= e(InstitutionAccess::roleName((string) $t['role'])) ?></span></label>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($teachers !== []): ?><button class="btn btn-sm btn-primary mt-3" type="submit"><?= icon('check2') ?>Save instructors</button><?php endif; ?>
                </form>
            </div>
            <?php elseif ($instructors === []): ?>
                <div class="card-body text-muted">None assigned yet.</div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($instructors as $t): ?><li class="request-row"><div class="row-main"><span class="avatar"><?= e(initials(Access::displayName($t))) ?></span><div class="row-text"><div class="row-title"><?= e(Access::displayName($t)) ?></div></div></div></li><?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php if ($manage): ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('gear') ?>The class</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base)) ?>" data-once><?= csrf_field() ?>
                    <?= \App\Core\View::current()->partial('institutions/_class_fields', ['c' => $class, 'programmes' => $programmes]) ?>
                    <button class="btn btn-sm btn-primary" type="submit"><?= icon('check2') ?>Save</button>
                </form>
                <hr>
                <form method="post" action="<?= e(url($base . '/archive')) ?>" data-once><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= icon($class['status'] === 'active' ? 'archive' : 'arrow-counterclockwise') ?><?= $class['status'] === 'active' ? 'Archive the class' : 'Restore the class' ?></button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
