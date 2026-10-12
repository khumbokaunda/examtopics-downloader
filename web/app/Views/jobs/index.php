<?php /** @var list<array> $jobs @var bool $hasMore @var int $page @var string $status @var string $type @var bool $workerRunning */ ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">Jobs &amp; History</h1>
        <div class="text-muted small">Worker: <?= $workerRunning ? '<span class="text-success">running</span>' : 'idle' ?></div>
    </div>
    <form class="d-flex gap-2" method="get" action="<?= e(url('/jobs')) ?>">
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
    </form>
</div>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>#</th><th>Job</th><th>Status</th><th>Queued</th><th>Duration</th><th>Result</th></tr></thead>
            <tbody>
            <?php if ($jobs === []): ?><tr><td colspan="6" class="text-muted p-4">No jobs.</td></tr><?php endif; ?>
            <?php foreach ($jobs as $j): ?>
                <tr>
                    <td><a href="<?= e(url('/jobs/' . (int) $j['id'])) ?>">#<?= (int) $j['id'] ?></a></td>
                    <td><?= e(job_label($j)) ?></td>
                    <td><?= status_badge($j['status']) ?><?= $j['result_status'] === 'partial' ? ' ' . status_badge('partial') : '' ?></td>
                    <td class="small"><?= e(fmt_time($j['queued_at'])) ?></td>
                    <td class="small"><?= e($j['started_at'] ? fmt_duration($j['started_at'], $j['finished_at']) : 'n/a') ?></td>
                    <td class="small text-truncate-2"><?= e($j['message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<nav class="mt-3 d-flex gap-2">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/jobs', ['status' => $status, 'type' => $type, 'page' => $page - 1])) ?>">Newer</a><?php endif; ?>
    <?php if ($hasMore): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/jobs', ['status' => $status, 'type' => $type, 'page' => $page + 1])) ?>">Older</a><?php endif; ?>
</nav>
