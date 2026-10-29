<?php
/**
 * Everything in the library that needs checking, across every exam.
 *
 * @var list<array<string,mixed>> $rows the exams shown, with what needs checking in each
 * @var string $show the filter @var array<string,int> $counts exams per filter
 * @var array{to_check:int,no_answer:int,missing:int,images:int,documents:int,corrections:int,pending:int,updating:int,found:int} $totals
 * @var int $waiting exams being checked in the background @var bool $started whether that check is running
 */
use App\Controllers\IntegrityController;
use App\Services\Editions;

$collector = can('exams.collect');
$plural = static fn(int $n, string $one, string $many): string => number_format($n) . ' ' . ($n === 1 ? $one : $many);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Integrity</h1>
        <p class="page-subtitle"><?= match (true) {
            $counts['all'] === 0 => 'Nothing is in the library yet.',
            $totals['found'] > 0 => $plural($totals['found'], 'exam needs', 'exams need') . ' checking, of ' . number_format($counts['all']) . ' in the library.',
            $totals['pending'] > 0 => 'Nothing found to check so far; the library is still being checked.',
            default => 'All ' . $plural($counts['all'], 'exam looks', 'exams look') . ' right.',
        } ?></p>
    </div>
</header>

<div class="kpi-strip">
    <?php foreach ([
        ['answers', 'Answers to check', $totals['to_check'], 'exclamation-triangle', 'warning'],
        ['missing', 'Questions missing', $totals['missing'], 'question-diamond', 'danger'],
        ['images', 'Images not saved', $totals['images'], 'image', 'info'],
        ['documents', 'Documents to rebuild', $totals['documents'], 'file-earmark-text', 'primary'],
    ] as [$key, $label, $value, $iconName, $tone]): ?>
        <a class="kpi" href="<?= e(url('/integrity', ['show' => $key])) ?>" data-tone="<?= e($value > 0 ? $tone : 'neutral') ?>">
            <span class="kpi-icon"><?= icon($iconName) ?></span>
            <span class="kpi-text"><span class="kpi-value d-block"><?= number_format($value) ?></span><span class="kpi-label d-block"><?= e($label) ?></span></span>
        </a>
    <?php endforeach; ?>
</div>

<nav class="filter-chips" aria-label="Show">
    <?php foreach (IntegrityController::FILTERS as $key => $label): $on = $show === $key;
        if (!$on && in_array($key, IntegrityController::RARE, true) && ($counts[$key] ?? 0) === 0) { continue; } ?>
        <a class="filter-chip<?= $on ? ' is-active' : '' ?>" href="<?= e(url('/integrity', ['show' => $key === 'attention' ? '' : $key])) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="filter-chip-count"><?= number_format($counts[$key] ?? 0) ?></span></a>
    <?php endforeach; ?>
</nav>

<?php if ($waiting > 0 && $started): ?>
    <div class="alert alert-info small" role="status" data-integrity-wait="<?= e(url('/api/integrity')) ?>"><?= icon('hourglass-split') ?><div class="alert-body" data-field="waiting"><?= e(IntegrityController::waitingLine($waiting)) ?></div></div>
<?php elseif ($waiting > 0): ?>
    <div class="alert alert-warning small"><?= icon('exclamation-triangle') ?><div class="alert-body"><?= $plural($waiting, 'exam', 'exams') ?> could not be checked: the server did not start the background check. The log is in storage/logs/integrity.log; <span class="mono">php bin/integrity.php</span> runs it by hand.</div></div>
<?php endif; ?>

<div class="card">
    <?php if ($rows === []): ?>
        <div class="card-body"><?= $counts['all'] === 0
            ? empty_state('journal-bookmark', 'Nothing to check yet', 'Exams appear here once they are collected.', $collector ? ['Collect an exam', url('/collect')] : null)
            : empty_state('check2-all', 'Nothing to show here', $show === 'attention' ? 'Every exam looks right: no doubtful answers, nothing missing and nothing to rebuild.' : 'No exam has this problem.', ['Show all exams', url('/integrity', ['show' => 'all'])], true) ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" id="integrity-table" data-table="integrity" data-order='[]'>
            <thead><tr>
                <th>Exam</th><th data-type="num">Answers to check</th><th data-type="num">Questions</th><th data-type="num">Images</th>
                <th data-type="num">Documents</th><th>Corrections</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $x = $r['exam']; $v = $r['version'];
                $base = '/library/' . rawurlencode((string) $x['provider_slug']) . '/' . rawurlencode((string) $x['code']);
                $done = array_filter([$r['corrected'] > 0 ? $r['corrected'] . ' corrected' : null, $r['checked'] > 0 ? $r['checked'] . ' checked' : null,
                    $r['edited'] > 0 ? $r['edited'] . ' edited' : null]); ?>
                <tr>
                    <td class="stack-title" data-order="<?= e(Editions::title($x)) ?>" data-search="<?= e($x['code'] . ' ' . ($x['name'] ?? '') . ' ' . provider_name($x, 'provider_name', 'provider_slug')) ?>">
                        <a class="table-row-link" href="<?= e(url($base, ['version' => $v['version']])) ?>" title="<?= e(strtoupper((string) $x['code'])) ?>"><?= e(Editions::shortCode((string) $x['code'])) ?></a>
                        <div class="cell-sub ms-0 cell-wrap text-clamp-2"><?= e(provider_name($x, 'provider_name', 'provider_slug')) ?><?= Editions::title($x) !== Editions::shortCode((string) $x['code']) ? ' · ' . e(Editions::title($x)) : '' ?></div>
                        <?php if ($r['state'] === 'pending'): ?><div class="mt-1"><?= chip('being checked', 'neutral', 'hourglass-split') ?></div>
                        <?php elseif ($r['updating']): ?><div class="mt-1" title="Changed since these figures were worked out; they are being worked out again"><?= chip('updating', 'neutral', 'arrow-repeat') ?></div>
                        <?php elseif ($r['state'] === 'error'): ?>
                            <div class="mt-1"><?= chip('data unreadable', 'danger', 'x-octagon') ?></div>
                            <div class="cell-sub ms-0 cell-wrap"><?= e((string) $r['error']) ?><?php if ($collector): ?> <a href="<?= e(url('/collect', ['provider' => $x['provider_slug'], 'exam' => $x['code']])) ?>">Collect again</a><?php endif; ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Answers to check" data-order="<?= (int) $r['to_check'] ?>">
                        <?php if ($r['to_check'] > 0): ?>
                            <a class="btn btn-sm btn-outline-warning" href="<?= e(url($base . '/view', ['version' => $v['version'], 'review' => '1'])) ?>"><?= icon('exclamation-triangle') ?>Check <?= number_format($r['to_check']) ?></a>
                            <?php if ($r['no_answer'] > 0): ?><div class="cell-sub ms-0"><?= $plural($r['no_answer'], 'has', 'have') ?> no answer</div><?php endif; ?>
                        <?php elseif ($r['state'] === 'ok'): ?><span class="text-muted">None</span><?php endif; ?>
                    </td>
                    <td data-label="Questions" data-order="<?= (int) $r['missing'] ?>">
                        <?= number_format($r['questions']) ?><?php if ($r['missing'] > 0): ?> <span class="text-muted">of <?= number_format((int) $r['reported']) ?></span><?php endif; ?>
                        <?php if ($r['missing'] > 0): ?>
                            <div class="cell-sub ms-0 text-danger"><?= number_format($r['missing']) ?> missing<?php if ($collector): ?> · <a href="<?= e(url('/collect', ['provider' => $x['provider_slug'], 'exam' => $x['code']])) ?>">Collect again</a><?php endif; ?></div>
                        <?php endif; ?>
                        <?php if ($r['partial']): ?><div class="mt-1"><?= chip('partly collected', 'warning', 'exclamation-triangle') ?></div><?php endif; ?>
                    </td>
                    <td data-label="Images" data-order="<?= (int) $r['images_to_fetch'] ?>">
                        <?php if ($r['images'] === null): ?>
                        <?php elseif ($r['images']['total'] === 0): ?><span class="text-muted">None</span>
                        <?php elseif ($r['images_to_fetch'] === 0): ?><?= number_format($r['images']['saved']) ?> saved
                        <?php else: ?>
                            <?= number_format($r['images_to_fetch']) ?> not saved
                            <?php if ($collector): ?>
                                <form class="mt-1" method="post" action="<?= e(url($base . '/' . rawurlencode((string) $v['version']) . '/images')) ?>" data-job-form><?= csrf_field() ?>
                                    <input type="hidden" name="back" value="<?= e('/integrity' . ($show !== 'attention' ? '?show=' . $show : '')) ?>">
                                    <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon('images') ?>Download</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Documents" data-order="<?= (int) $r['stale_documents'] ?>">
                        <?php if ($r['stale_documents'] > 0): ?>
                            <a href="<?= e(url($base, ['version' => $v['version']])) ?>"><?= $plural($r['stale_documents'], 'to rebuild', 'to rebuild') ?></a>
                        <?php elseif ($r['state'] === 'ok'): ?><span class="text-muted">Up to date</span><?php endif; ?>
                    </td>
                    <td data-label="Corrections" data-order="<?= $r['corrected'] + $r['checked'] + $r['edited'] ?>">
                        <?php if ($done !== []): ?><a href="<?= e(url($base . '/corrections', ['version' => $v['version']])) ?>"><?= e(implode(', ', $done)) ?></a>
                        <?php elseif ($r['state'] === 'ok'): ?><span class="text-muted">None</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
