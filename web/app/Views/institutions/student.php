<?php /** @var array $institution @var array $membership @var string $tab @var array $student @var list<array> $history @var list<array> $programmes @var bool $manage @var list<array> $results */
use App\Repositories\StructureRepository;
use App\Services\Access;
use App\Services\InstitutionStructure;
$name = Access::displayName($student);
$active = $student['status'] === 'active';
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Students' => '/institutions/' . $institution['uuid'] . '/students', $name => null]]) ?>
<?php if (!$active): ?>
    <div class="alert alert-info" role="status"><?= icon('info-circle') ?><div class="alert-body"><?= e($name) ?> no longer belongs to the institution. Their record and class history are kept.</div></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('person-vcard') ?><?= e($name) ?></span></div>
            <div class="card-body">
                <p class="small text-muted"><?= e($student['username']) ?><?= $manage && $student['email'] !== null ? ' · ' . e($student['email']) : '' ?> · joined <?= e(fmt_time((string) $student['joined_at'])) ?>. The name and email address are the student's own, kept on their account.</p>
                <?php if ($manage && $active): ?>
                <form method="post" action="<?= e(url('/institutions/' . $institution['uuid'] . '/students/' . $student['uuid'])) ?>" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="student_number">Student number</label>
                        <input class="form-control mono" id="student_number" name="student_number" value="<?= e((string) ($student['student_number'] ?? '')) ?>" maxlength="40"></div>
                    <div class="mb-3"><label class="form-label" for="programme">Programme</label>
                        <select class="form-select" id="programme" name="programme"><option value="">None</option>
                            <?php foreach ($programmes as $p): ?><option value="<?= e($p['uuid']) ?>" <?= ($student['programme_uuid'] ?? '') === $p['uuid'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6"><label class="form-label" for="status">Standing</label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach (StructureRepository::STUDENT_STATUSES as $k => $v): ?><option value="<?= e($k) ?>" <?= ($student['student_status'] ?? 'active') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-sm-6"><label class="form-label" for="enrolled_on">Enrolled on</label>
                            <input class="form-control" type="date" id="enrolled_on" name="enrolled_on" value="<?= e((string) ($student['enrolled_on'] ?? '')) ?>"></div>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save the record</button>
                </form>
                <?php else: ?>
                <dl class="row small mb-0">
                    <dt class="col-5">Student number</dt><dd class="col-7 mono"><?= e((string) ($student['student_number'] ?? 'none')) ?></dd>
                    <dt class="col-5">Programme</dt><dd class="col-7"><?= e((string) ($student['programme_name'] ?? 'none')) ?></dd>
                    <dt class="col-5">Standing</dt><dd class="col-7"><?= e(StructureRepository::STUDENT_STATUSES[$student['student_status'] ?? 'active'] ?? '') ?></dd>
                    <dt class="col-5">Enrolled on</dt><dd class="col-7"><?= e((string) ($student['enrolled_on'] ?? 'not given')) ?></dd>
                </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('clock-history') ?>Classes, now and before</span></div>
            <?php if ($history === []): ?>
                <div class="card-body"><?= empty_state('collection', 'Not in a class yet', '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($history as $h): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><a href="<?= e(url('/institutions/' . $institution['uuid'] . '/classes/' . $h['class_uuid'])) ?>"><?= e(InstitutionStructure::label($h['class_name'], $h['intake'])) ?></a>
                            <?= $h['status'] === 'active' ? chip('Current', 'success') : chip($h['status'] === 'moved' ? 'Moved to ' . ($h['moved_to_name'] ?? 'another class') : 'Left', 'neutral') ?></div>
                        <div class="row-meta"><?= e(fmt_time((string) $h['enrolled_at'])) ?><?= $h['ended_at'] !== null ? ' to ' . e(fmt_time((string) $h['ended_at'])) : '' ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php if ($results !== []): ?>
        <div class="card mt-4">
            <div class="card-header"><span class="card-title"><?= icon('graph-up') ?>Results</span></div>
            <ul class="list-rows">
                <?php foreach ($results as $r): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title fw-normal"><a href="<?= e(url('/institutions/' . $institution['uuid'] . '/assessments/' . $r['assessment_uuid'] . '/results/' . $r['uuid'])) ?>"><?= e($r['title']) ?></a></div>
                        <div class="row-meta">Attempt <?= (int) $r['attempt'] ?> · <?= e(fmt_time((string) $r['submitted_at'])) ?></div>
                    </div></div><span class="fw-semibold"><?= e(rtrim(rtrim((string) $r['percent'], '0'), '.')) ?>%</span>
                    <?= $r['passed'] !== null ? ((int) $r['passed'] ? chip('Pass', 'success') : chip('Not passed', 'danger')) : '' ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
