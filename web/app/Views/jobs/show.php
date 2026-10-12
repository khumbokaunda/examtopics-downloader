<?php /** @var array $job @var array $options @var string $logTail @var array|null $version */
$active = in_array($job['status'], ['queued', 'running'], true);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/jobs')) ?>">Jobs</a></li>
    <li class="breadcrumb-item active" aria-current="page">#<?= (int) $job['id'] ?></li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e(job_label($job)) ?></h1>
        <p class="page-subtitle">Job #<?= (int) $job['id'] ?> · queued <?= e(fmt_time($job['queued_at'])) ?></p>
        <?php if (!$active): ?><div class="chip-group mt-2"><?= status_badge($job['status']) ?><?= $job['result_status'] === 'partial' ? status_badge('partial') : '' ?></div><?php endif; ?>
    </div>
    <div class="page-actions">
        <?php if ($version): ?>
            <a class="btn btn-primary" href="<?= e(url('/library/' . rawurlencode($version['provider_slug']) . '/' . rawurlencode($version['exam_code']), ['version' => $version['version']])) ?>"><?= icon('journal-bookmark') ?>Open the collected version</a>
        <?php endif; ?>
        <?php if ($active): ?>
            <form method="post" action="<?= e(url('/jobs/' . (int) $job['id'] . '/cancel')) ?>" data-confirm="Previously cached data is not affected." data-confirm-title="Cancel this job?" data-confirm-button="Cancel job" data-confirm-tone="danger"><?= csrf_field() ?>
                <button class="btn btn-outline-danger" type="submit" <?= $job['cancel_requested'] ? 'disabled' : '' ?>><?= icon('stop-circle') ?><?= $job['cancel_requested'] ? 'Cancelling…' : 'Cancel job' ?></button>
            </form>
        <?php endif; ?>
    </div>
</header>

<?php if ($active): ?>
<div class="card mb-4"><div class="card-body">
    <?= \App\Core\View::current()->partial('partials/job_progress', ['job' => $job, 'reload' => true]) ?>
    <p class="form-text mb-0 mt-3"><?= icon('info-circle') ?> You can close this page or lose your connection: the job keeps running on the server. Come back any time to see its status.</p>
</div></div>
<?php endif; ?>

<div class="row g-4">
    <?php if (!$active): ?>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon($job['status'] === 'completed' ? 'check2-circle' : ($job['status'] === 'failed' ? 'x-circle' : 'info-circle')) ?>Result</span></div>
            <div class="card-body">
                <p class="mb-3"><?= e($job['message'] ?? 'n/a') ?></p>
                <?php if ($job['error_summary']): ?>
                    <h2 class="section-heading">Details</h2>
                    <pre class="log-box"><?= e($job['error_summary']) ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="<?= $active ? 'col-12' : 'col-lg-5' ?>">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('list-ul') ?>Details</span></div>
            <div class="card-body">
                <dl class="meta-list">
                    <dt>Queued</dt><dd><?= e(fmt_time($job['queued_at'])) ?></dd>
                    <?php if (!$active): /* while active, the live widget above shows timing and phase */ ?>
                    <dt>Started</dt><dd><?= e(fmt_time($job['started_at'])) ?></dd>
                    <dt>Finished</dt><dd><?= e(fmt_time($job['finished_at'])) ?></dd>
                    <dt>Duration</dt><dd class="mono"><?= e($job['started_at'] ? fmt_duration($job['started_at'], $job['finished_at']) : 'n/a') ?></dd>
                    <dt>Last update</dt><dd><?= e(fmt_time($job['heartbeat_at'])) ?></dd>
                    <dt>Phase</dt><dd><?= e($job['phase'] ? str_replace('_', ' ', (string) $job['phase']) : 'n/a') ?></dd>
                    <dt>Exit code</dt><dd class="mono"><?= $job['exit_code'] === null ? 'n/a' : (int) $job['exit_code'] ?></dd>
                    <?php endif; ?>
                    <?php foreach ($options as $k => $v): if (!is_scalar($v)) { continue; } ?>
                        <dt><?= e(ucfirst(str_replace('_', ' ', (string) $k))) ?></dt><dd><?= e((string) $v) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>
    </div>
    <?php if ($logTail !== ''): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('terminal') ?>Tool output</span><span class="small text-muted">Last 40 lines, credentials redacted</span></div>
            <div class="card-body"><pre class="log-box"><?= e($logTail) ?></pre></div>
        </div>
    </div>
    <?php endif; ?>
</div>
