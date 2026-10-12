<?php
/**
 * Live job progress. The initial state is rendered here; app.js polls the status URL and
 * updates it. Width is applied by JS (the CSP forbids inline style attributes).
 * @var array<string,mixed> $job
 */
$p = \App\Controllers\JobController::present($job);
?>
<div class="job-progress" data-job-progress data-status-url="<?= e(url('/api/jobs/' . $job['uuid'])) ?>"
     data-final="<?= $p['final'] ? '1' : '0' ?>" data-reload-on-finish="<?= !empty($reload) ? '1' : '0' ?>">
    <div class="job-progress-head">
        <div>
            <span data-field="status"><?= status_badge($job['status']) ?></span>
            <span class="job-phase" data-field="phase"><?= e(str_replace('_', ' ', (string) ($job['phase'] ?? ''))) ?></span>
        </div>
        <div class="job-numbers">
            <span data-field="counts"><?= $p['done'] !== null ? e($p['done'] . ($p['total'] !== null ? ' / ' . $p['total'] : '')) : '' ?></span>
            <span data-field="elapsed" data-elapsed="<?= e((string) ($p['elapsed_seconds'] ?? '')) ?>"></span>
        </div>
    </div>
    <div class="progress" role="progressbar" aria-label="Job progress" aria-valuemin="0" aria-valuemax="100"
         aria-valuenow="<?= e((string) ($p['percent'] ?? 0)) ?>">
        <div class="progress-bar" data-field="bar" data-percent="<?= e($p['percent'] === null ? '' : (string) $p['percent']) ?>"></div>
    </div>
    <div class="job-message" data-field="message"><?= e($p['message'] ?? '') ?></div>
</div>
