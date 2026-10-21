<?php
/**
 * A person's study progress: their own, or (for progress.view_all) someone else's, read only.
 *
 * @var array $person @var bool $own @var list<array> $rows
 * @var array{exams:int,answered:int,correct:int,mistakes:int,percent:?int,last_at:?string} $totals
 */
use App\Services\Access;
use App\Services\Editions;
use App\Services\StudyProgress;

$everyone = can('progress.view_all');
?>
<?php if (!$own): ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/progress/everyone')) ?>">Everyone's progress</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e(Access::displayName($person)) ?></li>
</ol></nav>
<?php endif; ?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= $own ? 'My progress' : e(Access::displayName($person)) . '\'s progress' ?></h1>
        <p class="page-subtitle"><?= $own
            ? 'Every exam you have read or practised. Answers count once you reveal them in practice mode.' . ($everyone ? '' : ' Administrators can see this page.')
            : 'What ' . e(Access::displayName($person)) . ' (' . e($person['username']) . ') has read and practised. Only they can change it.' ?></p>
    </div>
    <?php if ($everyone): ?>
    <div class="page-actions">
        <nav class="segmented" aria-label="Whose progress">
            <a class="<?= $own ? 'is-active' : '' ?>" href="<?= e(url('/progress')) ?>"<?= $own ? ' aria-current="page"' : '' ?>><?= icon('person') ?>Mine</a>
            <a class="<?= $own ? '' : 'is-active' ?>" href="<?= e(url('/progress/everyone')) ?>"><?= icon('people') ?>Everyone</a>
        </nav>
    </div>
    <?php endif; ?>
</header>

<div class="kpi-strip">
    <?php foreach ([
        ['Exams studied', number_format($totals['exams']), 'journal-bookmark', 'primary'],
        ['Questions answered', number_format($totals['answered']), 'ui-checks', 'info'],
        ['Answered right', $totals['percent'] === null ? 'n/a' : $totals['percent'] . '%', 'check-circle', 'success'],
        ['To go over again', number_format($totals['mistakes']), 'arrow-repeat', $totals['mistakes'] > 0 ? 'warning' : 'neutral'],
    ] as [$label, $value, $iconName, $tone]): ?>
        <div class="kpi" data-tone="<?= e($tone) ?>">
            <span class="kpi-icon"><?= icon($iconName) ?></span>
            <span class="kpi-text"><span class="kpi-value d-block"><?= e($value) ?></span><span class="kpi-label d-block"><?= e($label) ?></span></span>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($rows === []): ?>
    <div class="card"><div class="card-body"><?= $own
        ? empty_state('graph-up', 'Nothing studied yet', 'Open an exam in the library. Pick answers in practice mode and reveal them to see how you are doing.', ['Go to the library', url('/library')])
        : empty_state('graph-up', 'Nothing studied yet', e(Access::displayName($person)) . ' has not read or practised any exam.') ?></div></div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="progress" data-order='[[5,"desc"]]'>
            <thead><tr>
                <th>Exam</th><th>Provider</th><th data-type="num">Answered</th><th class="text-end" data-type="num">Right</th>
                <th class="text-end" data-type="num">To go over</th><th>Last studied</th>
                <?php if ($own): ?><th class="text-end" data-nosort data-novis data-nosearch data-export="false"><span class="visually-hidden">Actions</span></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $t = $r['progress']; $count = (int) ($r['best']['collected_count'] ?? 0);
                $path = '/library/' . rawurlencode((string) $r['provider_slug']) . '/' . rawurlencode((string) $r['code']);
                $pct = StudyProgress::percent($t['correct'], $t['answered']); ?>
                <tr>
                    <td class="stack-title" data-order="<?= e(Editions::title($r)) ?>" data-search="<?= e($r['code'] . ' ' . ($r['name'] ?? '')) ?>">
                        <a class="table-row-link" href="<?= e(url($path)) ?>"><?= e(Editions::shortCode((string) $r['code'])) ?></a>
                        <?php if (Editions::title($r) !== Editions::shortCode((string) $r['code'])): ?><div class="cell-sub ms-0 cell-wrap text-clamp-2"><?= e(Editions::title($r)) ?></div><?php endif; ?>
                    </td>
                    <td data-label="Provider"><?= e(provider_name($r, 'provider_name', 'provider_slug')) ?></td>
                    <td data-label="Answered" data-order="<?= $t['answered'] ?>" data-export="<?= $t['answered'] ?> of <?= $count ?>">
                        <div class="progress-cell"><progress class="study-meter" max="<?= max(1, $count) ?>" value="<?= min($t['answered'], max(1, $count)) ?>" aria-label="Questions answered"></progress><span><?= number_format($count > 0 ? min($t['answered'], $count) : $t['answered']) ?> of <?= number_format($count) ?></span></div>
                    </td>
                    <td class="text-end" data-label="Right" data-order="<?= $pct ?? -1 ?>"><?= $pct === null ? '<span class="text-muted">n/a</span>' : $pct . '%' ?></td>
                    <td class="text-end" data-label="To go over" data-order="<?= $t['mistakes'] ?>"><?= number_format($t['mistakes']) ?></td>
                    <td data-label="Last studied" data-order="<?= e($r['last']) ?>"><?= e(fmt_ago($r['last'])) ?><?php if ($r['place'] !== null): ?><div class="cell-sub ms-0"><?= $r['place']['mode'] === 'practice' ? 'Practice' : 'Study' ?>, page <?= (int) $r['place']['page'] ?></div><?php endif; ?></td>
                    <?php if ($own): ?>
                    <td class="text-end stack-actions">
                        <?php if ($r['place'] !== null): ?><a class="btn btn-sm btn-primary" href="<?= e(url($path . '/view', ['mode' => $r['place']['mode'] === 'practice' ? 'practice' : '', 'page' => (string) $r['place']['page']])) ?>"><?= icon('play-fill') ?>Continue</a><?php endif; ?>
                        <?php if ($t['mistakes'] > 0): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($path . '/view', ['mode' => 'practice', 'mistakes' => '1'])) ?>"><?= icon('arrow-repeat') ?>Mistakes</a><?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
