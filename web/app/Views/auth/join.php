<?php
/** @var array{display_name:string,username:string,email:string} $account @var array $profile @var array<string,string> $errors @var string|null $error
 *  @var bool $available @var bool $approval */
?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h3 mb-1">Register your institution</h1>
        <?php if (!$available): ?>
            <p class="text-muted">New accounts are not open at the moment, so institutions cannot register here. If you already have an account, sign in and register your institution from your account page.</p>
            <a class="btn btn-primary" href="<?= e(url('/login', ['next' => '/institutions/register'])) ?>"><?= icon('box-arrow-in-right') ?>Sign in</a>
        <?php else: ?>
        <p class="text-muted mb-4">Training centres, colleges and other institutions can assess their students here. Create your account and tell us about the institution: we email you a code to confirm your address<?= $approval ? ', an administrator approves your account,' : '' ?> and a platform reviewer then checks the registration before you invite your people. Verification here is not accreditation or government recognition.
            Already have an account? <a href="<?= e(url('/login', ['next' => '/institutions/register'])) ?>">Sign in</a> and register from there.</p>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/join')) ?>" data-once data-busy="Registering…"><?= csrf_field() ?>
            <div class="card mb-4">
                <div class="card-header"><span class="card-title"><?= icon('person-plus') ?>You: the institution's owner</span></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="display_name">Your name</label><input class="form-control" id="display_name" name="display_name" maxlength="128" value="<?= e($account['display_name']) ?>" autocomplete="name" required></div>
                        <div class="col-md-6"><label class="form-label" for="email">Your email address</label><input class="form-control" id="email" name="email" type="email" maxlength="190" value="<?= e($account['email']) ?>" autocomplete="email" required></div>
                        <div class="col-md-4"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" value="<?= e($account['username']) ?>" pattern="[A-Za-z0-9._\-]{3,64}" autocomplete="username" required></div>
                        <div class="col-md-4"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" minlength="12" autocomplete="new-password" required><div class="form-text">At least 12 characters.</div></div>
                        <div class="col-md-4"><label class="form-label" for="repeat_password">Repeat password</label><input class="form-control" id="repeat_password" name="repeat_password" type="password" minlength="12" autocomplete="new-password" required></div>
                    </div>
                </div>
            </div>
            <?= \App\Core\View::current()->partial('institutions/_form', ['form' => $profile, 'errors' => $errors, 'prefix' => 'inst_']) ?>
            <div class="form-actions mt-4"><button class="btn btn-primary" type="submit"><?= icon('send') ?>Create my account and register</button></div>
        </form>
        <?php endif; ?>
    </div>
</div>
