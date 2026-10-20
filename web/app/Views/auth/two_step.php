<?php /** @var string|null $error @var bool $restart */ ?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h3 mb-1">Two-step sign-in</h1>
        <p class="text-muted small mb-4">Open your authenticator app and type the six-digit code it shows for this site.</p>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger small" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div>
        <?php endif; ?>
        <?php if ($restart): ?>
            <a class="btn btn-primary w-100" href="<?= e(url('/login')) ?>"><?= icon('arrow-left') ?>Back to sign in</a>
        <?php else: ?>
            <form method="post" action="<?= e(url('/login/two-step')) ?>" data-once><?= csrf_field() ?>
                <div class="mb-4"><label class="form-label" for="code">Code</label><input class="form-control form-control-lg code-input" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus></div>
                <button class="btn btn-primary w-100" type="submit"><?= icon('shield-check') ?>Sign in</button>
            </form>
            <details class="mt-3">
                <summary class="small">Lost your phone? Use a recovery code</summary>
                <form method="post" action="<?= e(url('/login/two-step')) ?>" class="mt-2" data-once><?= csrf_field() ?>
                    <label class="form-label small" for="recovery">Recovery code</label>
                    <input class="form-control mono mb-2" id="recovery" name="code" maxlength="20" autocomplete="off" spellcheck="false" placeholder="XXXXX-XXXXX" required>
                    <button class="btn btn-outline-secondary btn-sm" type="submit">Use this code</button>
                    <p class="form-text mb-0">Each recovery code works once. Without your phone or a code, an administrator can turn two-step sign-in off for you.</p>
                </form>
            </details>
            <form method="post" action="<?= e(url('/login/two-step/cancel')) ?>" class="mt-3 text-center"><?= csrf_field() ?>
                <button class="btn btn-link btn-sm" type="submit">Sign in as someone else</button>
            </form>
        <?php endif; ?>
    </div>
</div>
