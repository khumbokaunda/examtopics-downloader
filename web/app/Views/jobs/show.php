<?php /** @var array $job @var array $options @var string $logTail @var array|null $version */
$active = in_array($job['status'], ['queued', 'running'], true);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= e(url('/jobs')) ?>">Jobs</a></li>
    <li class="breadcrumb-item active" aria-current="page">#<?= (int) $job['id'] ?></li>
</ol></nav>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1">Job #<?= (int) $job['id'] ?> · <?= e(job_label($job)) ?></h1>
        <?php if (!$active): ?><div><?= status_badge($job['status']) ?><?= $job['result_status'] === 'partial' ? ' ' . status_badge('partial') : '' ?></div><?php endif; ?>
    </div>
    <?php if ($active): ?>
        <form method="post" action="<?= e(url('/jobs/' . (int) $job['id'] . '/cancel')) ?>" data-confirm="Cancel this job? Previously cached data is not affected."><?= csrf_field() ?>
            <button class="btn btn-outline-danger" type="submit" <?= $job['cancel_requested'] ? 'disabled' : '' ?>><?= $job['cancel_requested'] ? 'Cancelling…' : 'Cancel job' ?></button>
        </form>
    <?php endif; ?>
</div>

<?php if ($active): ?>
<div class="card shadow-sm mb-4"><div class="card-body">
    <?= \App\Core\View::current()->partial('partials/job_progress', ['job' => $job, 'reload' => true]) ?>
    <p class="small text-muted mb-0 mt-2">You can close this page or lose your connection: the job keeps running on the server. Come back any time to see its status.</p>
</div></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100"><div class="card-body">
            <h2 class="h6">Details</h2>
            <dl class="row small mb-0">
                <dt class="col-5">Queued</dt><dd class="col-7"><?= e(fmt_time($job['queued_at'])) ?></dd>
                <?php if (!$active): /* while active, the live widget above shows timing and phase */ ?>
                <dt class="col-5">Started</dt><dd class="col-7"><?= e(fmt_time($job['started_at'])) ?></dd>
                <dt class="col-5">Finished</dt><dd class="col-7"><?= e(fmt_time($job['finished_at'])) ?></dd>
                <dt class="col-5">Duration</dt><dd class="col-7"><?= e($job['started_at'] ? fmt_duration($job['started_at'], $job['finished_at']) : 'n/a') ?></dd>
                <dt class="col-5">Last update</dt><dd class="col-7"><?= e(fmt_time($job['heartbeat_at'])) ?></dd>
                <dt class="col-5">Phase</dt><dd class="col-7"><?= e($job['phase'] ?? 'n/a') ?></dd>
                <dt class="col-5">Exit code</dt><dd class="col-7"><?= $job['exit_code'] === null ? 'n/a' : (int) $job['exit_code'] ?></dd>
                <?php endif; ?>
                <?php foreach ($options as $k => $v): if (!is_scalar($v)) { continue; } ?>
                    <dt class="col-5"><?= e(str_replace('_', ' ', (string) $k)) ?></dt><dd class="col-7"><?= e((string) $v) ?></dd>
                <?php endforeach; ?>
            </dl>
            <?php if ($version): ?>
                <a class="btn btn-sm btn-primary mt-3" href="<?= e(url('/library/' . rawurlencode($version['provider_slug']) . '/' . rawurlencode($version['exam_code']), ['version' => $version['version']])) ?>">Open the collected version</a>
            <?php endif; ?>
        </div></div>
    </div>
    <?php if (!$active): ?>
    <div class="col-lg-7">
        <div class="card shadow-sm h-100"><div class="card-body">
            <h2 class="h6">Result</h2>
            <p class="mb-2"><?= e($job['message'] ?? 'n/a') ?></p>
            <?php if ($job['error_summary']): ?>
                <pre class="log-box mb-0"><?= e($job['error_summary']) ?></pre>
            <?php endif; ?>
        </div></div>
    </div>
    <?php endif; ?>
    <?php if ($logTail !== ''): ?>
    <div class="col-12">
        <div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Tool output (last 40 lines, credentials redacted)</h2>
            <pre class="log-box mb-0"><?= e($logTail) ?></pre>
        </div></div>
    </div>
    <?php endif; ?>
</div>
