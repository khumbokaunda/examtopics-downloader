<?php /** @var string|null $error @var bool $available */ ?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h3 mb-1">Forgot your password</h1>
        <?php if (!$available): ?>
            <p class="text-muted small">Email is not set up on this server, so a code cannot be sent. Ask an administrator to set a new password for you.</p>
            <a class="btn btn-outline-secondary w-100" href="<?= e(url('/login')) ?>">Back to sign in</a>
        <?php else: ?>
        <p class="text-muted small mb-4">Type the email address of your account and we send a code to set a new password.</p>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger small" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/forgot')) ?>" data-once data-busy="Sending…"><?= csrf_field() ?>
            <div class="mb-4"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" name="email" type="email" autocomplete="email" required autofocus></div>
            <button class="btn btn-primary w-100" type="submit"><?= icon('send') ?>Send a code</button>
        </form>
        <p class="small text-center text-muted mt-4 mb-0"><a href="<?= e(url('/login')) ?>">Back to sign in</a></p>
        <?php endif; ?>
    </div>
</div>
