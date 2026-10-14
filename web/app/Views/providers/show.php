<?php /** @var array $provider @var string $q @var list<array> $exams @var array|null $lastRefresh @var array|null $lastSuccess @var array|null $activeJob @var bool $mayRefresh */
$name = provider_name($provider);
$collector = can('exams.collect');
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/providers')) ?>">Providers</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($name) ?></li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e($name) ?></h1>
        <p class="page-subtitle"><code><?= e($provider['slug']) ?></code> · exams with discussions on ExamTopics</p>
    </div>
    <?php if ($mayRefresh): ?>
    <div class="page-actions">
        <form method="post" action="<?= e(url('/providers/' . rawurlencode($provider['slug']) . '/refresh')) ?>" data-once><?= csrf_field() ?>
            <button class="btn btn-outline-primary" type="submit" <?= $activeJob ? 'disabled' : '' ?>><?= icon('arrow-clockwise') ?><?= $collector ? 'Refresh inventory' : 'Load exam list' ?></button>
        </form>
    </div>
    <?php endif; ?>
</header>

<div class="kpi-strip">
    <div class="kpi" data-tone="primary">
        <span class="kpi-icon"><?= icon('collection') ?></span>
        <span class="kpi-text"><span class="kpi-value d-block"><?= number_format(count($exams)) ?></span><span class="kpi-label d-block">Exams with discussions<?= $q !== '' ? ' matching' : '' ?></span></span>
    </div>
    <div class="kpi" data-tone="<?= $lastSuccess ? ($lastSuccess['status'] === 'partial' ? 'warning' : 'success') : 'neutral' ?>">
        <span class="kpi-icon"><?= icon('calendar-check') ?></span>
        <span class="kpi-text"><span class="kpi-value is-small d-block"><?= e($lastSuccess ? fmt_time($lastSuccess['finished_at']) : 'never') ?></span><span class="kpi-label d-block">Last successful refresh</span>
            <?php if ($lastSuccess && $lastSuccess['status'] === 'partial'): ?><span class="kpi-note d-block text-warning">Partial: <?= (int) $lastSuccess['failed_pages'] ?> of <?= (int) $lastSuccess['listing_pages'] ?> pages failed, so counts may be low.</span><?php endif; ?></span>
    </div>
    <div class="kpi" data-tone="<?= $lastRefresh ? status_style($lastRefresh['status'])[0] : 'neutral' ?>">
        <span class="kpi-icon"><?= icon('activity') ?></span>
        <span class="kpi-text"><span class="kpi-value is-small d-block"><?= $lastRefresh ? status_badge($lastRefresh['status']) : '<span class="text-muted">n/a</span>' ?></span><span class="kpi-label d-block">Latest refresh</span>
            <?php if ($lastRefresh && $lastRefresh['error']): ?><details class="error-details kpi-note"><summary class="text-danger"><?= e(error_summary((string) $lastRefresh['error'])) ?></summary><pre class="log-box mt-1"><?= e($lastRefresh['error']) ?></pre></details><?php endif; ?></span>
    </div>
</div>

<?php if ($activeJob): ?>
<div class="card mb-4"><div class="card-body">
    <div class="job-item-title">Inventory refresh in progress (job #<?= (int) $activeJob['id'] ?>)</div>
    <?= \App\Core\View::current()->partial('partials/job_progress', ['job' => $activeJob, 'reload' => true]) ?>
</div></div>
<?php endif; ?>

<div class="card">
    <?php if ($exams === []): ?>
        <div class="card-body"><?= $q !== ''
            ? empty_state('search', 'No exams match this filter', '<a href="' . e(url('/providers/' . rawurlencode($provider['slug']))) . '">Show every exam</a>.', null, true)
            : empty_state('collection', 'No exams yet', $mayRefresh
                ? ($collector ? 'Refresh the inventory to load this provider\'s exams.' : 'Use <strong>Load exam list</strong> to fetch the exams this provider offers. It joins the queue and takes a minute or two.')
                : 'An administrator has not loaded this provider\'s exams yet.', null, true) ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" id="exam-table" data-table="exams" data-order='[[0,"asc"]]'>
            <thead><tr>
                <th>Exam</th>
                <th>Name</th>
                <th class="text-end" data-type="num">Questions (reported)</th>
                <th data-type="num">Library</th>
                <th class="text-end" data-nosort data-novis data-nosearch data-export="false"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($exams as $ex): $hasName = $ex['name'] && strtolower($ex['name']) !== $ex['code']; ?>
                <tr>
                    <td class="stack-title" data-order="<?= e($ex['code']) ?>" data-export="<?= e(strtoupper($ex['code'])) ?>"><span class="fw-semibold"><?= e(strtoupper($ex['code'])) ?></span></td>
                    <td data-label="Name"><span class="truncate text-muted" title="<?= e($hasName ? $ex['name'] : '') ?>"><?= e($hasName ? $ex['name'] : '') ?></span></td>
                    <td class="text-end" data-label="Questions" data-order="<?= $ex['reported_count'] === null ? -1 : (int) $ex['reported_count'] ?>"><?= $ex['reported_count'] === null ? '<span class="text-muted">n/a</span>' : number_format((int) $ex['reported_count']) ?></td>
                    <td data-label="Library" data-order="<?= $ex['latest_version'] ? (int) $ex['latest_collected_count'] : -1 ?>" data-export="<?= $ex['latest_version'] ? 'cached (' . (int) $ex['latest_collected_count'] . ')' : 'not collected' ?>"><span><?php if ($ex['latest_version']): ?><?= chip('cached', 'success', 'check2-circle') ?> <span class="small text-muted"><?= (int) $ex['latest_collected_count'] ?> · <?= e(fmt_ago($ex['latest_collected_at'])) ?></span><?php else: ?><span class="text-muted small">not collected</span><?php endif; ?></span></td>
                    <td class="text-end stack-actions"><?php if ($collector): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/collect', ['provider' => $provider['slug'], 'exam' => $ex['code']])) ?>">Select<?= icon('arrow-right') ?></a><?php elseif ($ex['latest_version']): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($ex['code']))) ?>">Open<?= icon('arrow-right') ?></a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
