<?php /** Public. @var array $policy @var string $key */ ?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h4 mb-1"><?= e($policy['title']) ?></h1>
        <p class="text-muted small mb-3">Version <?= e($policy['version']) ?></p>
        <ul class="mb-3">
            <?php foreach ($policy['points'] as $pt): ?><li class="mb-2"><?= e($pt) ?></li><?php endforeach; ?>
        </ul>
        <a class="small" href="<?= e(url('/policies')) ?>">All policies and notices</a>
    </div>
</div>
