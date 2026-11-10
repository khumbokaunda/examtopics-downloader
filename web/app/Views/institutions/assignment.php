<?php
/**
 * One assignment. Staff: its settings, rubric and who has handed in; learners: the instructions,
 * handing in, and their mark once released.
 *
 * @var array $institution @var array $membership @var string $tab @var array $crumb @var array $course @var array $assignment @var bool $manage @var string $timezone
 * @var list<array> $criteria
 */
use App\Controllers\InstitutionAssessmentController as AC;
use App\Services\Access;
use App\Services\Coursework;
use App\Services\Gradebook;
$a = $assignment;
$base = '/institutions/' . $institution['uuid'] . '/courses/' . $course['uuid'] . '/assignments/' . $a['uuid'];
$f = static fn(?float $n): string => $n === null ? '' : Gradebook::fmt($n);
$tone = ['draft' => 'neutral', 'published' => 'success', 'closed' => 'neutral'];
$due = $a['due_at'] !== null ? fmt_time((string) $a['due_at']) : null;
$kinds = implode(', ', array_map(static fn(string $k): string => Coursework::KINDS[$k]['label'] ?? $k, explode(',', (string) $a['file_kinds'])));
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab, 'crumb' => $crumb]) ?>
<div class="card mb-4"><div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="icon-bubble" data-tone="primary"><?= icon('file-earmark-arrow-up') ?></span>
    <div class="flex-grow-1">
        <h2 class="h5 mb-1"><?= e($a['title']) ?> <?= chip(Coursework::STATUSES[$a['status']] ?? $a['status'], $tone[$a['status']] ?? 'neutral') ?><?= $a['released_at'] !== null ? ' ' . chip('Marks out', 'primary') : '' ?></h2>
        <div class="small text-muted"><?= $due !== null ? 'Due ' . e($due) : 'No deadline' ?> · out of <?= e($f((float) $a['max_marks'])) ?> · <?= e($kinds) ?>, up to <?= (int) $a['max_files'] ?> file<?= (int) $a['max_files'] === 1 ? '' : 's' ?> of <?= (int) $a['max_mb'] ?> MB
            · late work: <?= $a['late_policy'] === 'refuse' ? 'refused' : 'accepted, marked late' ?></div>
    </div>
</div></div>

<?php if ((string) ($a['instructions'] ?? '') !== ''): ?><div class="card mb-4"><div class="card-body"><p class="mb-0 text-pre"><?= e((string) $a['instructions']) ?></p></div></div><?php endif; ?>

<?php if ($manage): /** @var list<array> $roster @var bool $marked */
    $handed = count(array_filter($roster, static fn(array $r): bool => $r['submission_uuid'] !== null));
    $done = count(array_filter($roster, static fn(array $r): bool => $r['marks'] !== null)); ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('inbox') ?>Handed in: <?= $handed ?> of <?= count($roster) ?> · marked: <?= $done ?></span></div>
            <?php if ($roster === []): ?>
                <div class="card-body"><?= empty_state('mortarboard', 'No learners', 'The class has no students yet.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack">
                    <thead><tr><th>Learner</th><th>Handed in</th><th>Mark</th></tr></thead>
                    <tbody>
                    <?php foreach ($roster as $r): ?>
                        <tr><td class="stack-title"><a class="table-row-link" href="<?= e(url($base . '/submissions/' . $r['membership_uuid'])) ?>"><?= e(Access::displayName($r)) ?></a>
                                <?php if ($r['student_number'] !== null): ?><div class="small text-muted"><?= e($r['student_number']) ?></div><?php endif; ?></td>
                            <td class="small" data-label="Handed in"><?= $r['submitted_at'] !== null ? e(fmt_time((string) $r['submitted_at'])) . ((int) $r['late'] === 1 ? ' ' . chip('Late', 'warning') : '') : '<span class="text-muted">not yet</span>' ?></td>
                            <td class="small" data-label="Mark"><?= $r['marks'] !== null ? e($f((float) $r['marks'])) . ' of ' . e($f((float) $a['max_marks'])) : '<span class="text-muted">-</span>' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('ui-checks-grid') ?>Rubric (optional)</span></div>
            <div class="card-body">
                <?php if ($marked): ?>
                    <?php if ($criteria === []): ?><p class="small text-muted mb-0">No rubric. Marking has started, so it stays that way.</p><?php else: ?>
                    <ul class="mb-0 small"><?php foreach ($criteria as $cr): ?><li><?= e($cr['title']) ?>: <?= e($f((float) $cr['max_marks'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
                <?php else: ?>
                <form method="post" action="<?= e(url($base . '/rubric')) ?>" data-once><?= csrf_field() ?>
                    <p class="small text-muted">The mark is then the sum of the criteria. Leave a row empty to drop it.</p>
                    <?php foreach (array_merge($criteria, array_fill(0, max(2, 6 - count($criteria)), null)) as $n => $cr): ?>
                        <div class="row g-2 mb-2">
                            <div class="col-sm-5"><input class="form-control form-control-sm" name="criterion[<?= $n ?>]" maxlength="160" placeholder="Criterion" aria-label="Criterion <?= $n + 1 ?>" value="<?= e((string) ($cr['title'] ?? '')) ?>"></div>
                            <div class="col-sm-2"><input class="form-control form-control-sm" type="number" name="criterion_marks[<?= $n ?>]" min="0.01" step="0.01" placeholder="Marks" aria-label="Marks for criterion <?= $n + 1 ?>" value="<?= $cr !== null ? e($f((float) $cr['max_marks'])) : '' ?>"></div>
                            <div class="col-sm-5"><input class="form-control form-control-sm" name="criterion_description[<?= $n ?>]" maxlength="500" placeholder="What earns them (optional)" aria-label="Description of criterion <?= $n + 1 ?>" value="<?= e((string) ($cr['description'] ?? '')) ?>"></div>
                        </div>
                    <?php endforeach; ?>
                    <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon('check2') ?>Save the rubric</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card mb-4"><div class="card-body d-flex flex-wrap gap-2">
            <?php if ($a['status'] !== 'published' && $a['released_at'] === null): ?>
                <form method="post" action="<?= e(url($base . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="published"><button class="btn btn-primary" type="submit"><?= icon('send') ?><?= $a['status'] === 'draft' ? 'Open it to the learners' : 'Open it again' ?></button></form>
            <?php elseif ($a['status'] === 'published'): ?>
                <form method="post" action="<?= e(url($base . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="closed"><button class="btn btn-outline-secondary" type="submit"><?= icon('lock') ?>Close it</button></form>
            <?php endif; ?>
            <?php if ($a['released_at'] === null && $done > 0): ?>
                <form method="post" action="<?= e(url($base . '/release')) ?>" data-confirm="Each learner sees their mark and feedback, and nothing more can be handed in." data-confirm-title="Release the marks?" data-confirm-button="Release"><?= csrf_field() ?>
                    <button class="btn btn-outline-primary" type="submit"><?= icon('megaphone') ?>Release the marks</button></form>
            <?php endif; ?>
        </div></div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('pencil') ?>Settings</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base)) ?>" data-once><?= csrf_field() ?>
                    <?= \App\Core\View::current()->partial('institutions/_assignment_fields', ['a' => $a, 'timezone' => $timezone, 'rubric' => $criteria !== []]) ?>
                    <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php else: /** @var array|null $submission @var int $attempts @var array|null $mark @var bool $canSubmit */
    $closed = $a['status'] !== 'published' || $a['released_at'] !== null;
    $lateNow = $a['due_at'] !== null && \App\Core\Database::now() > $a['due_at']; ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('inbox') ?>Your work</span></div>
            <div class="card-body">
                <?php if ($submission === null): ?>
                    <p class="mb-0 text-muted">Nothing handed in yet.</p>
                <?php else: ?>
                    <p class="mb-2">Handed in <?= e(fmt_time((string) $submission['submitted_at'])) ?><?= (int) $submission['late'] === 1 ? ' ' . chip('Late', 'warning') : '' ?><?= $attempts > 1 ? ' (attempt ' . $attempts . ')' : '' ?>.</p>
                    <ul class="mb-0">
                        <?php foreach ($submission['files'] as $file): ?><li><a href="<?= e(url($base . '/files/' . $file['uuid'])) ?>"><?= e($file['filename']) ?></a> <span class="small text-muted">(<?= e(number_format((int) $file['size'] / 1024, 0)) ?> KB)</span></li><?php endforeach; ?>
                    </ul>
                    <?php if ($submission['note'] !== null): ?><p class="small text-muted mt-2 mb-0 text-pre"><?= e((string) $submission['note']) ?></p><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($mark !== null && $mark['marks'] !== null || ($mark['feedback'] ?? null) !== null): ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('check2-circle') ?>Your mark</span></div>
            <div class="card-body">
                <?php if ($mark['marks'] !== null): ?><div class="h4"><?= e($f((float) $mark['marks'])) ?> of <?= e($f((float) $a['max_marks'])) ?></div><?php endif; ?>
                <?php if ($criteria !== []): ?><ul class="small"><?php foreach ($criteria as $cr): ?><li><?= e($cr['title']) ?>: <?= isset($mark['criteria'][(string) $cr['id']]) ? e($f((float) $mark['criteria'][(string) $cr['id']])) : '-' ?> of <?= e($f((float) $cr['max_marks'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
                <?php if ($mark['feedback'] !== null): ?><p class="mb-0 text-pre"><?= e((string) $mark['feedback']) ?></p><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-xl-5">
        <?php if ($canSubmit && !$closed && !($lateNow && $a['late_policy'] === 'refuse')): ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('upload') ?><?= $submission === null ? 'Hand in' : 'Hand in again' ?></span></div>
            <div class="card-body">
                <?php if ($lateNow): ?><div class="alert alert-warning small" role="status"><?= icon('exclamation-triangle') ?><div class="alert-body">The deadline has passed. It will be marked late.</div></div><?php endif; ?>
                <form method="post" action="<?= e(url($base . '/submit')) ?>" enctype="multipart/form-data" data-once data-busy="Uploading…"><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="hand-in"><?= (int) $a['max_files'] === 1 ? 'File' : 'Files (up to ' . (int) $a['max_files'] . ')' ?></label>
                        <input class="form-control" type="file" id="hand-in" name="files[]"<?= (int) $a['max_files'] > 1 ? ' multiple' : '' ?> required>
                        <div class="form-text"><?= e($kinds) ?>, up to <?= (int) $a['max_mb'] ?> MB each.<?= $submission !== null ? ' This replaces what you handed in.' : '' ?></div></div>
                    <div class="mb-3"><label class="form-label" for="hand-note">Note (optional)</label><textarea class="form-control" id="hand-note" name="note" rows="2" maxlength="2000"></textarea></div>
                    <button class="btn btn-primary" type="submit"><?= icon('upload') ?>Hand in</button>
                </form>
            </div>
        </div>
        <?php elseif ($canSubmit): ?>
            <div class="alert alert-info" role="status"><?= icon('info-circle') ?><div class="alert-body"><?= $a['released_at'] !== null ? 'The marks are out.' : ($a['status'] === 'closed' ? 'This assignment is closed.' : 'The deadline has passed, and late work is not accepted.') ?></div></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
