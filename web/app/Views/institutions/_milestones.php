<?php
/** An institution application's progress as milestones. @var array $institution */
use App\Services\Onboarding;
$i = $institution;
$st = (string) $i['status'];
$sent = $st !== 'draft';
$checks = array_slice(Onboarding::verification($i), 0, 4);
$done = count(array_filter($checks, static fn(array $c): bool => in_array($c['state'], ['verified', 'not_applicable'], true)));
$steps = [
    ['Started', fmt_time((string) $i['created_at']), 'done', 'pencil'],
    ['Sent', $sent && $i['submitted_at'] !== null ? fmt_time((string) $i['submitted_at']) : 'Not yet', $sent ? 'done' : 'current', 'send'],
    ['Checks', $done . ' of ' . count($checks), match (true) { $st === 'info_requested' => 'warning', $done === count($checks) => 'done', $sent => 'current', default => 'todo' },
        $st === 'info_requested' ? 'question-lg' : 'shield-check'],
    [match ($st) { 'rejected' => 'Not approved', 'suspended' => 'Suspended', default => 'Approved' }, $i['decided_at'] !== null && in_array($st, ['verified', 'rejected', 'suspended'], true)
        ? fmt_time((string) $i['decided_at']) : 'Waiting', match ($st) { 'verified' => 'done', 'rejected', 'suspended' => 'danger', default => 'todo' },
        match ($st) { 'rejected' => 'x-lg', 'suspended' => 'slash-circle', default => 'patch-check' }],
];
?>
<ol class="milestones" aria-label="Application progress">
    <?php foreach ($steps as [$label, $meta, $state, $ic]): ?>
        <li class="milestone is-<?= e($state) ?>"><span class="milestone-dot"><?= icon($ic) ?></span>
            <span class="milestone-label"><?= e($label) ?></span><span class="milestone-meta"><?= e($meta) ?></span></li>
    <?php endforeach; ?>
</ol>
<div class="milestone-checks">
    <?php foreach ($checks as $c): ?><?= chip($c['label'] . ': ' . strtolower($c['text']), $c['tone']) ?><?php endforeach; ?>
</div>
