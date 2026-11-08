<?php
/** A notice shown before someone agrees to something, with a box to tick. @var string $key @var string $name the checkbox field @var string $institution */
use App\Services\Policies;
$p = Policies::POLICIES[$key];
?>
<div class="notice-box mb-3">
    <p class="fw-semibold small mb-1"><?= e($p['title']) ?> <span class="text-muted fw-normal">· version <?= e($p['version']) ?></span></p>
    <ul class="small mb-2"><?php foreach ($p['points'] as $pt): ?><li><?= e($pt) ?></li><?php endforeach; ?></ul>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="<?= e($name) ?>" name="<?= e($name) ?>" value="1" required>
        <label class="form-check-label small" for="<?= e($name) ?>">I have read this and want to join <?= e($institution) ?>.</label></div>
</div>
