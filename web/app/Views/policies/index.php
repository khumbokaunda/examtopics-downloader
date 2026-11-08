<?php /** Public. @var array<string,array> $policies */ ?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h4 mb-3">Policies and notices</h1>
        <ul class="list-unstyled mb-0">
            <?php foreach ($policies as $k => $p): ?>
                <li class="mb-2"><a href="<?= e(url('/policies/' . $k)) ?>"><?= e($p['title']) ?></a> <span class="text-muted small">· version <?= e($p['version']) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
