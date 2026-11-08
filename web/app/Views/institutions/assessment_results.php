<?php /** @var array $institution @var array $membership @var string $tab @var array $assessment @var list<array> $rows @var array $summary */
use App\Controllers\InstitutionAssessmentController as C;
use App\Services\Access;
use App\Services\InstitutionStructure;
use App\Services\Sittings;
$a = $assessment;
$base = '/institutions/' . $institution['uuid'] . '/assessments/' . $a['uuid'];
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Assessments' => '/institutions/' . $institution['uuid'] . '/assessments', $a['title'] => $base, 'Results' => null]]) ?>
<div class="kpi-strip">
    <?php foreach ([
        ['Handed in', $summary['sat'] . ' of ' . $summary['candidates'], 'check2-square', 'primary'],
        ['Sitting now', (string) $summary['in_progress'], 'play-circle', $summary['in_progress'] > 0 ? 'warning' : 'neutral'],
        ['Average', $summary['average'] !== null ? $summary['average'] . '%' : 'n/a', 'graph-up', 'info'],
        ...($summary['passed'] !== null ? [['Passed', $summary['passed'] . ' of ' . $summary['sat'], 'patch-check', 'success']] : []),
    ] as [$label, $value, $iconName, $kt]): ?>
        <div class="kpi" data-tone="<?= e($kt) ?>"><span class="kpi-icon"><?= icon($iconName) ?></span>
            <span class="kpi-text"><span class="kpi-value d-block"><?= e($value) ?></span><span class="kpi-label d-block"><?= e($label) ?></span></span></div>
    <?php endforeach; ?>
</div>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('table') ?>Sittings</span><span class="small text-muted">Results are kept here; candidates see theirs once results are published.</span></div>
    <?php if ($rows === []): ?>
        <div class="card-body"><?= empty_state('table', 'Nobody has sat it yet', 'Sittings show here as candidates start them.', null, true) ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="assessment-results" data-order='[[0,"asc"]]'>
            <thead><tr><th>Candidate</th><th>Class</th><th data-type="num">Attempt</th><th>Status</th><th data-type="num">Score</th><th data-type="num">%</th><th data-type="num">Started</th><th data-type="num">Time taken</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $live = $r['status'] === 'in_progress'; ?>
                <tr>
                    <td class="stack-title" data-order="<?= e(strtolower(Access::displayName($r))) ?>"><a class="table-row-link" href="<?= e(url($base . '/results/' . $r['uuid'])) ?>"><?= e(Access::displayName($r)) ?></a>
                        <?php if ($r['student_number'] !== null): ?><div class="small text-muted mono"><?= e($r['student_number']) ?></div><?php endif; ?></td>
                    <td class="small" data-label="Class"><?= $r['class_name'] !== null ? e(InstitutionStructure::label((string) $r['class_name'], (string) $r['intake'])) : '<span class="text-muted">named</span>' ?></td>
                    <td class="small" data-label="Attempt"><?= (int) $r['attempt'] ?></td>
                    <td data-label="Status"><?= chip(Sittings::STATUSES[$r['status']] ?? $r['status'], $live ? 'warning' : 'success') ?><?php if ($live): ?> <span class="small text-muted"><?= (int) $r['answered'] ?> of <?= (int) $r['question_count'] ?> answered</span><?php endif; ?></td>
                    <td class="small" data-label="Score" data-order="<?= e((string) ($r['score'] ?? -1)) ?>"><?= $live ? '' : e(C::marks($r['score'])) . ' / ' . e(C::marks($r['max_score'])) ?></td>
                    <td data-label="%" data-order="<?= e((string) ($r['percent'] ?? -1)) ?>"><?= $live ? '' : e(C::marks($r['percent'])) . '%' ?> <?= $r['passed'] !== null && !$live ? chip((int) $r['passed'] ? 'Pass' : 'Not passed', (int) $r['passed'] ? 'success' : 'danger') : '' ?></td>
                    <td class="small" data-label="Started" data-order="<?= e((string) $r['started_at']) ?>"><?= e(fmt_time((string) $r['started_at'])) ?></td>
                    <td class="small" data-label="Time taken" data-order="<?= $r['submitted_at'] !== null ? strtotime((string) $r['submitted_at']) - strtotime((string) $r['started_at']) : 0 ?>"><?= $r['submitted_at'] !== null ? e(fmt_duration((string) $r['started_at'], (string) $r['submitted_at'])) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
