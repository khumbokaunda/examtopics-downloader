<?php
/** @var array{exams:int,questions:int} $totals @var int $providerCount @var list<array> $active
 *  @var list<array> $recentVersions @var list<array> $recentCompleted @var list<array> $recentFailed
 *  @var list<array> $recentDocuments @var array<string,int> $jobCounts @var bool $workerRunning */
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">Dashboard</h1>
        <div class="text-muted small">Your private exam library at a glance.</div>
    </div>
    <a class="btn btn-primary" href="<?= e(url('/collect')) ?>">Collect an exam</a>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Cached exams', number_format($totals['exams']), '/library'],
        ['Cached questions', number_format($totals['questions']), '/library'],
        ['Providers', number_format($providerCount), '/providers'],
        ['Failed jobs', number_format($jobCounts['failed'] ?? 0), '/jobs?status=failed'],
    ] as [$label, $value, $href]): ?>
        <div class="col-6 col-lg-3">
            <a class="card stat-card h-100 text-decoration-none" href="<?= e(url(explode('?', $href)[0], str_contains($href, '?') ? ['status' => 'failed'] : [])) ?>">
                <div class="card-body">
                    <div class="stat-label"><?= e($label) ?></div>
                    <div class="stat-value"><?= e($value) ?></div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Active jobs</span>
        <span class="small text-muted">Worker: <?= $workerRunning ? '<span class="text-success">running</span>' : 'idle' ?></span>
    </div>
    <div class="card-body">
        <?php if ($active === []): ?>
            <p class="text-muted mb-0">Nothing is running. Collections and inventory refreshes appear here while they run, and keep running if you close the page.</p>
        <?php else: foreach ($active as $job): ?>
            <div class="mb-3">
                <div class="d-flex justify-content-between"><a href="<?= e(url('/jobs/' . (int) $job['id'])) ?>" class="fw-semibold">#<?= (int) $job['id'] ?> · <?= e(job_label($job)) ?></a></div>
                <?= \App\Core\View::current()->partial('partials/job_progress', ['job' => $job, 'reload' => true]) ?>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Recently collected</div>
            <ul class="list-group list-group-flush">
                <?php if ($recentVersions === []): ?><li class="list-group-item text-muted">No exams collected yet.</li><?php endif; ?>
                <?php foreach ($recentVersions as $v): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <a href="<?= e(url('/library/' . rawurlencode($v['provider_slug']) . '/' . rawurlencode($v['exam_code']))) ?>" class="fw-semibold"><?= e(strtoupper($v['exam_code'])) ?></a>
                            <span class="text-muted small"><?= e($v['provider_slug']) ?> · <?= (int) $v['collected_count'] ?> questions</span>
                        </div>
                        <div class="text-end small"><?= status_badge($v['status']) ?><div class="text-muted"><?= e(fmt_ago($v['published_at'])) ?></div></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Recent jobs</div>
            <ul class="list-group list-group-flush">
                <?php $recent = array_merge($recentCompleted, $recentFailed);
                usort($recent, static fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);
                if ($recent === []): ?><li class="list-group-item text-muted">No finished jobs yet.</li><?php endif; ?>
                <?php foreach (array_slice($recent, 0, 8) as $j): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div><a href="<?= e(url('/jobs/' . (int) $j['id'])) ?>">#<?= (int) $j['id'] ?> <?= e(job_label($j)) ?></a>
                            <div class="small text-muted text-truncate-2"><?= e($j['message'] ?? '') ?></div></div>
                        <div class="text-end small"><?= status_badge($j['status']) ?><div class="text-muted"><?= e(fmt_ago($j['finished_at'])) ?></div></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Recently generated documents</div>
            <ul class="list-group list-group-flush">
                <?php if ($recentDocuments === []): ?><li class="list-group-item text-muted">No documents generated yet.</li><?php endif; ?>
                <?php foreach ($recentDocuments as $d): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div><span class="badge text-bg-light border me-2"><?= e(strtoupper($d['format'])) ?></span><?= e($d['filename']) ?>
                            <span class="small text-muted ms-2"><?= e(fmt_bytes($d['size_bytes'])) ?></span></div>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/documents/' . (int) $d['id'] . '/download')) ?>">Download</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
