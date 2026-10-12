<?php /** @var string $next @var string|null $error @var string $username @var bool $noUsers */ ?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h3 mb-1">Sign in</h1>
        <p class="text-muted small mb-4">Use the account created on the server.</p>
        <?php if ($noUsers): ?>
            <div class="alert alert-info small"><?= icon('info-circle') ?><div class="alert-body">No user exists yet. On the server, run<br><code>php bin/create-user.php &lt;username&gt;</code></div></div>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger small" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/login')) ?>" data-once>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <input class="form-control" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary w-100" type="submit"><?= icon('box-arrow-in-right') ?>Sign in</button>
        </form>
    </div>
</div>
