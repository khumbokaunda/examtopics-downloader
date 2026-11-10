<?php
/**
 * One course: its notices (overview), its assessments and its participants.
 *
 * @var array $institution @var array $membership @var string $tab @var array $crumb @var array $course @var string $view @var array<string,string> $views
 * @var bool $manage may change the course @var bool $learner one of its learners
 */
use App\Services\Access;
use App\Services\Assessments;
use App\Services\InstitutionStructure;
use App\Services\Sittings;
$base = '/institutions/' . $institution['uuid'] . '/courses/' . $course['uuid'];
$day = static fn(?string $d): string => $d !== null ? (new DateTimeImmutable($d))->format('j M Y') : '';
$dates = $course['starts_on'] === null && $course['ends_on'] === null ? '' : trim($day($course['starts_on']) . ' to ' . $day($course['ends_on']), ' to');
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab, 'crumb' => $crumb]) ?>
<div class="card mb-4"><div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="icon-bubble" data-tone="primary"><?= icon('journal-text') ?></span>
    <div class="flex-grow-1">
        <h2 class="h5 mb-1"><?= e($course['title']) ?> <?= $course['status'] === 'archived' ? chip('Archived', 'neutral') : '' ?></h2>
        <div class="small text-muted"><?= e(InstitutionStructure::label((string) $course['class_name'], (string) $course['class_intake'])) ?>
            <?= $dates !== '' ? ' · ' . e($dates) : '' ?><?= $course['lead_name'] !== null ? ' · ' . e($course['lead_name']) : '' ?>
            <?= $course['module_name'] !== null ? ' · ' . e($course['module_name']) : '' ?></div>
    </div>
    <nav class="segmented" aria-label="Course pages">
        <?php foreach ($views as $key => $label): ?>
            <a class="<?= $view === $key ? 'is-active' : '' ?>" href="<?= e(url($base, $key === 'overview' ? [] : ['tab' => $key])) ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
</div></div>

<?php if ($view === 'overview'): /** @var list<array> $announcements @var list<array> $modules @var list<array> $teachers */ ?>
<div class="row g-4">
    <div class="<?= $manage ? 'col-xl-7' : 'col-12' ?>">
        <?php if ((string) ($course['summary'] ?? '') !== ''): ?><div class="card mb-4"><div class="card-body"><p class="mb-0 text-pre"><?= e((string) $course['summary']) ?></p></div></div><?php endif; ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('megaphone') ?>Notices</span></div>
            <?php if ($announcements === []): ?>
                <div class="card-body"><?= empty_state('megaphone', 'No notices yet', $manage ? 'Post one and the learners are told.' : '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($announcements as $n): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><?= e($n['title']) ?></div>
                        <div class="row-meta"><?= e(fmt_time((string) $n['posted_at'])) ?><?= $n['author'] !== null ? ' · ' . e($n['author']) : '' ?></div>
                        <p class="mb-0 mt-2 text-pre"><?= e($n['body']) ?></p>
                    </div></div>
                    <?php if ($manage): ?><form method="post" action="<?= e(url($base . '/notices/' . $n['uuid'] . '/remove')) ?>" class="request-actions" data-confirm="Learners will no longer see it." data-confirm-title="Remove this notice?" data-confirm-button="Remove" data-confirm-tone="danger"><?= csrf_field() ?>
                        <button class="btn btn-sm btn-link btn-link-danger" type="submit">Remove</button></form><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($manage): ?>
    <div class="col-xl-5">
        <?php if ($course['status'] === 'active'): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('megaphone') ?>Post a notice</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base . '/notices')) ?>" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="notice-title">Title</label><input class="form-control" id="notice-title" name="title" maxlength="160" required></div>
                    <div class="mb-3"><label class="form-label" for="notice-body">Notice</label><textarea class="form-control" id="notice-body" name="body" rows="4" maxlength="5000" required></textarea>
                        <div class="form-text">Sent to the <?= (int) $course['learner_count'] ?> learners' inboxes.</div></div>
                    <button class="btn btn-primary" type="submit"><?= icon('send') ?>Post</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('pencil') ?>Course details</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base)) ?>" data-once><?= csrf_field() ?>
                    <?= \App\Core\View::current()->partial('institutions/_course_fields', ['c' => $course, 'classes' => [], 'modules' => $modules, 'teachers' => $teachers]) ?>
                    <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save</button>
                </form>
                <form method="post" action="<?= e(url($base . '/archive')) ?>" class="mt-3"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= icon($course['status'] === 'active' ? 'archive' : 'arrow-counterclockwise') ?><?= $course['status'] === 'active' ? 'Archive the course' : 'Restore the course' ?></button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($view === 'materials'): /** @var array<string,list<array>> $groups */ ?>
<div class="row g-4">
    <div class="<?= $manage ? 'col-xl-8' : 'col-12' ?>">
        <?php if ($groups === []): ?>
            <div class="card"><div class="card-body"><?= empty_state('folder2-open', 'No materials yet', $manage ? 'Add files and links here.' : '', null, true) ?></div></div>
        <?php endif; ?>
        <?php foreach ($groups as $heading => $mats): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('folder2-open') ?><?= e($heading !== '' ? $heading : 'Materials') ?></span></div>
            <ul class="list-rows">
                <?php foreach ($mats as $mat): ?>
                    <li class="req-row"><div class="row-main"><span class="icon-bubble" data-tone="primary"><?= icon($mat['kind'] === 'link' ? 'link-45deg' : 'file-earmark') ?></span><div class="row-text">
                        <div class="row-title"><?php if ($mat['kind'] === 'link'): ?><a href="<?= e((string) $mat['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($mat['title']) ?></a>
                            <?php else: ?><a href="<?= e(url($base . '/materials/' . $mat['uuid'])) ?>"><?= e($mat['title']) ?></a><?php endif; ?></div>
                        <div class="row-meta"><?= $mat['kind'] === 'link' ? e((string) parse_url((string) $mat['url'], PHP_URL_HOST)) : e((string) $mat['filename']) . ' · ' . e(number_format((int) $mat['size'] / 1024, 0)) . ' KB' ?></div>
                    </div></div>
                    <?php if ($manage): ?><form method="post" action="<?= e(url($base . '/materials/' . $mat['uuid'] . '/remove')) ?>" class="req-actions" data-confirm="Learners will no longer see it." data-confirm-title="Remove it?" data-confirm-button="Remove" data-confirm-tone="danger"><?= csrf_field() ?>
                        <button class="btn btn-sm btn-link btn-link-danger" type="submit">Remove</button></form><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($manage): ?>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('plus-circle') ?>Add a file or link</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base . '/materials')) ?>" enctype="multipart/form-data" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="mat-heading">Heading</label><input class="form-control" id="mat-heading" name="heading" maxlength="120" list="mat-headings" placeholder="Week 1">
                        <datalist id="mat-headings"><?php foreach (array_keys($groups) as $h): if ($h !== '') { ?><option value="<?= e((string) $h) ?>"><?php } endforeach; ?></datalist></div>
                    <div class="mb-3"><label class="form-label" for="mat-title">Title</label><input class="form-control" id="mat-title" name="title" maxlength="160" required></div>
                    <div class="mb-3"><label class="form-label" for="mat-file">File</label><input class="form-control" type="file" id="mat-file" name="file">
                        <div class="form-text">Up to <?= \App\Services\Coursework::MATERIAL_MB ?> MB: PDF, Office files, pictures, text or ZIP.</div></div>
                    <div class="mb-3"><label class="form-label" for="mat-url">Or a link</label><input class="form-control" type="url" id="mat-url" name="url" maxlength="1000" placeholder="https://">
                        <div class="form-text">Videos go here as links.</div></div>
                    <button class="btn btn-primary" type="submit"><?= icon('plus') ?>Add</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($view === 'assignments'): /** @var list<array> $assignments @var array<int,array> $mine @var string $timezone */
    $wTone = ['draft' => 'neutral', 'published' => 'success', 'closed' => 'neutral']; ?>
<div class="row g-4">
    <div class="<?= $manage ? 'col-xl-7' : 'col-12' ?>">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('file-earmark-arrow-up') ?>Assignments</span></div>
            <?php if ($assignments === []): ?>
                <div class="card-body"><?= empty_state('file-earmark-arrow-up', 'No assignments yet', $manage ? 'Set one here.' : '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($assignments as $w): [$sub, $mk] = $mine[(int) $w['id']] ?? [null, null]; ?>
                    <li><div class="row-main"><span class="icon-bubble" data-tone="primary"><?= icon('file-earmark-arrow-up') ?></span><div class="row-text">
                        <div class="row-title"><a href="<?= e(url($base . '/assignments/' . $w['uuid'])) ?>"><?= e($w['title']) ?></a>
                            <?php if ($manage): ?><?= chip(\App\Services\Coursework::STATUSES[$w['status']] ?? $w['status'], $wTone[$w['status']] ?? 'neutral') ?>
                            <?php elseif ($mk !== null && $mk['marks'] !== null): ?><?= chip('Marked: ' . \App\Services\Gradebook::fmt((float) $mk['marks']) . ' of ' . \App\Services\Gradebook::fmt((float) $w['max_marks']), 'primary') ?>
                            <?php elseif ($sub !== null): ?><?= chip((int) $sub['late'] === 1 ? 'Handed in late' : 'Handed in', (int) $sub['late'] === 1 ? 'warning' : 'success') ?>
                            <?php else: ?><?= chip('To do', 'info') ?><?php endif; ?></div>
                        <div class="row-meta"><?= $w['due_at'] !== null ? 'Due ' . e(fmt_time((string) $w['due_at'])) : 'No deadline' ?>
                            <?= $manage ? ' · ' . (int) $w['submitted'] . ' handed in · ' . (int) $w['marked'] . ' marked' : '' ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($manage): ?>
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('plus-circle') ?>New assignment</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base . '/assignments/new')) ?>" data-once><?= csrf_field() ?>
                    <?= \App\Core\View::current()->partial('institutions/_assignment_fields', ['a' => null, 'timezone' => $timezone, 'rubric' => false]) ?>
                    <button class="btn btn-primary" type="submit"><?= icon('plus') ?>Save as a draft</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($view === 'assessments'): /** @var list<array> $assessments @var list<array> $mine @var list<array> $attachable @var bool $canBuild */
    $tone = ['open' => 'success', 'upcoming' => 'info', 'closed' => 'neutral'];
    $label = ['open' => 'Open', 'upcoming' => 'Not open yet', 'closed' => 'Closed'];
    $statusTone = ['draft' => 'neutral', 'published' => 'success', 'closed' => 'neutral']; ?>
<div class="row g-4">
    <div class="<?= $manage ? 'col-xl-8' : 'col-12' ?>">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('clipboard-check') ?>Assessments</span></div>
            <?php if ($learner): ?>
                <?php if ($mine === []): ?>
                    <div class="card-body"><?= empty_state('clipboard-check', 'Nothing set yet', 'Assessments for this course show here.', null, true) ?></div>
                <?php else: ?>
                <ul class="list-rows">
                    <?php foreach ($mine as $a): $w = Sittings::window($a); ?>
                        <li><div class="row-main"><span class="icon-bubble" data-tone="<?= e($tone[$w]) ?>"><?= icon('clipboard-check') ?></span><div class="row-text">
                            <div class="row-title"><a href="<?= e(url('/my/assessments/' . $a['institution_uuid'] . '/' . $a['uuid'])) ?>"><?= e($a['title']) ?></a> <?= chip($label[$w], $tone[$w]) ?></div>
                            <div class="row-meta"><?= (int) $a['duration_minutes'] ?> minutes<?= $w === 'upcoming' ? ' · opens ' . e(fmt_time((string) $a['opens_at'])) : '' ?><?= $a['closes_at'] !== null && $w !== 'closed' ? ' · closes ' . e(fmt_time((string) $a['closes_at'])) : '' ?></div>
                        </div></div></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            <?php elseif ($assessments === []): ?>
                <div class="card-body"><?= empty_state('clipboard-check', 'No assessments in this course', $manage ? 'Add one from the list.' : '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($assessments as $a): ?>
                    <li class="request-row"><div class="row-main"><span class="icon-bubble" data-tone="primary"><?= icon('clipboard-check') ?></span><div class="row-text">
                        <div class="row-title"><a href="<?= e(url('/institutions/' . $institution['uuid'] . '/assessments/' . $a['uuid'])) ?>"><?= e($a['title']) ?></a>
                            <?= chip(Assessments::STATUSES[$a['status']] ?? $a['status'], $statusTone[$a['status']] ?? 'neutral') ?></div>
                        <div class="row-meta"><?= e(Assessments::TYPES[$a['type']] ?? $a['type']) ?> · <?= (int) $a['duration_minutes'] ?> minutes<?= $a['opens_at'] !== null ? ' · opens ' . e(fmt_time((string) $a['opens_at'])) : '' ?><?= $a['closes_at'] !== null ? ' · closes ' . e(fmt_time((string) $a['closes_at'])) : '' ?></div>
                    </div></div>
                    <?php if ($manage): ?><form method="post" action="<?= e(url($base . '/assessments/' . $a['uuid'] . '/remove')) ?>" class="request-actions"><?= csrf_field() ?>
                        <button class="btn btn-sm btn-link" type="submit">Take out</button></form><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($manage): ?>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('plus-circle') ?>Add an assessment</span></div>
            <div class="card-body">
                <?php if ($attachable === []): ?>
                    <p class="small text-muted mb-0">No assessment is free to add.<?php if ($canBuild): ?> <a href="<?= e(url('/institutions/' . $institution['uuid'] . '/assessments')) ?>">Build one</a> first.<?php endif; ?></p>
                <?php else: ?>
                <form method="post" action="<?= e(url($base . '/assessments')) ?>" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="course-assessment">Assessment</label>
                        <select class="form-select" id="course-assessment" name="assessment" required>
                            <option value="">Choose…</option>
                            <?php foreach ($attachable as $a): ?><option value="<?= e($a['uuid']) ?>"><?= e($a['title']) ?> (<?= e(strtolower(Assessments::STATUSES[$a['status']] ?? $a['status'])) ?>)</option><?php endforeach; ?>
                        </select>
                        <div class="form-text">It is also set for the course's class.</div></div>
                    <button class="btn btn-primary" type="submit"><?= icon('plus') ?>Add</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($view === 'grades'): /** @var array{items:list<array>,rows:list<array>,final:bool,weight:float} $book @var list<array> $online @var list<array> $freeAssignments */
    $f = static fn(?float $n): string => $n === null ? '' : \App\Services\Gradebook::fmt($n);
    $gbase = $base . '/grades';
    $status = $book['final'] ? chip('Final', 'success', 'lock') : chip('Provisional', 'neutral'); ?>
<?php if ($learner): $r = $book['rows'][0] ?? null; ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('journal-check') ?>Your grades</span><span class="d-flex align-items-center gap-2"><?= $status ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/grades.pdf')) ?>"><?= icon('file-earmark-pdf') ?>PDF</a></span></div>
    <?php if ($book['items'] === []): ?>
        <div class="card-body"><?= empty_state('journal-check', 'Nothing graded yet', '', null, true) ?></div>
    <?php else: ?>
    <ul class="list-rows">
        <?php foreach ($book['items'] as $g): $cell = $r['cells'][(int) $g['id']] ?? null; ?>
            <li><div class="row-main"><div class="row-text">
                <div class="row-title"><?= e($g['title']) ?> <span class="text-muted small fw-normal">weight <?= e($f((float) $g['weight'])) ?></span></div>
                <div class="row-meta"><?= $cell === null || $cell['marks'] === null ? 'Not out yet' : e($f($cell['marks'])) . ($g['assessment_id'] !== null ? '%' : ' of ' . e($f((float) $g['max_marks']))) ?></div>
                <?php if (($cell['feedback'] ?? null) !== null): ?><p class="mb-0 mt-1 small text-pre"><?= e((string) $cell['feedback']) ?></p><?php endif; ?>
            </div></div></li>
        <?php endforeach; ?>
    </ul>
    <div class="card-body border-top">
        <div class="h5 mb-1">Course grade: <?= $r !== null && $r['grade'] !== null ? e($f($r['grade'])) . '%' : 'not yet' ?>
            <?= $r !== null && $r['passed'] !== null ? chip($r['passed'] ? 'Passed' : 'Not passed', $r['passed'] ? 'success' : 'danger') : '' ?></div>
        <div class="small text-muted"><?= $book['final'] ? 'Final.' : 'Provisional: from the marks out so far.' ?><?= $course['pass_percent'] !== null ? ' Pass mark ' . e($f((float) $course['pass_percent'])) . '%.' : '' ?></div>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card mb-4">
    <div class="card-header"><span class="card-title"><?= icon('journal-check') ?>Gradebook</span><span class="d-flex flex-wrap align-items-center gap-2"><?= $status ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/grades.csv')) ?>"><?= icon('download') ?>CSV</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/grades.pdf')) ?>"><?= icon('file-earmark-pdf') ?>PDF</a></span></div>
    <?php if ($book['items'] === []): ?>
        <div class="card-body"><?= empty_state('journal-check', 'Nothing graded yet', $manage ? 'Add the course\'s assessments and offline work below.' : '', null, true) ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0 gradebook">
            <thead><tr><th>Learner</th>
                <?php foreach ($book['items'] as $g): ?><th class="text-end"><?= $manage && $g['assessment_id'] === null && $g['assignment_id'] === null ? '<a href="' . e(url($gbase . '/' . $g['uuid'])) . '">' . e($g['title']) . '</a>'
                    : ($manage && $g['assignment_id'] !== null ? '<a href="' . e(url($base . '/assignments/' . $g['assignment_uuid'])) . '">' . e($g['title']) . '</a>' : e($g['title'])) ?>
                    <div class="small text-muted fw-normal"><?= $g['assessment_id'] !== null ? '%' : 'of ' . e($f((float) $g['max_marks'])) ?> · weight <?= e($f((float) $g['weight'])) ?>
                    <?= $g['assessment_id'] !== null ? ($g['results_published_at'] === null ? ' · unpublished' : '') : (($g['assignment_id'] !== null ? $g['assignment_released_at'] : $g['released_at']) === null ? ' · not released' : '') ?></div></th><?php endforeach; ?>
                <th class="text-end">Course</th></tr></thead>
            <tbody>
            <?php foreach ($book['rows'] as $r): ?>
                <tr><td><?= e(Access::displayName($r)) ?><?php if ($r['student_number'] !== null): ?><div class="small text-muted"><?= e($r['student_number']) ?></div><?php endif; ?></td>
                    <?php foreach ($book['items'] as $g): $cell = $r['cells'][(int) $g['id']] ?? null; ?><td class="text-end"><?= $cell !== null && $cell['marks'] !== null ? e($f($cell['marks'])) : '<span class="text-muted">-</span>' ?></td><?php endforeach; ?>
                    <td class="text-end fw-semibold"><?= $r['grade'] !== null ? e($f($r['grade'])) . '%' : '<span class="text-muted">-</span>' ?>
                        <?php if ($r['override'] !== null): ?><div class="small text-muted fw-normal" title="<?= e((string) $r['override']['reason']) ?>">set by hand (worked out: <?= $r['computed'] !== null ? e($f($r['computed'])) . '%' : 'none' ?>)</div><?php endif; ?>
                        <?php if ($r['passed'] !== null): ?><div><?= chip($r['passed'] ? 'Passed' : 'Not passed', $r['passed'] ? 'success' : 'danger') ?></div><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-note"><span class="card-note-text text-muted small"><?= $book['final'] ? 'Final: an item with no mark counts as zero.' : 'Provisional: the course grade is the weighted average of the items each learner has a mark for.' ?>
        Online items use each learner's best attempt.</span></div>
    <?php endif; ?>
</div>
<?php if ($manage): ?>
<div class="row g-4">
    <div class="col-xl-6">
        <?php if ($book['items'] !== []): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('list-ol') ?>Items</span></div>
            <ul class="list-rows">
                <?php foreach ($book['items'] as $g): ?>
                    <li class="req-row"><div class="row-main"><div class="row-text"><div class="row-title"><?= e($g['title']) ?></div>
                        <div class="row-meta"><?= $g['assessment_id'] !== null ? 'Online assessment' : ($g['assignment_id'] !== null ? 'Assignment' : 'Offline') . ', out of ' . e($f((float) $g['max_marks'])) ?> · weight <?= e($f((float) $g['weight'])) ?></div></div></div>
                        <div class="req-actions">
                            <?php if ($g['assessment_id'] === null && $g['assignment_id'] === null): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url($gbase . '/' . $g['uuid'])) ?>"><?= icon('pencil-square') ?>Marks</a><?php endif; ?>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle-panel="gi-<?= e($g['uuid']) ?>" aria-label="Change"><?= icon('pencil') ?></button>
                            <form method="post" action="<?= e(url($gbase . '/' . $g['uuid'] . '/remove')) ?>" data-confirm="Its marks are kept." data-confirm-title="Take it out of the gradebook?" data-confirm-button="Take out"><?= csrf_field() ?>
                                <button class="btn btn-sm btn-link" type="submit">Take out</button></form>
                        </div>
                        <div class="req-edit" id="gi-<?= e($g['uuid']) ?>" hidden>
                            <form method="post" action="<?= e(url($gbase . '/' . $g['uuid'])) ?>" class="row g-2 align-items-end mt-1" data-once><?= csrf_field() ?>
                                <?php if ($g['assessment_id'] === null && $g['assignment_id'] === null): ?>
                                <div class="col-sm-5"><label class="form-label small" for="gt-<?= e($g['uuid']) ?>">Title</label><input class="form-control form-control-sm" id="gt-<?= e($g['uuid']) ?>" name="title" maxlength="160" required value="<?= e($g['title']) ?>"></div>
                                <div class="col-sm-3"><label class="form-label small" for="gm-<?= e($g['uuid']) ?>">Out of</label><input class="form-control form-control-sm" type="number" id="gm-<?= e($g['uuid']) ?>" name="max_marks" min="0.01" step="0.01" required value="<?= e($f((float) $g['max_marks'])) ?>"></div>
                                <?php endif; ?>
                                <div class="col-sm-2"><label class="form-label small" for="gw-<?= e($g['uuid']) ?>">Weight</label><input class="form-control form-control-sm" type="number" id="gw-<?= e($g['uuid']) ?>" name="weight" min="0.01" step="0.01" required value="<?= e($f((float) $g['weight'])) ?>"></div>
                                <div class="col-sm-2"><button class="btn btn-sm btn-primary" type="submit">Save</button></div>
                            </form>
                        </div></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php if (!$book['final']): ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('plus-circle') ?>Add to the gradebook</span></div>
            <div class="card-body">
                <?php if ($online !== []): ?>
                <form method="post" action="<?= e(url($gbase)) ?>" class="row g-2 align-items-end mb-4" data-once><?= csrf_field() ?><input type="hidden" name="kind" value="online">
                    <div class="col-sm-8"><label class="form-label" for="gi-assessment">An assessment of the course</label>
                        <select class="form-select" id="gi-assessment" name="assessment" required><option value="">Choose…</option>
                            <?php foreach ($online as $a): ?><option value="<?= e($a['uuid']) ?>"><?= e($a['title']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-2"><label class="form-label" for="gi-aw">Weight</label><input class="form-control" type="number" id="gi-aw" name="weight" min="0.01" step="0.01" value="1" required></div>
                    <div class="col-sm-2"><button class="btn btn-outline-primary w-100" type="submit">Add</button></div>
                </form>
                <?php endif; ?>
                <?php if ($freeAssignments !== []): ?>
                <form method="post" action="<?= e(url($gbase)) ?>" class="row g-2 align-items-end mb-4" data-once><?= csrf_field() ?><input type="hidden" name="kind" value="assignment">
                    <div class="col-sm-8"><label class="form-label" for="gi-assignment">An assignment of the course</label>
                        <select class="form-select" id="gi-assignment" name="assignment" required><option value="">Choose…</option>
                            <?php foreach ($freeAssignments as $w): ?><option value="<?= e($w['uuid']) ?>"><?= e($w['title']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-2"><label class="form-label" for="gi-ww">Weight</label><input class="form-control" type="number" id="gi-ww" name="weight" min="0.01" step="0.01" value="1" required></div>
                    <div class="col-sm-2"><button class="btn btn-outline-primary w-100" type="submit">Add</button></div>
                </form>
                <?php endif; ?>
                <form method="post" action="<?= e(url($gbase)) ?>" class="row g-2 align-items-end" data-once><?= csrf_field() ?><input type="hidden" name="kind" value="offline">
                    <div class="col-sm-6"><label class="form-label" for="gi-title">Offline work</label><input class="form-control" id="gi-title" name="title" maxlength="160" placeholder="Practical, paper test…" required></div>
                    <div class="col-sm-2"><label class="form-label" for="gi-max">Out of</label><input class="form-control" type="number" id="gi-max" name="max_marks" min="0.01" step="0.01" value="100" required></div>
                    <div class="col-sm-2"><label class="form-label" for="gi-w">Weight</label><input class="form-control" type="number" id="gi-w" name="weight" min="0.01" step="0.01" value="1" required></div>
                    <div class="col-sm-2"><button class="btn btn-outline-primary w-100" type="submit">Add</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-xl-6">
        <?php if ($book['rows'] !== [] && !$book['final']): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('pencil') ?>Set a grade by hand</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($gbase . '/overrides')) ?>" data-once><?= csrf_field() ?>
                    <div class="row g-2">
                        <div class="col-sm-8"><label class="form-label" for="ov-learner">Learner</label><select class="form-select" id="ov-learner" name="learner" required><option value="">Choose…</option>
                            <?php foreach ($book['rows'] as $r): ?><option value="<?= e($r['membership_uuid']) ?>"><?= e(Access::displayName($r)) ?></option><?php endforeach; ?></select></div>
                        <div class="col-sm-4"><label class="form-label" for="ov-percent">Grade, %</label><input class="form-control" type="number" id="ov-percent" name="percent" min="0" max="100" step="0.01"></div>
                        <div class="col-12"><label class="form-label" for="ov-reason">Reason</label><input class="form-control" id="ov-reason" name="reason" maxlength="500" required>
                            <div class="form-text">Kept with the grade. Leave the grade empty to go back to the worked-out one.</div></div>
                    </div>
                    <button class="btn btn-outline-primary mt-3" type="submit"><?= icon('check2') ?>Save</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($book['items'] !== []): ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon($book['final'] ? 'lock' : 'unlock') ?><?= $book['final'] ? 'Grades are final' : 'Make the grades final' ?></span></div>
            <div class="card-body">
                <p class="small text-muted"><?= $book['final'] ? 'Made final ' . e(fmt_time((string) $course['grades_final_at'])) . '. Marks and grades cannot change until they are provisional again.'
                    : 'Unmarked items then count as zero, marks stop changing, and the learners are told.' ?></p>
                <form method="post" action="<?= e(url($gbase . '/final')) ?>" data-confirm="<?= $book['final'] ? 'Marks and grades can change again.' : 'Each learner is told their final grade.' ?>"
                    data-confirm-title="<?= $book['final'] ? 'Make the grades provisional?' : 'Make the grades final?' ?>" data-confirm-button="<?= $book['final'] ? 'Make provisional' : 'Make final' ?>"><?= csrf_field() ?>
                    <input type="hidden" name="final" value="<?= $book['final'] ? '0' : '1' ?>">
                    <button class="btn <?= $book['final'] ? 'btn-outline-secondary' : 'btn-primary' ?>" type="submit"><?= $book['final'] ? 'Make them provisional again' : 'Make the grades final' ?></button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php else: /** @var list<array> $instructors @var list<array> $students */ ?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('person-video3') ?>Instructors</span></div>
            <?php if ($instructors === [] && $course['lead_name'] === null): ?>
                <div class="card-body"><?= empty_state('person-video3', 'None yet', '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php if ($course['lead_name'] !== null): ?><li><div class="row-main"><div class="row-text"><div class="row-title"><?= e($course['lead_name']) ?> <?= chip('Lead', 'primary') ?></div></div></div></li><?php endif; ?>
                <?php foreach ($instructors as $p): if ($p['uuid'] === $course['lead_uuid']) { continue; } ?>
                    <li><div class="row-main"><div class="row-text"><div class="row-title fw-normal"><?= e(Access::displayName($p)) ?></div></div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('mortarboard') ?>Learners</span>
                <?php if ($manage): ?><a class="small" href="<?= e(url('/institutions/' . $institution['uuid'] . '/classes/' . $course['class_uuid'])) ?>">Change in the class</a><?php endif; ?></div>
            <?php if ($learner): ?>
                <div class="card-note"><span class="card-note-text text-muted"><?= (int) $course['learner_count'] ?> learners in <?= e((string) $course['class_name']) ?>.</span></div>
            <?php elseif ($students === []): ?>
                <div class="card-body"><?= empty_state('mortarboard', 'No learners yet', 'Students of the class show here.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack" data-table="course-learners" data-order='[[0,"asc"]]'>
                    <thead><tr><th>Student</th><th>Number</th><th>In the class since</th></tr></thead>
                    <tbody>
                    <?php foreach ($students as $s): ?>
                        <tr><td class="stack-title" data-order="<?= e(strtolower(Access::displayName($s))) ?>"><?= e(Access::displayName($s)) ?></td>
                            <td class="small" data-label="Number"><?= $s['student_number'] !== null ? e($s['student_number']) : '<span class="text-muted">none</span>' ?></td>
                            <td class="small" data-label="In the class since" data-order="<?= e((string) $s['enrolled_at']) ?>"><?= e(fmt_time((string) $s['enrolled_at'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
