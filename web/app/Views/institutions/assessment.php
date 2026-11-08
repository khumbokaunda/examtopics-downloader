<?php
/** @var array $institution @var array $membership @var string $tab @var array $assessment @var list<array> $items @var list<array> $blueprints @var list<array> $assignments
 *  @var int $candidates @var list<array> $checks @var bool $canEdit @var bool $canAssign @var bool $canSchedule @var list<array> $banks @var list<array> $classes
 *  @var list<array> $students @var string $timezone */
use App\Controllers\InstitutionAssessmentController as C;
use App\Services\Access;
use App\Services\Assessments;
use App\Services\InstitutionStructure;
use App\Services\QuestionBanks;
$a = $assessment;
$base = '/institutions/' . $institution['uuid'] . '/assessments/' . $a['uuid'];
$tone = ['draft' => 'neutral', 'published' => 'success', 'closed' => 'info', 'archived' => 'neutral'];
$drawn = array_sum(array_map(static fn(array $r): int => (int) $r['question_count'], $blueprints));
$fixedMarks = array_sum(array_map(static fn(array $it): float => (float) ($it['marks'] ?? 0), $items));
$ready = $checks !== [] && array_filter($checks, static fn(array $c): bool => !$c['ok']) === [];
$creator = (int) $a['created_by'] === (int) $membership['id'];
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Assessments' => '/institutions/' . $institution['uuid'] . '/assessments', $a['title'] => null]]) ?>

<div class="kpi-strip">
    <?php foreach ([
        ['Status', Assessments::STATUSES[$a['status']] ?? $a['status'], 'signpost-split', $tone[$a['status']] ?? 'neutral'],
        ['Questions', (string) (count($items) + $drawn), 'list-check', 'primary'],
        ['Time allowed', (int) $a['duration_minutes'] . ' min', 'stopwatch', 'info'],
        ['Candidates now', (string) $candidates, 'people', $candidates > 0 ? 'success' : 'warning'],
    ] as [$label, $value, $iconName, $kt]): ?>
        <div class="kpi" data-tone="<?= e($kt) ?>"><span class="kpi-icon"><?= icon($iconName) ?></span>
            <span class="kpi-text"><span class="kpi-value d-block"><?= e($value) ?></span><span class="kpi-label d-block"><?= e($label) ?></span></span></div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card mb-4" id="questions">
            <div class="card-header"><span class="card-title"><?= icon('list-check') ?>Questions</span>
                <span class="d-flex gap-2"><?php if ($a['status'] !== 'draft' && !empty($canWatch)): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url($base . '/live')) ?>"><?= icon('broadcast') ?>Live</a><?php endif; ?>
                <?php if ($a['status'] !== 'draft'): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url($base . '/results')) ?>"><?= icon('table') ?>Results</a><?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/preview')) ?>"><?= icon('eye') ?>Preview a form</a>
                <?php if ($canEdit): ?><a class="btn btn-sm btn-primary" href="<?= e(url($base . '/add')) ?>"><?= icon('plus') ?>Add questions</a><?php endif; ?></span></div>
            <?php if ($items === [] && $blueprints === []): ?>
                <div class="card-body"><?= empty_state('list-check', 'No questions yet', $canEdit ? 'Pick questions or set a blueprint.' : '', null, true) ?></div>
            <?php endif; ?>
            <?php if ($items !== []): ?>
                <div class="card-body pb-0"><h2 class="section-heading">Chosen questions: <?= count($items) ?>, <?= e(C::marks($fixedMarks)) ?> marks<?= $a['status'] !== 'draft' ? ', fixed to the versions published when it was published' : '' ?></h2></div>
                <ul class="list-rows">
                    <?php foreach ($items as $n => $it): ?>
                        <li class="request-row">
                            <div class="row-main"><span class="text-muted small"><?= $n + 1 ?>.</span><div class="row-text">
                                <div class="row-title fw-normal"><?= $it['text'] !== null ? e(QuestionBanks::excerpt((string) $it['text'], 130)) : '<span class="text-danger">No published version</span>' ?></div>
                                <div class="row-meta"><?= e($it['bank_name']) ?> · v<?= (int) $it['version'] ?> · <?= e(C::marks($it['marks'])) ?> marks<?= $it['topic_name'] !== null ? ' · ' . e($it['topic_name']) : '' ?>
                                    <?= $it['question_status'] === 'archived' ? chip('Archived', 'danger') : '' ?></div>
                            </div></div>
                            <?php if ($canEdit): ?>
                            <div class="request-actions">
                                <form method="post" action="<?= e(url($base . '/questions/' . (int) $it['id'] . '/move')) ?>"><?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit" name="direction" value="up" aria-label="Move up" <?= $n === 0 ? 'disabled' : '' ?>><?= icon('arrow-up') ?></button>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit" name="direction" value="down" aria-label="Move down" <?= $n === count($items) - 1 ? 'disabled' : '' ?>><?= icon('arrow-down') ?></button>
                                </form>
                                <form method="post" action="<?= e(url($base . '/questions/' . (int) $it['id'] . '/remove')) ?>"><?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Take out"><?= icon('x-lg') ?></button></form>
                            </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($blueprints !== []): ?>
                <div class="card-body pb-0"><h2 class="section-heading">Drawn for each candidate: <?= $drawn ?></h2></div>
                <ul class="list-rows">
                    <?php foreach ($blueprints as $r): ?>
                        <li class="request-row">
                            <div class="row-main"><span class="icon-bubble" data-tone="info"><?= icon('shuffle') ?></span><div class="row-text">
                                <div class="row-title fw-normal"><?= (int) $r['question_count'] ?> from <?= e(Assessments::rowLabel($r)) ?></div>
                            </div></div>
                            <?php if ($canEdit): ?>
                            <div class="request-actions"><form method="post" action="<?= e(url($base . '/blueprint/' . (int) $r['id'] . '/remove')) ?>"><?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Take out"><?= icon('x-lg') ?></button></form></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card mb-4" id="settings">
            <div class="card-header"><span class="card-title"><?= icon('sliders') ?>Settings</span></div>
            <div class="card-body">
                <?php if ($canEdit): ?>
                <form method="post" action="<?= e(url($base . '/settings')) ?>" data-once><?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label" for="title">Title</label><input class="form-control" id="title" name="title" value="<?= e($a['title']) ?>" maxlength="200" required></div>
                        <div class="col-md-4"><label class="form-label" for="type">Kind</label><select class="form-select" id="type" name="type">
                            <?php foreach (Assessments::TYPES as $k => $v): ?><option value="<?= e($k) ?>" <?= $a['type'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
                        <div class="col-12"><label class="form-label" for="description">Description (for staff, optional)</label><input class="form-control" id="description" name="description" value="<?= e((string) ($a['description'] ?? '')) ?>" maxlength="2000"></div>
                        <div class="col-12"><label class="form-label" for="instructions">Instructions for candidates</label>
                            <textarea class="form-control" id="instructions" name="instructions" rows="4" maxlength="10000" placeholder="Answer every question. You can go back to a question until you hand in."><?= e((string) ($a['instructions'] ?? '')) ?></textarea></div>
                        <div class="col-sm-4"><label class="form-label" for="duration_minutes">Time allowed (minutes)</label><input class="form-control" type="number" min="1" max="600" id="duration_minutes" name="duration_minutes" value="<?= (int) $a['duration_minutes'] ?>" required></div>
                        <div class="col-sm-4"><label class="form-label" for="pass_percent">Pass mark (%)</label><input class="form-control" type="number" min="0" max="100" step="0.5" id="pass_percent" name="pass_percent" value="<?= e($a['pass_percent'] !== null ? C::marks($a['pass_percent']) : '') ?>" placeholder="none"></div>
                        <div class="col-sm-4"><label class="form-label" for="max_attempts">Attempts allowed</label><input class="form-control" type="number" min="1" max="20" id="max_attempts" name="max_attempts" value="<?= (int) $a['max_attempts'] ?>" required></div>
                        <div class="col-sm-6"><label class="form-label" for="opens_at">Opens (<?= e($timezone) ?>)</label><input class="form-control" type="datetime-local" id="opens_at" name="opens_at" value="<?= e(C::localInput($a['opens_at'], $timezone)) ?>"><div class="form-text">Empty: when it is published.</div></div>
                        <div class="col-sm-6"><label class="form-label" for="closes_at">Closes (<?= e($timezone) ?>)</label><input class="form-control" type="datetime-local" id="closes_at" name="closes_at" value="<?= e(C::localInput($a['closes_at'], $timezone)) ?>"><div class="form-text">Empty: until it is closed.</div></div>
                        <div class="col-sm-6"><label class="form-label" for="negative_marking">Taken off for a wrong answer (times its marks)</label><input class="form-control" type="number" min="0" max="1" step="0.05" id="negative_marking" name="negative_marking" value="<?= e(C::marks($a['negative_marking'])) ?>"><div class="form-text">0 for none. 0.25 takes off a quarter.</div></div>
                        <div class="col-sm-6"><label class="form-label" for="review_policy">After results are published, candidates see</label><select class="form-select" id="review_policy" name="review_policy">
                            <?php foreach (Assessments::REVIEW_POLICIES as $k => $v): ?><option value="<?= e($k) ?>" <?= $a['review_policy'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
                        <?php if (!empty($modules)): ?>
                        <div class="col-12"><label class="form-label" for="module">Module</label><select class="form-select" id="module" name="module"><option value="">Not part of a module</option>
                            <?php foreach ($modules as $mod): ?><option value="<?= e($mod['uuid']) ?>" <?= (int) ($a['module_id'] ?? 0) === (int) $mod['id'] ? 'selected' : '' ?>><?= e($mod['programme_name'] . ': ' . ($mod['code'] !== null ? $mod['code'] . ' ' : '') . $mod['name']) ?></option><?php endforeach; ?></select>
                            <div class="form-text">Passing it completes the module.</div></div>
                        <?php endif; ?>
                        <div class="col-12"><label class="form-label" for="monitoring">Monitoring during the sitting</label><select class="form-select" id="monitoring" name="monitoring">
                            <?php foreach (\App\Services\Integrity::POLICIES as $k => $p): ?><option value="<?= e($k) ?>" <?= ($a['monitoring'] ?? 'basic') === $k ? 'selected' : '' ?>><?= e($p['name'] . ': ' . $p['text']) ?></option><?php endforeach; ?></select>
                            <div class="form-text">Candidates are told before they start.</div></div>
                        <div class="col-12">
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="shuffle_questions" name="shuffle_questions" value="1" <?= (int) $a['shuffle_questions'] ? 'checked' : '' ?>><label class="form-check-label" for="shuffle_questions">Put the questions in a different order for each candidate</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="shuffle_choices" name="shuffle_choices" value="1" <?= (int) $a['shuffle_choices'] ? 'checked' : '' ?>><label class="form-check-label" for="shuffle_choices">Put the choices of each question in a different order (true or false stays as it is)</label></div>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit"><?= icon('check2') ?>Save settings</button>
                </form>
                <?php else: ?>
                <dl class="row small mb-0">
                    <dt class="col-sm-4">Kind</dt><dd class="col-sm-8"><?= e(Assessments::TYPES[$a['type']] ?? $a['type']) ?></dd>
                    <dt class="col-sm-4">Time allowed</dt><dd class="col-sm-8"><?= (int) $a['duration_minutes'] ?> minutes</dd>
                    <dt class="col-sm-4">Pass mark</dt><dd class="col-sm-8"><?= $a['pass_percent'] !== null ? e(C::marks($a['pass_percent'])) . '%' : 'none' ?></dd>
                    <dt class="col-sm-4">Attempts</dt><dd class="col-sm-8"><?= (int) $a['max_attempts'] ?></dd>
                    <dt class="col-sm-4">Opens</dt><dd class="col-sm-8"><?= $a['opens_at'] !== null ? e(fmt_time((string) $a['opens_at'])) : 'when published' ?></dd>
                    <dt class="col-sm-4">Closes</dt><dd class="col-sm-8"><?= $a['closes_at'] !== null ? e(fmt_time((string) $a['closes_at'])) : 'when closed by hand' ?></dd>
                    <dt class="col-sm-4">Wrong answers</dt><dd class="col-sm-8"><?= (float) $a['negative_marking'] > 0 ? 'lose ' . e(C::marks($a['negative_marking'])) . ' times their marks' : 'lose nothing' ?></dd>
                    <dt class="col-sm-4">Order</dt><dd class="col-sm-8"><?= (int) $a['shuffle_questions'] ? 'Questions shuffled' : 'Questions in order' ?>, <?= (int) $a['shuffle_choices'] ? 'choices shuffled' : 'choices in order' ?></dd>
                    <dt class="col-sm-4">Monitoring</dt><dd class="col-sm-8"><?= e((\App\Services\Integrity::POLICIES[$a['monitoring'] ?? 'basic'] ?? \App\Services\Integrity::POLICIES['basic'])['name']) ?></dd>
                    <dt class="col-sm-4">Afterwards</dt><dd class="col-sm-8"><?= e(Assessments::REVIEW_POLICIES[$a['review_policy']] ?? '') ?></dd>
                    <dt class="col-sm-4">Instructions</dt><dd class="col-sm-8"><?= nl2br(e((string) ($a['instructions'] ?? ''))) ?></dd>
                </dl>
                <?php if ($canSchedule && $a['status'] === 'published'): ?>
                <form method="post" action="<?= e(url($base . '/schedule')) ?>" class="row g-2 mt-3 align-items-end" data-once><?= csrf_field() ?>
                    <div class="col-sm-5"><label class="form-label small" for="s-opens">Opens</label><input class="form-control form-control-sm" type="datetime-local" id="s-opens" name="opens_at" value="<?= e(C::localInput($a['opens_at'], $timezone)) ?>"></div>
                    <div class="col-sm-5"><label class="form-label small" for="s-closes">Closes</label><input class="form-control form-control-sm" type="datetime-local" id="s-closes" name="closes_at" value="<?= e(C::localInput($a['closes_at'], $timezone)) ?>"></div>
                    <div class="col-sm-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit">Save times</button></div>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <?php if ($a['status'] === 'draft'): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('clipboard-check') ?>Ready to publish?</span></div>
            <ul class="list-rows">
                <?php foreach ($checks as $c): ?>
                    <li class="request-row"><div class="row-main"><span class="icon-bubble" data-tone="<?= $c['ok'] ? 'success' : 'warning' ?>"><?= icon($c['ok'] ? 'check2' : 'exclamation') ?></span>
                        <div class="row-text"><div class="row-title fw-normal"><?= e($c['text']) ?></div></div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($canSchedule): ?>
            <div class="card-body">
                <form method="post" action="<?= e(url($base . '/status')) ?>" data-confirm="Its chosen questions are fixed to the versions published now, and its settings can no longer be changed." data-confirm-title="Publish this assessment?" data-confirm-button="Publish"><?= csrf_field() ?>
                    <button class="btn btn-success" type="submit" name="action" value="publish" <?= $ready ? '' : 'disabled' ?>><?= icon('clipboard-check') ?>Publish</button>
                </form>
            </div>
            <?php else: ?><div class="card-footer small text-muted">An examination officer or administrator publishes it.</div><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card mb-4" id="assigned">
            <div class="card-header"><span class="card-title"><?= icon('people') ?>Assigned to</span></div>
            <?php if ($assignments === []): ?><div class="card-body text-muted small">Nobody yet.</div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($assignments as $s): ?>
                    <li class="request-row"><div class="row-main"><span class="icon-bubble" data-tone="neutral"><?= icon($s['class_id'] !== null ? 'collection' : 'person') ?></span><div class="row-text">
                        <div class="row-title fw-normal"><?= $s['class_id'] !== null ? e(InstitutionStructure::label((string) $s['class_name'], (string) $s['intake'])) . ' <span class="text-muted small">· ' . (int) $s['class_size'] . ' students</span>' : e(Access::displayName($s)) ?></div>
                    </div></div>
                    <?php if ($canAssign): ?><div class="request-actions"><form method="post" action="<?= e(url($base . '/assignments/' . (int) $s['id'] . '/remove')) ?>"><?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Take off"><?= icon('x-lg') ?></button></form></div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($canAssign): ?>
            <div class="card-body">
                <?php if ($classes !== []): ?>
                <form method="post" action="<?= e(url($base . '/assignments')) ?>" class="toolbar mb-2" data-once><?= csrf_field() ?>
                    <label class="visually-hidden" for="as-class">Class</label>
                    <select class="form-select form-select-sm" id="as-class" name="class"><?php foreach ($classes as $c): ?><option value="<?= e($c['uuid']) ?>"><?= e(InstitutionStructure::label($c['name'], $c['intake'])) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon('plus') ?>Assign class</button>
                </form>
                <?php else: ?><p class="small text-muted">No classes to assign<?= !\App\Services\InstitutionAccess::can($membership, 'classes.view') ? ' (you assign the classes you teach)' : '' ?>.</p><?php endif; ?>
                <?php if ($students !== []): ?>
                <form method="post" action="<?= e(url($base . '/assignments')) ?>" class="toolbar" data-once><?= csrf_field() ?>
                    <label class="visually-hidden" for="as-student">Student</label>
                    <select class="form-select form-select-sm" id="as-student" name="student"><?php foreach ($students as $st): ?><option value="<?= e($st['membership_uuid']) ?>"><?= e(Access::displayName($st)) ?><?= $st['student_number'] !== null ? ' (' . e($st['student_number']) . ')' : '' ?></option><?php endforeach; ?></select>
                    <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon('plus') ?>Assign student</button>
                </form>
                <?php endif; ?>
                <p class="form-text mb-0">A class means its students at the time they sit it.</p>
            </div>
            <?php endif; ?>
        </div>

        <?php
        $actions = array_filter([
            'unpublish' => [$canSchedule && $a['status'] === 'published', 'Back to a draft', 'clipboard-x'],
            'close' => [$canSchedule && $a['status'] === 'published', 'Close now', 'lock'],
            'reopen' => [$canSchedule && $a['status'] === 'closed', 'Reopen', 'unlock'],
            'archive' => [($canSchedule || $creator) && in_array($a['status'], ['draft', 'closed'], true), 'Archive', 'archive'],
            'restore' => [($canSchedule || $creator) && $a['status'] === 'archived', 'Restore as a draft', 'arrow-counterclockwise'],
        ], static fn(array $x): bool => $x[0]);
        ?>
        <?php if ($actions !== []): ?>
        <div class="card"><div class="card-body">
            <form method="post" action="<?= e(url($base . '/status')) ?>" class="d-flex flex-wrap gap-2" data-once><?= csrf_field() ?>
                <?php foreach ($actions as $k => [, $label, $iconName]): ?><button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="<?= e($k) ?>"><?= icon($iconName) ?><?= e($label) ?></button><?php endforeach; ?>
            </form>
        </div></div>
        <?php endif; ?>
    </div>
</div>
