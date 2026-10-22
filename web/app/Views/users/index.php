<?php
/** @var list<array> $users @var int $me @var string $mode @var bool $mailReady */
use App\Services\Access;
$statusTone = ['active' => 'success', 'unverified' => 'warning', 'pending' => 'warning', 'disabled' => 'neutral'];
$waiting = count(array_filter($users, static fn(array $u): bool => $u['status'] === 'pending'));
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Users</h1>
        <p class="page-subtitle">Who can sign in and what each person may do. Members read and practise the collected exams; administrators do everything.</p>
    </div>
    <div class="page-actions"><a class="btn btn-primary" href="<?= e(url('/users/new')) ?>"><?= icon('person-plus') ?>New user</a></div>
</header>

<?php if ($waiting > 0): ?>
    <div class="alert alert-info"><?= icon('person-exclamation') ?><div class="alert-body"><?= $waiting === 1 ? 'One account is' : $waiting . ' accounts are' ?> waiting for approval. Use <strong>Approve</strong> below, or open the account to disable it.</div></div>
<?php endif; ?>

<div class="card mb-4" id="signup">
    <div class="card-header"><span class="card-title"><?= icon('person-plus') ?>Sign-up</span><span class="small text-muted">Who may create their own account</span></div>
    <div class="card-body">
        <form method="post" action="<?= e(url('/users/registration')) ?>" data-once><?= csrf_field() ?>
            <div class="choice-grid mb-3">
                <?php foreach (\App\Services\Registration::MODES as $key => [$label, $hint]): ?>
                    <label class="choice-card">
                        <input type="radio" name="mode" value="<?= e($key) ?>" <?= $mode === $key ? 'checked' : '' ?>>
                        <span class="choice-body"><span class="choice-title"><?= e($label) ?></span><span class="choice-hint"><?= e($hint) ?></span></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if (!$mailReady && $mode !== 'closed'): ?><p class="small text-warning"><?= icon('exclamation-triangle') ?> Sign-up is hidden until email is set up (Settings &gt; Email), because the code is emailed.</p><?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="users" data-order='[[0,"asc"]]'>
            <thead><tr><th>Account</th><th>Email</th><th>Role</th><th>Status</th><th data-type="num">Last sign-in</th><th data-type="num">Created</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $extras = Access::extras($u); ?>
                <tr>
                    <td class="stack-title" data-order="<?= e(strtolower(Access::displayName($u))) ?>">
                        <a class="table-row-link" href="<?= e(url('/users/' . $u['uuid'])) ?>"><?= e(Access::displayName($u)) ?></a>
                        <div class="cell-sub"><?= e($u['username']) ?><?= (int) $u['id'] === $me ? ' · you' : '' ?></div>
                    </td>
                    <td class="small" data-label="Email"><?php if ($u['email'] !== null): ?><?= e($u['email']) ?><?= $u['email_verified_at'] === null ? ' <span class="text-warning" title="Not verified">' . icon('exclamation-circle') . '</span>' : '' ?><?php else: ?><span class="text-muted">none</span><?php endif; ?></td>
                    <td data-label="Role"><span class="chip-group"><?= chip(Access::roleName($u['role']), $u['role'] === 'admin' ? 'primary' : 'neutral', 'shield') ?><?php if ($extras !== []): ?><?= chip('+' . count($extras), 'info') ?><?php endif; ?></span></td>
                    <td data-label="Status"><span class="chip-group"><?= chip(Access::STATUSES[$u['status']] ?? $u['status'], $statusTone[$u['status']] ?? 'neutral') ?><?= \App\Services\SignInGuard::lockedFor($u) !== null ? chip('Locked', 'warning', 'lock') : '' ?>
                        <?php if ($u['status'] === 'pending'): ?><form method="post" action="<?= e(url('/users/' . $u['uuid'] . '/approve')) ?>" class="d-inline" data-once><?= csrf_field() ?><button class="btn btn-sm btn-success py-0" type="submit"><?= icon('check2') ?>Approve</button></form><?php endif; ?></span></td>
                    <td class="small" data-label="Last sign-in" data-order="<?= e((string) ($u['last_login_at'] ?? '')) ?>"><?= $u['last_login_at'] ? e(fmt_ago($u['last_login_at'])) : '<span class="text-muted">never</span>' ?></td>
                    <td class="small" data-label="Created" data-order="<?= e((string) $u['created_at']) ?>"><?= e(fmt_time($u['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
