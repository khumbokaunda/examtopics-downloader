<?php /** @var array $institution @var array $membership @var string $tab @var array $assessment @var list<array> $rows @var array $summary @var array $stats @var bool $canPublish @var bool $canExport */
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
<div class="card mb-4"><div class="card-body d-flex flex-wrap align-items-center gap-3">
    <div class="flex-grow-1"><?php if ($a['results_published_at'] !== null): ?><?= chip('Results published', 'success', 'megaphone') ?> <span class="small text-muted"><?= e(fmt_time((string) $a['results_published_at'])) ?>. Candidates see <?= e(strtolower(\App\Services\Assessments::REVIEW_POLICIES[$a['review_policy']] ?? '')) ?>.</span>
        <?php else: ?><?= chip('Results not published', 'neutral') ?> <span class="small text-muted">Candidates know only that they handed in.</span><?php endif; ?></div>
    <?php if ($canPublish): ?>
    <form method="post" action="<?= e(url($base . '/results/publication')) ?>"<?= $a['results_published_at'] === null ? ' data-confirm="Every candidate who handed in is told, and sees ' . e(strtolower(\App\Services\Assessments::REVIEW_POLICIES[$a['review_policy']] ?? '')) . '." data-confirm-title="Publish the results?" data-confirm-button="Publish"' : '' ?>><?= csrf_field() ?>
        <?php if ($a['results_published_at'] === null): ?><button class="btn btn-sm btn-success" type="submit" name="action" value="publish"><?= icon('megaphone') ?>Publish results</button>
        <?php else: ?><button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="unpublish"><?= icon('eye-slash') ?>Take back</button><?php endif; ?>
    </form>
    <?php endif; ?>
    <?php if ($canExport && $rows !== []): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/results.csv')) ?>"><?= icon('download') ?>Download CSV</a><?php endif; ?>
</div></div>

<?php if ($stats['count'] > 0): $peak = max(1, max($stats['distribution'])); ?>
<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('bar-chart') ?>Score distribution</span><span class="small text-muted"><?= (int) $stats['count'] ?> sittings</span></div>
            <div class="card-body">
                <div class="score-bars" role="img" aria-label="Sittings per band of 10 percent">
                    <?php foreach ($stats['distribution'] as $band => $n): ?>
                        <div class="score-bar" title="<?= $band * 10 ?> to <?= $band === 9 ? 100 : $band * 10 + 9 ?>%: <?= (int) $n ?>"><span class="score-bar-n"><?= $n > 0 ? (int) $n : '' ?></span><span class="score-bar-fill topic-h-<?= (int) round($n / $peak * 20) * 5 ?>"></span><span class="score-bar-label"><?= $band * 10 ?></span></div>
                    <?php endforeach; ?>
                </div>
                <table class="visually-hidden"><caption>Sittings per band</caption><?php foreach ($stats['distribution'] as $band => $n): ?><tr><th><?= $band * 10 ?>%</th><td><?= (int) $n ?></td></tr><?php endforeach; ?></table>
            </div>
        </div>
    </div>
    <div class="col-xl-6"><?= \App\Core\View::current()->partial('sitting/_topics', ['topics' => $stats['topics']]) ?></div>
</div>
<div class="card mb-4">
    <div class="card-header"><span class="card-title"><?= icon('question-circle') ?>Questions</span><span class="small text-muted">Hardest first. Flags are prompts to look again, not proof a question is wrong.</span></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack" data-table="assessment-questions" data-order='[[2,"asc"]]'>
            <thead><tr><th>Question</th><th data-type="num">Given</th><th data-type="num">Right</th><th data-type="num">Unanswered</th><th>Wrong choice picked most</th></tr></thead>
            <tbody>
            <?php foreach ($stats['questions'] as $q): ?>
                <tr>
                    <td class="stack-title"><?= e(\App\Services\QuestionBanks::excerpt($q['text'], 110)) ?> <?= $q['flag'] === 'hard' ? chip('Very hard', 'danger') : ($q['flag'] === 'easy' ? chip('Very easy', 'info') : '') ?></td>
                    <td class="small" data-label="Given"><?= (int) $q['shown'] ?></td>
                    <td class="small" data-label="Right" data-order="<?= e((string) $q['percent']) ?>"><?= e(C::marks($q['percent'])) ?>%</td>
                    <td class="small" data-label="Unanswered"><?= (int) $q['unanswered'] ?></td>
                    <td class="small" data-label="Wrong choice picked most"><?= $q['distractor'] !== null ? e(\App\Services\QuestionBanks::excerpt((string) $q['distractor']['text'], 60)) . ' <span class="text-muted">(' . (int) $q['distractor']['count'] . ')</span>' : '<span class="text-muted">none</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('table') ?>Sittings</span></div>
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
