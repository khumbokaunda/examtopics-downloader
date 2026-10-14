<?php /** @var array{display_name:string,username:string,email:string} $form @var string|null $error @var bool $available @var bool $approval */ ?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h3 mb-1">Create an account</h1>
        <?php if (!$available): ?>
            <p class="text-muted small">New accounts are not open at the moment. Ask an administrator to create one for you.</p>
            <a class="btn btn-outline-secondary w-100" href="<?= e(url('/login')) ?>">Back to sign in</a>
        <?php else: ?>
        <p class="text-muted small mb-4">We email you a code to confirm your address.<?= $approval ? ' An administrator then approves the account.' : '' ?></p>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger small" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/register')) ?>" data-once data-busy="Creating…"><?= csrf_field() ?>
            <div class="mb-3"><label class="form-label" for="display_name">Your name</label><input class="form-control" id="display_name" name="display_name" maxlength="128" value="<?= e($form['display_name']) ?>" autocomplete="name" required autofocus></div>
            <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" name="email" type="email" maxlength="190" value="<?= e($form['email']) ?>" autocomplete="email" required></div>
            <div class="mb-3"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" value="<?= e($form['username']) ?>" pattern="[A-Za-z0-9._\-]{3,64}" autocomplete="username" required><div class="form-text">3 to 64 letters, digits, dots, hyphens or underscores.</div></div>
            <div class="mb-3"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" minlength="12" autocomplete="new-password" required><div class="form-text">At least 12 characters.</div></div>
            <div class="mb-4"><label class="form-label" for="repeat_password">Repeat password</label><input class="form-control" id="repeat_password" name="repeat_password" type="password" minlength="12" autocomplete="new-password" required></div>
            <button class="btn btn-primary w-100" type="submit"><?= icon('person-plus') ?>Create account</button>
        </form>
        <p class="small text-center text-muted mt-4 mb-0">Already have one? <a href="<?= e(url('/login')) ?>">Sign in</a></p>
        <?php endif; ?>
    </div>
</div>
