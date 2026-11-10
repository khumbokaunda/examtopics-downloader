<?php
/** @var array $provider @var array $exam @var array $version @var list<array> $objectives @var array|null $editing
 *  @var list<array{number:int,text:string,objective:?string}> $rows @var int $without questions in this version with no objective */
use App\Services\Editions;
use App\Services\Objectives;

$base = '/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']);
$action = url($base . '/objectives');
$hidden = csrf_field() . '<input type="hidden" name="version" value="' . e((string) $version['version']) . '">';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/library')) ?>">Library</a></li>
    <li class="breadcrumb-item"><a href="<?= e(url($base, ['version' => $version['version']])) ?>" title="<?= e(strtoupper((string) $exam['code'])) ?>"><?= e(Editions::shortCode((string) $exam['code'])) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page">Objectives</li>
</ol></nav>

<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Objectives</h1>
        <p class="page-subtitle"><?= $objectives === [] ? 'The exam guide\'s domains. Readers can practise one at a time, and mock results are broken down by them.'
            : e(count($objectives) . ' ' . (count($objectives) === 1 ? 'objective' : 'objectives') . ', ' . $without . ' of ' . count($rows) . ' questions without one') ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= e(url($base, ['version' => $version['version']])) ?>"><?= icon('arrow-left') ?>Back to the exam</a>
    </div>
</header>

<div class="row g-4">
    <div class="col-xl-5">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('bullseye') ?>Objectives</span></div>
            <?php if ($objectives === []): ?>
                <div class="card-body">
                    <?= empty_state('bullseye', 'No objectives yet', 'Add them from the exam guide, or start from the topics the questions came in.', null, true) ?>
                    <?php if ($rows !== []): ?>
                    <form class="text-center mt-3" method="post" action="<?= e($action) ?>"><?= $hidden ?><input type="hidden" name="action" value="topics">
                        <button class="btn btn-outline-primary" type="submit"><?= icon('magic') ?>Start from the topics</button></form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($objectives as $o): ?>
                        <li class="list-group-item d-flex align-items-center gap-2">
                            <span class="flex-grow-1"><?php if ($o['code'] !== null): ?><span class="mono text-muted me-1"><?= e($o['code']) ?></span><?php endif; ?><?= e($o['name']) ?>
                                <span class="small text-muted d-block"><?= (int) $o['question_count'] ?> <?= (int) $o['question_count'] === 1 ? 'question' : 'questions' ?></span></span>
                            <a class="btn btn-sm btn-link" href="<?= e(url($base . '/view', ['version' => $version['version'], 'objective' => $o['uuid']])) ?>">Questions</a>
                            <a class="btn btn-sm btn-link" href="<?= e(url($base . '/objectives', ['version' => $version['version'], 'edit' => $o['uuid']])) ?>"><?= icon('pencil') ?>Edit</a>
                            <form method="post" action="<?= e($action) ?>" data-confirm="Its questions are left without an objective." data-confirm-title="Remove <?= e($o['name']) ?>?" data-confirm-button="Remove" data-confirm-tone="danger">
                                <?= $hidden ?><input type="hidden" name="action" value="remove"><input type="hidden" name="objective" value="<?= e($o['uuid']) ?>">
                                <button class="btn btn-sm btn-link btn-link-danger" type="submit">Remove</button></form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card mb-4" id="objective-form">
            <div class="card-header"><span class="card-title"><?= icon($editing !== null ? 'pencil' : 'plus-lg') ?><?= $editing !== null ? 'Edit ' . e($editing['name']) : 'Add an objective' ?></span></div>
            <form class="card-body" method="post" action="<?= e($action) ?>"><?= $hidden ?><input type="hidden" name="action" value="save">
                <?php if ($editing !== null): ?><input type="hidden" name="objective" value="<?= e($editing['uuid']) ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-sm-3"><label class="form-label" for="o-code">Code</label><input class="form-control" id="o-code" name="code" maxlength="32" value="<?= e((string) ($editing['code'] ?? '')) ?>" placeholder="1.0"></div>
                    <div class="col-sm-9"><label class="form-label" for="o-name">Name</label><input class="form-control" id="o-name" name="name" maxlength="160" required value="<?= e((string) ($editing['name'] ?? '')) ?>"></div>
                </div>
                <div class="mt-3 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= icon('check2') ?><?= $editing !== null ? 'Save' : 'Add' ?></button>
                    <?php if ($editing !== null): ?><a class="btn btn-outline-secondary" href="<?= e(url($base . '/objectives', ['version' => $version['version']])) ?>">Cancel</a><?php endif; ?></div>
            </form>
        </div>

        <?php if ($objectives !== []): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('list-ol') ?>Put questions under an objective</span></div>
            <form class="card-body" method="post" action="<?= e($action) ?>"><?= $hidden ?><input type="hidden" name="action" value="assign">
                <div class="mb-3"><label class="form-label" for="o-numbers">Question numbers</label>
                    <input class="form-control" id="o-numbers" name="numbers" required placeholder="1-20, 25, 31-40"></div>
                <div class="mb-3"><label class="form-label" for="o-target">Objective</label>
                    <select class="form-select" id="o-target" name="objective">
                        <?php foreach ($objectives as $o): ?><option value="<?= e($o['uuid']) ?>"><?= e(($o['code'] !== null ? $o['code'] . ' ' : '') . $o['name']) ?></option><?php endforeach; ?>
                        <option value="">No objective</option>
                    </select></div>
                <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Apply</button>
                <p class="small text-muted mt-2 mb-0">Numbers are as in this version. A mock is broken down by objective once <?= (int) Objectives::BREAKDOWN_COVERAGE ?>% of its questions have one.</p>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-xl-7">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('card-list') ?>Questions</span></div>
            <?php if ($rows === []): ?>
                <div class="card-body"><?= empty_state('card-list', 'No questions', 'This version has no questions.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack" data-table="objective-questions" data-order='[[0,"asc"]]'>
                    <thead><tr><th data-type="num">Question</th><th>Text</th><th>Objective</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="stack-title" data-order="<?= $r['number'] ?>"><a class="table-row-link" href="<?= e(url($base . '/view', ['version' => $version['version'], 'jump' => (string) $r['number']]) . '#question-' . $r['number']) ?>">Question <?= $r['number'] ?></a></td>
                            <td data-label="Text"><span class="truncate" title="<?= e($r['text']) ?>"><?= e(mb_substr(preg_replace('/\s+/', ' ', $r['text']) ?? '', 0, 120)) ?></span></td>
                            <td data-label="Objective"><?= $r['objective'] !== null ? e($r['objective']) : '<span class="text-muted">None</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
