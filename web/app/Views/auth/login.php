<?php /** @var string $next @var string|null $error @var string $username @var bool $noUsers @var bool $canRegister @var bool $canReset @var bool $restored */ ?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h3 mb-1">Sign in</h1>
        <p class="text-muted small mb-4">With your username or email address.</p>
        <?php if ($noUsers): ?>
            <div class="alert alert-info small"><?= icon('info-circle') ?><div class="alert-body">No user exists yet. On the server, run<br><code>php bin/create-user.php &lt;username&gt;</code><br>or, to move an existing system here, restore its backup:<br><code>php bin/backup.php restore &lt;file.zip&gt;</code></div></div>
        <?php endif; ?>
        <?php if ($restored ?? false): ?>
            <div class="alert alert-success small"><?= icon('check-circle') ?><div class="alert-body">The system was restored from a backup. Sign in with an account from the backup; the details are on the Backups page.</div></div>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger small" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/login')) ?>" data-once>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
            <div class="mb-3">
                <label class="form-label" for="username">Username or email</label>
                <input class="form-control" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-baseline"><label class="form-label" for="password">Password</label><?php if ($canReset): ?><a class="small" href="<?= e(url('/forgot')) ?>">Forgot it?</a><?php endif; ?></div>
                <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary w-100" type="submit"><?= icon('box-arrow-in-right') ?>Sign in</button>
        </form>
        <?php if ($canRegister): ?>
            <p class="small text-center text-muted mt-4 mb-0">New here? <a href="<?= e(url('/register')) ?>">Create an account</a></p>
        <?php endif; ?>
    </div>
</div>
