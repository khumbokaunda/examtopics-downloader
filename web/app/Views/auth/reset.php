<?php /** @var string $email @var string|null $error */ ?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h3 mb-1">Set a new password</h1>
        <p class="text-muted small mb-4">Type the code sent to <strong><?= e($email) ?></strong> and choose a new password.</p>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger small" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/reset')) ?>" data-once><?= csrf_field() ?>
            <input class="d-none" name="username" value="<?= e($email) ?>" autocomplete="username" readonly tabindex="-1" aria-hidden="true">
            <div class="mb-3"><label class="form-label" for="code">Code</label><input class="form-control form-control-lg code-input" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus></div>
            <div class="mb-3"><label class="form-label" for="password">New password</label><input class="form-control" id="password" name="password" type="password" minlength="12" autocomplete="new-password" required><div class="form-text">At least 12 characters.</div></div>
            <div class="mb-4"><label class="form-label" for="repeat_password">Repeat new password</label><input class="form-control" id="repeat_password" name="repeat_password" type="password" minlength="12" autocomplete="new-password" required></div>
            <button class="btn btn-primary w-100" type="submit"><?= icon('shield-check') ?>Set password</button>
        </form>
        <p class="small text-center text-muted mt-4 mb-0">No code? <a href="<?= e(url('/forgot')) ?>">Send another</a></p>
    </div>
</div>
