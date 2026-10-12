<?php /** @var string $next @var string|null $error @var string $username @var bool $noUsers */ ?>
<div class="row justify-content-center">
    <div class="col-sm-9 col-md-6 col-lg-4">
        <div class="text-center mb-4 mt-5">
            <span class="brand-mark brand-mark-lg">EL</span>
            <h1 class="h4 mt-3 mb-0">Examtopics</h1>
            <p class="text-muted small">Private study library</p>
        </div>
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <?php if ($noUsers): ?>
                    <div class="alert alert-info small">No user exists yet. On the server, run:<br><code>php bin/create-user.php &lt;username&gt;</code></div>
                <?php endif; ?>
                <?php if ($error !== null): ?>
                    <div class="alert alert-danger small" role="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/login')) ?>" data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="next" value="<?= e($next) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <input class="form-control" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Sign in</button>
                </form>
            </div>
        </div>
    </div>
</div>
