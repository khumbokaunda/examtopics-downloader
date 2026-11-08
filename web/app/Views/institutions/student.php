<?php /** @var array $institution @var array $membership @var string $tab @var array $student @var list<array> $history @var list<array> $programmes @var bool $manage @var list<array> $results
 *  @var array $record @var list<array> $transcripts @var bool $canIssue @var list<array> $modules */
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
<?php $sbase = '/institutions/' . $institution['uuid'] . '/students/' . $student['uuid']; ?>
<div class="row g-4 mt-0">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('journal-bookmark') ?>Academic record</span></div>
            <?php if ($record['programmes'] === []): ?>
                <div class="card-body"><?= empty_state('journal', 'Not on a programme', 'Give the student a programme in their record to follow their modules.', null, true) ?></div>
            <?php endif; ?>
            <?php foreach ($record['programmes'] as $p): ?>
                <div class="card-body border-bottom">
                    <div class="d-flex flex-wrap justify-content-between gap-2"><div><strong><?= e($p['name']) ?></strong> <?= chip(\App\Services\AcademicRecords::OUTCOMES[$p['outcome']] ?? $p['outcome'], $p['outcome'] === 'completed' ? 'success' : ($p['outcome'] === 'active' ? 'primary' : 'neutral')) ?>
                        <div class="small text-muted"><?= e((string) $p['started_on']) ?><?= $p['ended_on'] !== null ? ' to ' . e((string) $p['ended_on']) : ', ongoing' ?></div></div>
                        <?php if ($manage && $active && $p['outcome'] === 'active'): ?>
                        <form method="post" action="<?= e(url($sbase . '/programme/complete')) ?>" class="d-flex gap-2 align-items-center" data-once><?= csrf_field() ?>
                            <?php if (!$p['eligible']): ?><div class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="override" value="1" id="override"><label class="form-check-label" for="override">Not every required module is done: complete anyway</label></div><?php endif; ?>
                            <button class="btn btn-sm <?= $p['eligible'] ? 'btn-success' : 'btn-outline-secondary' ?>" type="submit"><?= icon('mortarboard') ?>Mark completed</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($p['modules'] !== []): ?>
                    <ul class="list-unstyled small mt-2 mb-0">
                        <?php foreach ($p['modules'] as $mod): ?>
                            <li><?= $mod['completed_at'] !== null ? icon('check2-circle') : icon('circle') ?> <?= e($mod['name']) ?><?= $mod['required'] ? '' : ' <span class="text-muted">(optional)</span>' ?>
                                <span class="text-muted"><?= $mod['completed_at'] !== null ? '· completed ' . e(substr((string) $mod['completed_at'], 0, 10)) . ($mod['percent'] !== null ? ', ' . e(rtrim(rtrim((string) $mod['percent'], '0'), '.')) . '%' : '') . ($mod['method'] === 'manual' ? ', recorded by staff' : '') : '· not completed' ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($manage && $active && $modules !== []): ?>
            <form method="post" action="<?= e(url($sbase . '/modules')) ?>" class="card-body toolbar" data-once><?= csrf_field() ?>
                <label class="visually-hidden" for="rec-module">Module</label>
                <select class="form-select form-select-sm" id="rec-module" name="module" required><option value="">Record a module completed elsewhere…</option>
                    <?php foreach ($modules as $mod): ?><option value="<?= e($mod['uuid']) ?>"><?= e($mod['programme_name'] . ': ' . $mod['name']) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-outline-primary" type="submit">Record</button>
            </form>
            <?php endif; ?>
            <?php if ($record['results'] !== []): ?>
            <div class="card-body pt-0"><p class="small text-muted mb-1">Assessments, final once their results are published:</p>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($record['results'] as $r): ?>
                        <li><?= e($r['title']) ?><?= $r['module'] !== null ? ' <span class="text-muted">(' . e($r['module']) . ')</span>' : '' ?> · <?= $r['percent'] !== null ? e(rtrim(rtrim(number_format($r['percent'], 2), '0'), '.')) . '%' : 'no score' ?>
                            <?= $r['final'] ? chip('Final', 'success') : chip('Provisional', 'warning') ?></li>
                    <?php endforeach; ?>
                </ul></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('file-earmark-text') ?>Transcripts</span></div>
            <?php if ($canIssue && $active): ?>
            <form method="post" action="<?= e(url($sbase . '/transcripts')) ?>" class="card-body" data-once><?= csrf_field() ?>
                <div class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="provisional" value="1" id="provisional"><label class="form-check-label" for="provisional">Include provisional results, marked as provisional</label></div>
                <button class="btn btn-sm btn-primary" type="submit"><?= icon('file-earmark-plus') ?>Issue a transcript</button>
                <p class="form-text mb-0">A transcript keeps the record as it is now. A later change needs a new transcript.</p>
            </form>
            <?php endif; ?>
            <?php if ($transcripts !== []): ?>
            <ul class="list-rows">
                <?php foreach ($transcripts as $t): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><a href="<?= e(url($sbase . '/transcripts/' . $t['uuid'] . '.pdf')) ?>" class="mono"><?= e((string) $t['number']) ?></a> <?= $t['status'] === 'issued' ? chip('Issued', 'success') : chip('Withdrawn', 'neutral') ?><?= (int) $t['includes_provisional'] === 1 ? ' ' . chip('With provisional results', 'warning') : '' ?></div>
                        <div class="row-meta"><?= e(fmt_time((string) $t['issued_at'])) ?> by <?= e(Access::displayName($t)) ?><?= $t['withdrawn_reason'] !== null ? ' · withdrawn: ' . e($t['withdrawn_reason']) : '' ?></div>
                    </div></div>
                    <?php if ($canIssue && $t['status'] === 'issued'): ?>
                    <form method="post" action="<?= e(url($sbase . '/transcripts/' . $t['uuid'] . '/withdraw')) ?>" class="request-actions toolbar" data-once><?= csrf_field() ?>
                        <label class="visually-hidden" for="why-<?= e($t['uuid']) ?>">Why</label><input class="form-control form-control-sm" id="why-<?= e($t['uuid']) ?>" name="reason" maxlength="500" placeholder="Why it is withdrawn" required>
                        <button class="btn btn-sm btn-outline-danger" type="submit">Withdraw</button></form>
                    <?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php elseif (!$canIssue): ?><div class="card-body small text-muted">None issued.</div><?php endif; ?>
        </div>
        <?php if ($manage): ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('shield-check') ?>Under 18</span></div>
            <form method="post" action="<?= e(url($sbase . '/guardian')) ?>" class="card-body" data-once><?= csrf_field() ?>
                <?php if (\App\Services\AcademicRecords::minor($student['date_of_birth'] ?? null)): ?>
                    <div class="alert alert-warning small" role="status"><?= icon('exclamation-triangle') ?><div class="alert-body">Under 18. Your institution is responsible for the guardian's involvement and the lawful basis for processing their information.</div></div>
                <?php endif; ?>
                <div class="mb-2"><label class="form-label small" for="dob">Date of birth</label><input class="form-control form-control-sm" type="date" id="dob" name="date_of_birth" value="<?= e((string) ($student['date_of_birth'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label small" for="gname">Guardian's name</label><input class="form-control form-control-sm" id="gname" name="guardian_name" maxlength="120" value="<?= e((string) ($student['guardian_name'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label small" for="gcontact">Guardian's telephone or email</label><input class="form-control form-control-sm" id="gcontact" name="guardian_contact" maxlength="190" value="<?= e((string) ($student['guardian_contact'] ?? '')) ?>"></div>
                <div class="form-check small mb-2"><input class="form-check-input" type="checkbox" id="consent" name="consent" value="1" <?= ($student['guardian_consent_at'] ?? null) !== null ? 'checked' : '' ?>>
                    <label class="form-check-label" for="consent">The institution has the guardian's agreement<?= ($student['guardian_consent_at'] ?? null) !== null ? ' (recorded ' . e(substr((string) $student['guardian_consent_at'], 0, 10)) . ')' : '' ?></label></div>
                <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
