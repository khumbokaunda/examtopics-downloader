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
