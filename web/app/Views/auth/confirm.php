<?php /** @var string $next @var bool $withCode @var string|null $error @var int $minutes */ ?>
<div class="card confirm-card">
    <div class="card-body">
        <h1 class="h4 mb-1"><?= icon('shield-lock') ?> Confirm it is you</h1>
        <p class="text-muted small mb-4"><?= $withCode
            ? 'Type the code from your authenticator app. It is good for the next ' . (int) $minutes . ' minutes of backups and account changes.'
            : 'Type your password. It is good for the next ' . (int) $minutes . ' minutes of backups and account changes.' ?></p>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger small" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/confirm')) ?>" data-once><?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
            <?php if ($withCode): ?>
                <div class="mb-3"><label class="form-label" for="code">Code</label><input class="form-control form-control-lg code-input" id="code" name="code" maxlength="20" autocomplete="one-time-code" required autofocus>
                    <div class="form-text">The six digits from your app, or one of your recovery codes.</div></div>
            <?php else: ?>
                <div class="mb-3"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required autofocus></div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit"><?= icon('check2-circle') ?>Confirm</button>
                <a class="btn btn-outline-secondary" href="<?= e(url($next === '/confirm' ? '/' : $next)) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
