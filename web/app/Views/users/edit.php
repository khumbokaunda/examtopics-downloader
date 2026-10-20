<?php
/** @var bool $twoStepOn */
/** @var array|null $account @var array{username:string,display_name:string,email:string,role:string,status:string,permissions:list<string>} $form
 *  @var string|null $error @var bool $self */
use App\Services\Access;
$new = $account === null;
$action = $new ? url('/users') : url('/users/' . rawurlencode($account['username']));
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/users')) ?>">Users</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($new ? 'New user' : $account['username']) ?></li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e($new ? 'New user' : Access::displayName($account)) ?></h1>
        <p class="page-subtitle"><?= $new ? 'Create an account and share its password with the person privately.' : 'Created ' . e(fmt_time($account['created_at'])) . ' · last sign-in ' . e($account['last_login_at'] ? fmt_time($account['last_login_at']) : 'never') ?></p>
    </div>
</header>

<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div>
<?php endif; ?>
<?php if (!$new && ($lockedFor = \App\Services\SignInGuard::lockedFor($account)) !== null): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2"><?= icon('lock') ?><div class="alert-body flex-grow-1">Locked after too many wrong passwords, for <?= e(\App\Services\Auth::minutes($lockedFor)) ?> more.</div>
        <?php if (can('security.manage')): ?>
            <form method="post" action="<?= e(url('/security/sign-ins/unlock')) ?>"><?= csrf_field() ?><input type="hidden" name="username" value="<?= e($account['username']) ?>"><input type="hidden" name="return" value="user">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= icon('unlock') ?>Unlock now</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" data-once><?= csrf_field() ?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('person') ?>Account</span></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="username">Username</label>
                    <input class="form-control" id="username" name="username" value="<?= e($form['username']) ?>" <?= $new ? 'required pattern="[A-Za-z0-9._\-]{3,64}" autocomplete="off"' : 'disabled' ?>>
                    <?php if ($new): ?><div class="form-text">3 to 64 letters, digits, dots, hyphens or underscores. People sign in with this or their email address.</div><?php endif; ?>
                </div>
                <div class="mb-3"><label class="form-label" for="display_name">Name</label><input class="form-control" id="display_name" name="display_name" maxlength="128" value="<?= e($form['display_name']) ?>"></div>
                <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" name="email" type="email" maxlength="190" value="<?= e($form['email']) ?>">
                    <?php if (!$new && $account['email'] !== null): ?><div class="form-text"><?= $account['email_verified_at'] !== null ? chip('verified', 'success', 'check2-circle') : chip('not verified', 'warning', 'exclamation-triangle') ?></div><?php endif; ?></div>
                <div class="mb-3">
                    <label class="form-label" for="password"><?= $new ? 'Password' : 'New password' ?></label>
                    <input class="form-control" id="password" name="password" type="password" minlength="12" autocomplete="new-password" <?= $new ? 'required' : '' ?>>
                    <div class="form-text"><?= $new ? 'At least 12 characters.' : 'Leave empty to keep the current one. At least 12 characters.' ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('shield-lock') ?>Access</span></div>
            <div class="card-body">
                <?php if ($self): ?><p class="small text-muted">This is your own account, so its role and status cannot be changed here.</p><?php endif; ?>
                <div class="mb-3">
                    <label class="form-label" for="role">Role</label>
                    <select class="form-select" id="role" name="role" <?= $self ? 'disabled' : '' ?>>
                        <?php foreach (Access::assignableRoles() as $slug => $name): ?><option value="<?= e($slug) ?>" <?= $form['role'] === $slug ? 'selected' : '' ?>><?= e($name) ?>: <?= e(Access::ROLES[$slug]['description']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status" <?= $self ? 'disabled' : '' ?>>
                        <?php foreach (Access::STATUSES as $slug => $name): ?><option value="<?= e($slug) ?>" <?= $form['status'] === $slug ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text">Only active accounts can sign in. A disabled account is signed out on its next page.</div>
                </div>
                <?php if ($form['role'] !== Access::ADMIN): $given = Access::ROLE_PERMISSIONS[$form['role']] ?? []; ?>
                <fieldset class="settings-section" <?= $self ? 'disabled' : '' ?>>
                    <legend class="section-heading">Permissions</legend>
                    <p class="small text-muted">Ticked and greyed: given by the role. Tick others to give this person more without making them an administrator.</p>
                    <?php foreach (Access::PERMISSIONS as $perm => $description): $byRole = in_array($perm, $given, true); ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="perm-<?= e($perm) ?>" <?= $byRole ? 'checked disabled' : 'name="permissions[]" value="' . e($perm) . '"' . (in_array($perm, $form['permissions'], true) ? ' checked' : '') ?>>
                            <label class="form-check-label" for="perm-<?= e($perm) ?>"><code class="small"><?= e($perm) ?></code> <span class="small"><?= e($description) ?></span></label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                <?php else: ?>
                    <p class="small text-muted mb-0">Administrators hold every permission.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<div class="form-actions mt-4">
    <button class="btn btn-primary" type="submit"><?= icon('check2') ?><?= $new ? 'Create account' : 'Save' ?></button>
    <a class="btn btn-link" href="<?= e(url('/users')) ?>">Cancel</a>
</div>
</form>

<?php if (!$new && !$self && ($twoStepOn ?? false)): ?>
<div class="card mt-4">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div><strong><?= icon('phone') ?> Two-step sign-in is on</strong><div class="small text-muted">If they lost their phone and their recovery codes, turn it off so they can sign in with their password, then set it up again.</div></div>
        <form method="post" action="<?= e(url('/users/' . rawurlencode($account['username']) . '/two-step/off')) ?>" data-confirm="They are told by email, and can turn it on again from their account page." data-confirm-title="Turn off two-step sign-in for <?= e($account['username']) ?>?" data-confirm-button="Turn off" data-confirm-tone="danger"><?= csrf_field() ?>
            <button class="btn btn-outline-danger" type="submit">Turn off two-step sign-in</button>
        </form>
    </div>
</div>
<?php endif; ?>
<?php if (!$new && !$self): ?>
<div class="card mt-4 border-danger-subtle">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div><strong>Delete this account</strong><div class="small text-muted">Their requests and notifications go with it. To keep them, set the status to Disabled instead.</div></div>
        <form method="post" action="<?= e(url('/users/' . rawurlencode($account['username']) . '/delete')) ?>" data-confirm="This cannot be undone." data-confirm-title="Delete <?= e($account['username']) ?>?" data-confirm-button="Delete"><?= csrf_field() ?>
            <button class="btn btn-outline-danger" type="submit"><?= icon('trash') ?>Delete account</button>
        </form>
    </div>
</div>
<?php endif; ?>
