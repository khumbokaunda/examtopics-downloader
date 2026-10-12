<?php /** @var int $status @var string $message */
[$iconName, $heading] = match ($status) {
    404 => ['signpost-split', 'Not found'],
    403 => ['shield-lock', 'Not allowed'],
    409 => ['exclamation-diamond', 'Data problem'],
    default => ['bug', 'Something went wrong'],
};
?>
<div class="card mx-auto mt-4 error-card">
    <div class="card-body">
        <div class="empty-state">
            <?= icon($iconName, 'empty-icon') ?>
            <div class="eyebrow mb-1">Error <?= (int) $status ?></div>
            <h1 class="empty-title"><?= e($heading) ?></h1>
            <p class="empty-body"><?= e($message) ?></p>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/')) ?>"><?= icon('house') ?>Back to the dashboard</a>
        </div>
    </div>
</div>
