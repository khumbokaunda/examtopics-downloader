<?php /** By topic, weakest first. @var list<array{topic:string,questions:int,correct:int,marks:float,awarded:float,percent:float}> $topics */
if ($topics === []) { return; } ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('bar-chart') ?>By topic</span><span class="small text-muted">Weakest first</span></div>
    <ul class="list-rows">
        <?php foreach ($topics as $t): $tone = $t['percent'] >= 70 ? 'success' : ($t['percent'] >= 50 ? 'warning' : 'danger'); ?>
            <li class="request-row"><div class="row-main"><div class="row-text">
                <div class="row-title"><?= e($t['topic']) ?> <span class="text-muted fw-normal small">· <?= (int) $t['correct'] ?> of <?= (int) $t['questions'] ?> right</span></div>
                <div class="topic-bar" data-tone="<?= e($tone) ?>"><span class="topic-bar-fill topic-w-<?= (int) round(min(100, max(0, $t['percent'])) / 5) * 5 ?>"></span></div>
            </div></div><span class="small fw-semibold"><?= e(rtrim(rtrim(number_format($t['percent'], 1), '0'), '.')) ?>%</span></li>
        <?php endforeach; ?>
    </ul>
</div>
