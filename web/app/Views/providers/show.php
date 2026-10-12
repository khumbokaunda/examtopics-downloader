<?php /** @var array $provider @var string $q @var list<array> $exams @var array|null $lastRefresh @var array|null $lastSuccess @var array|null $activeJob */ ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= e(url('/providers')) ?>">Providers</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($provider['name'] ?: $provider['slug']) ?></li>
</ol></nav>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><?= e($provider['name'] ?: $provider['slug']) ?></h1>
        <div class="small text-muted"><code><?= e($provider['slug']) ?></code></div>
    </div>
    <form method="post" action="<?= e(url('/providers/' . rawurlencode($provider['slug']) . '/refresh')) ?>" data-once><?= csrf_field() ?>
        <button class="btn btn-outline-primary" type="submit" <?= $activeJob ? 'disabled' : '' ?>>Refresh inventory</button>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label">Exams with discussions</div><div class="stat-value"><?= count($exams) ?><?= $q !== '' ? '<span class="fs-6 text-muted"> matching</span>' : '' ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label">Last successful refresh</div>
        <div class="stat-value fs-5"><?= e($lastSuccess ? fmt_time($lastSuccess['finished_at']) : 'never') ?></div>
        <?php if ($lastSuccess && $lastSuccess['status'] === 'partial'): ?><div class="small text-warning-emphasis">Partial: <?= (int) $lastSuccess['failed_pages'] ?> of <?= (int) $lastSuccess['listing_pages'] ?> pages failed; counts may be low.</div><?php endif; ?>
    </div></div></div>
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label">Latest refresh status</div>
        <div class="stat-value fs-5"><?= $lastRefresh ? status_badge($lastRefresh['status']) : '<span class="text-muted">n/a</span>' ?></div>
        <?php if ($lastRefresh && $lastRefresh['error']): ?><div class="small text-danger text-truncate-2" title="<?= e($lastRefresh['error']) ?>"><?= e($lastRefresh['error']) ?></div><?php endif; ?>
    </div></div></div>
</div>

<?php if ($activeJob): ?>
<div class="card shadow-sm mb-4"><div class="card-body">
    <div class="fw-semibold mb-2">Inventory refresh in progress (job #<?= (int) $activeJob['id'] ?>)</div>
    <?= \App\Core\View::current()->partial('partials/job_progress', ['job' => $activeJob, 'reload' => true]) ?>
</div></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header">
        <form class="row g-2 align-items-center" method="get" action="<?= e(url('/providers/' . rawurlencode($provider['slug']))) ?>">
            <div class="col"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Filter exams by code or name" data-filter-table="#exam-table" aria-label="Filter exams"></div>
            <div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Search</button></div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="exam-table">
            <thead><tr><th>Exam</th><th class="text-end">Questions (reported)</th><th>Library</th><th></th></tr></thead>
            <tbody>
            <?php if ($exams === []): ?><tr><td colspan="4" class="text-muted p-4">No exams<?= $q !== '' ? ' match this filter' : ' yet. Refresh the inventory to load them' ?>.</td></tr><?php endif; ?>
            <?php foreach ($exams as $ex): ?>
                <tr data-filter-text="<?= e($ex['code'] . ' ' . ($ex['name'] ?? '')) ?>">
                    <td><span class="fw-semibold"><?= e(strtoupper($ex['code'])) ?></span><?php if ($ex['name'] && strtolower($ex['name']) !== $ex['code']): ?> <span class="text-muted small"><?= e($ex['name']) ?></span><?php endif; ?></td>
                    <td class="text-end"><?= $ex['reported_count'] === null ? 'n/a' : (int) $ex['reported_count'] ?></td>
                    <td><?php if ($ex['latest_version']): ?><span class="badge text-bg-success">cached</span> <span class="small text-muted"><?= (int) $ex['latest_collected_count'] ?> · <?= e(fmt_ago($ex['latest_collected_at'])) ?></span><?php else: ?><span class="text-muted small">not collected</span><?php endif; ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/collect', ['provider' => $provider['slug'], 'exam' => $ex['code']])) ?>">Select</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
