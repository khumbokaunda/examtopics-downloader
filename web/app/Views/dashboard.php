<?php
/** @var array{exams:int,questions:int} $totals @var int $providerCount @var list<array> $active
 *  @var list<array> $recentVersions @var list<array> $recentCompleted @var list<array> $recentFailed
 *  @var list<array> $recentDocuments @var array<string,int> $jobCounts @var bool $workerRunning */
$failed = (int) ($jobCounts['failed'] ?? 0);
$recent = array_merge($recentCompleted, $recentFailed);
usort($recent, static fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">Your private exam library at a glance.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= e(url('/library')) ?>"><?= icon('journal-bookmark') ?>Open library</a>
        <a class="btn btn-primary" href="<?= e(url('/collect')) ?>"><?= icon('cloud-arrow-down') ?>Collect an exam</a>
    </div>
</header>

<div class="kpi-strip">
    <?php foreach ([
        ['Cached exams', number_format($totals['exams']), url('/library'), 'journal-bookmark', 'primary'],
        ['Cached questions', number_format($totals['questions']), url('/library'), 'patch-question', 'info'],
        ['Providers', number_format($providerCount), url('/providers'), 'buildings', 'neutral'],
        ['Failed jobs', number_format($failed), url('/jobs', ['status' => 'failed']), 'x-circle', $failed > 0 ? 'danger' : 'neutral'],
    ] as [$label, $value, $href, $iconName, $tone]): ?>
        <a class="kpi" href="<?= e($href) ?>" data-tone="<?= e($tone) ?>">
            <span class="kpi-icon"><?= icon($iconName) ?></span>
            <span class="kpi-text"><span class="kpi-value d-block"><?= e($value) ?></span><span class="kpi-label d-block"><?= e($label) ?></span></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="card mb-4">
    <div class="card-header">
        <span class="card-title"><?= icon('activity') ?>Active jobs</span>
        <?= $workerRunning ? chip('Worker running', 'success', 'cpu') : chip('Worker idle', 'neutral', 'cpu') ?>
    </div>
    <div class="card-body">
        <?php if ($active === []): ?>
            <?= empty_state('cup-hot', 'Nothing is running', 'Collections and inventory refreshes show here while they run. They keep running if you close the page.', null, true) ?>
        <?php else: foreach ($active as $job): ?>
            <div class="job-item">
                <div class="job-item-title"><a href="<?= e(url('/jobs/' . $job['uuid'])) ?>">#<?= (int) $job['id'] ?> · <?= e(job_label($job)) ?></a></div>
                <?= \App\Core\View::current()->partial('partials/job_progress', ['job' => $job, 'reload' => true]) ?>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <span class="card-title"><?= icon('journal-check') ?>Recently collected</span>
                <a class="small" href="<?= e(url('/library')) ?>">View library</a>
            </div>
            <?php if ($recentVersions === []): ?>
                <div class="card-body"><?= empty_state('journal-plus', 'No exams collected yet', 'Pick a provider and an exam to build your library.', ['Collect an exam', url('/collect')], true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($recentVersions as $v): ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="primary"><?= icon('journal-text') ?></span>
                            <div class="row-text">
                                <a class="row-title" href="<?= e(url('/library/' . rawurlencode($v['provider_slug']) . '/' . rawurlencode($v['exam_code']))) ?>"><?= e(strtoupper($v['exam_code'])) ?></a>
                                <div class="row-meta"><?= e($v['provider_slug']) ?> · <?= number_format((int) $v['collected_count']) ?> questions</div>
                            </div>
                        </div>
                        <div class="row-side"><?= status_badge($v['status']) ?><span class="row-time"><?= e(fmt_ago($v['published_at'])) ?></span></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <span class="card-title"><?= icon('clock-history') ?>Recent jobs</span>
                <a class="small" href="<?= e(url('/jobs')) ?>">All jobs</a>
            </div>
            <?php if ($recent === []): ?>
                <div class="card-body"><?= empty_state('clock', 'No finished jobs yet', 'Finished collections and inventory refreshes are listed here.', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach (array_slice($recent, 0, 6) as $j): [$tone, $iconName] = status_style($j['status']); ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="<?= e($tone) ?>"><?= icon($iconName) ?></span>
                            <div class="row-text">
                                <a class="row-title" href="<?= e(url('/jobs/' . $j['uuid'])) ?>">#<?= (int) $j['id'] ?> <?= e(job_label($j)) ?></a>
                                <div class="row-meta text-clamp-2"><?= e($j['message'] ?? '') ?></div>
                            </div>
                        </div>
                        <div class="row-side"><?= status_badge($j['status']) ?><span class="row-time"><?= e(fmt_ago($j['finished_at'])) ?></span></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('file-earmark-text') ?>Recently generated documents</span></div>
            <?php if ($recentDocuments === []): ?>
                <div class="card-body"><?= empty_state('file-earmark-plus', 'No documents yet', 'Open an exam in the library to generate Markdown or PDF study documents.', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($recentDocuments as $d): $pdf = $d['format'] === 'pdf'; ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="<?= $pdf ? 'danger' : 'info' ?>"><?= icon($pdf ? 'filetype-pdf' : 'filetype-md') ?></span>
                            <div class="row-text">
                                <div class="row-title text-truncate"><?= e($d['filename']) ?></div>
                                <div class="row-meta"><?= e(strtoupper($d['format'])) ?> · <?= e(fmt_bytes($d['size_bytes'])) ?></div>
                            </div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/documents/' . $d['uuid'] . '/download')) ?>"><?= icon('download') ?>Download</a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
