<?php
/**
 * A small live status for a job, shown where the button that started it was: "In the queue",
 * "Running, 54%", then "Done" or "Failed". It links to the job for those who may open jobs;
 * app.js keeps it up to date (and builds the same thing when a form starts a job in place).
 *
 * @var array<string,mixed> $job a jobs row @var bool $block full width
 */
$p = \App\Controllers\JobController::present($job);
$link = can('exams.collect');
$tag = $link ? 'a' : 'span';
?>
<<?= $tag ?> class="job-chip<?= !empty($block) ? ' job-chip--block' : '' ?>" data-job-chip data-status="<?= e($p['status']) ?>" data-status-url="<?= e(url('/api/jobs/' . $p['uuid'])) ?>"<?= $link ? ' href="' . e(url('/jobs/' . $p['uuid'])) . '" title="Open the job to follow it"' : '' ?>>
    <span class="job-chip-icon" data-field="icon"><?= icon($p['chip']['icon'], $p['status'] === 'running' ? 'spin' : '') ?></span>
    <span class="job-chip-text" data-field="text"><?= e($p['chip']['text']) ?></span>
    <?php if ($link): ?><span class="job-chip-go">View<?= icon('arrow-right-short') ?></span><?php endif; ?>
</<?= $tag ?>>
