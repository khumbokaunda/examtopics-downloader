<?php
/** @var array{display_name:string,username:string,email:string} $account @var array $profile @var array<string,string> $errors @var string|null $error
 *  @var bool $available @var bool $approval @var array $extras */
?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h3 mb-1">Register your institution</h1>
        <?php if (!$available): ?>
            <p class="text-muted">Sign-ups are closed. Sign in to register an institution.</p>
            <a class="btn btn-primary" href="<?= e(url('/login', ['next' => '/institutions/register'])) ?>"><?= icon('box-arrow-in-right') ?>Sign in</a>
        <?php else: ?>
        <p class="text-muted mb-4">Have an account? <a href="<?= e(url('/login', ['next' => '/institutions/register'])) ?>">Sign in</a> first.</p>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/join')) ?>" data-once data-busy="Registering…"><?= csrf_field() ?>
            <?php ob_start(); ?>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="display_name">Your name</label><input class="form-control" id="display_name" name="display_name" maxlength="128" value="<?= e($account['display_name']) ?>" autocomplete="name" required></div>
                        <div class="col-md-6"><label class="form-label" for="email">Your email address</label><input class="form-control" id="email" name="email" type="email" maxlength="190" value="<?= e($account['email']) ?>" autocomplete="email" required></div>
                        <div class="col-md-4"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" value="<?= e($account['username']) ?>" pattern="[A-Za-z0-9._\-]{3,64}" autocomplete="username" required></div>
                        <div class="col-md-4"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" minlength="12" autocomplete="new-password" required><div class="form-text">At least 12 characters.</div></div>
                        <div class="col-md-4"><label class="form-label" for="repeat_password">Repeat password</label><input class="form-control" id="repeat_password" name="repeat_password" type="password" minlength="12" autocomplete="new-password" required data-same-as="password"></div>
                    </div>
            <?php $you = (string) ob_get_clean(); ?>
            <?= \App\Core\View::current()->partial('institutions/_form', ['form' => $profile, 'errors' => $errors, 'prefix' => 'inst_', 'extras' => $extras, 'wizard' => true,
                'lead' => [['icon' => 'person-plus', 'title' => 'You', 'html' => $you]],
                'finish' => '<div class="form-actions"><button class="btn btn-primary" type="submit">' . icon('send') . 'Create account and register</button></div>']) ?>
        </form>
        <?php endif; ?>
    </div>
</div>
