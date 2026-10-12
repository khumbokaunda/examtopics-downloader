<?php /** @var list<array> $jobs @var bool $capped @var int $limit @var string $status @var string $type @var bool $workerRunning */ ?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Jobs &amp; history</h1>
        <p class="page-subtitle">Every collection, inventory refresh and provider discovery, newest first.</p>
    </div>
    <div class="page-actions"><?= $workerRunning ? chip('Worker running', 'success', 'cpu') : chip('Worker idle', 'neutral', 'cpu') ?></div>
</header>
<div class="card">
    <div class="card-header">
        <form class="toolbar" method="get" action="<?= e(url('/jobs')) ?>">
            <?= icon('funnel', 'text-subtle') ?>
            <select class="form-select form-select-sm" name="type" aria-label="Type" data-autosubmit>
                <option value="">All types</option>
                <?php foreach (['collect' => 'Collections', 'inventory' => 'Inventory refreshes', 'providers' => 'Provider discovery'] as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm" name="status" aria-label="Status" data-autosubmit>
                <option value="">All statuses</option>
                <?php foreach (['queued', 'running', 'completed', 'failed', 'cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($type !== '' || $status !== ''): ?><a class="btn btn-sm btn-link" href="<?= e(url('/jobs')) ?>">Clear</a><?php endif; ?>
        </form>
    </div>
    <?php if ($jobs === []): ?>
        <div class="card-body"><?= empty_state('clock-history', $type !== '' || $status !== '' ? 'No jobs match these filters' : 'No jobs yet', $type !== '' || $status !== '' ? 'Clear the filters to see every job.' : 'Collections and inventory refreshes you start are listed here.', null, true) ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="jobs" data-order='[[0,"desc"]]'>
            <thead><tr><th data-type="num">Job</th><th>Status</th><th>Queued</th><th data-type="num">Duration</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $j): ?>
                <tr>
                    <td class="stack-title" data-order="<?= (int) $j['id'] ?>" data-export="#<?= (int) $j['id'] ?> <?= e(job_label($j)) ?>"><a class="table-row-link" href="<?= e(url('/jobs/' . $j['uuid'])) ?>">#<?= (int) $j['id'] ?></a> <span class="ms-1"><?= e(job_label($j)) ?></span></td>
                    <td data-label="Status"><span class="chip-group"><?= status_badge($j['status']) ?><?= $j['result_status'] === 'partial' ? status_badge('partial') : '' ?></span></td>
                    <td class="small text-muted" data-label="Queued" data-order="<?= e((string) $j['queued_at']) ?>"><?= e(fmt_time($j['queued_at'])) ?></td>
                    <td class="small mono" data-label="Duration" data-order="<?= $j['started_at'] ? max(0, (int) strtotime(($j['finished_at'] ?? gmdate('Y-m-d H:i:s')) . ' UTC') - (int) strtotime($j['started_at'] . ' UTC')) : -1 ?>"><?= e($j['started_at'] ? fmt_duration($j['started_at'], $j['finished_at']) : 'n/a') ?></td>
                    <td class="small text-muted" data-label="Result"><span class="truncate" title="<?= e($j['message'] ?? '') ?>"><?= e($j['message'] ?? '') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if ($capped): ?>
    <div class="card-footer small text-muted"><?= icon('info-circle') ?> Showing the latest <?= number_format($limit) ?> jobs. Filter by type or status to look further back.</div>
    <?php endif; ?>
</div>
