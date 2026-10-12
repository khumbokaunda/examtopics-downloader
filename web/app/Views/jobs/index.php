<?php /** @var list<array> $jobs @var bool $hasMore @var int $page @var string $status @var string $type @var bool $workerRunning */ ?>
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
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead><tr><th>Job</th><th>Status</th><th>Queued</th><th>Duration</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $j): ?>
                <tr>
                    <td class="stack-title"><a class="table-row-link" href="<?= e(url('/jobs/' . (int) $j['id'])) ?>">#<?= (int) $j['id'] ?></a> <span class="ms-1"><?= e(job_label($j)) ?></span></td>
                    <td data-label="Status"><span class="chip-group"><?= status_badge($j['status']) ?><?= $j['result_status'] === 'partial' ? status_badge('partial') : '' ?></span></td>
                    <td class="small text-muted" data-label="Queued"><?= e(fmt_time($j['queued_at'])) ?></td>
                    <td class="small mono" data-label="Duration"><?= e($j['started_at'] ? fmt_duration($j['started_at'], $j['finished_at']) : 'n/a') ?></td>
                    <td class="small text-muted cell-wrap" data-label="Result"><span class="text-clamp-2"><?= e($j['message'] ?? '') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if ($page > 1 || $hasMore): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted">Page <?= (int) $page ?></span>
        <span class="d-flex gap-2">
            <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/jobs', ['status' => $status, 'type' => $type, 'page' => $page - 1])) ?>"><?= icon('chevron-left') ?>Newer</a><?php endif; ?>
            <?php if ($hasMore): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/jobs', ['status' => $status, 'type' => $type, 'page' => $page + 1])) ?>">Older<?= icon('chevron-right') ?></a><?php endif; ?>
        </span>
    </div>
    <?php endif; ?>
</div>
