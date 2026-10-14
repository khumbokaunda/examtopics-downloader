<?php /** @var string $email @var string|null $error */ ?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h3 mb-1">Verify your email</h1>
        <p class="text-muted small mb-4">Type the six-digit code we sent to <strong><?= e($email) ?></strong>. If it is not there, look in the spam folder.</p>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger small" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/verify')) ?>" data-once><?= csrf_field() ?>
            <div class="mb-4"><label class="form-label" for="code">Code</label><input class="form-control form-control-lg code-input" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus></div>
            <button class="btn btn-primary w-100" type="submit"><?= icon('check2-circle') ?>Verify</button>
        </form>
        <form method="post" action="<?= e(url('/verify/resend')) ?>" class="mt-3 text-center" data-once><?= csrf_field() ?>
            <button class="btn btn-link btn-sm" type="submit"><?= icon('arrow-repeat') ?>Send a new code</button>
        </form>
        <p class="small text-center text-muted mt-2 mb-0">Wrong address? <a href="<?= e(url('/register')) ?>">Start again</a></p>
    </div>
</div>
