<?php
/** @var array $user @var bool $toolInstalled @var string $toolPath @var bool $tokenConfigured @var bool $workerRunning
 *  @var int|null $workerPid @var bool $autostart @var float $rps @var string $storage @var bool $pdfImages @var list<array> $recentLogins */
?>
<h1 class="h3 mb-4">Settings</h1>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <h2 class="h6">System status</h2>
            <dl class="row small mb-3">
                <dt class="col-5">Collection tool</dt><dd class="col-7"><?= $toolInstalled ? '<span class="text-success">installed</span>' : '<span class="text-danger">missing</span>' ?><div class="text-muted text-break"><code><?= e($toolPath) ?></code></div></dd>
                <dt class="col-5">GitHub token</dt><dd class="col-7"><?= $tokenConfigured ? 'configured (not shown)' : 'not configured' ?></dd>
                <dt class="col-5">Worker</dt><dd class="col-7"><?= $workerRunning ? 'running' . ($workerPid ? ' (pid ' . (int) $workerPid . ')' : '') : 'idle' ?> · <?= $autostart ? 'started on demand' : 'systemd daemon mode' ?></dd>
                <dt class="col-5">Default pacing</dt><dd class="col-7"><?= e((string) $rps) ?> requests/second</dd>
                <dt class="col-5">Images in PDFs</dt><dd class="col-7"><?= $pdfImages ? 'enabled' : 'disabled' ?></dd>
                <dt class="col-5">Storage</dt><dd class="col-7 text-break"><code><?= e($storage) ?></code></dd>
            </dl>
            <p class="small text-muted">These values come from <code>config/config.local.php</code> on the server; secrets are never shown here.</p>
            <form method="post" action="<?= e(url('/settings/reconcile')) ?>" data-confirm="Mark jobs left running by a stopped worker as failed, and restart the worker if jobs are queued?"><?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-secondary" type="submit">Reconcile stale jobs</button>
            </form>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <h2 class="h6">Change password for <?= e($user['username']) ?></h2>
            <form method="post" action="<?= e(url('/settings/password')) ?>" data-once><?= csrf_field() ?>
                <div class="mb-2"><label class="form-label small" for="cp">Current password</label><input class="form-control" id="cp" name="current_password" type="password" autocomplete="current-password" required></div>
                <div class="mb-2"><label class="form-label small" for="np">New password (12+ characters)</label><input class="form-control" id="np" name="new_password" type="password" minlength="12" autocomplete="new-password" required></div>
                <div class="mb-3"><label class="form-label small" for="rp">Repeat new password</label><input class="form-control" id="rp" name="repeat_password" type="password" minlength="12" autocomplete="new-password" required></div>
                <button class="btn btn-primary" type="submit">Change password</button>
            </form>
        </div></div>
    </div>
    <div class="col-12">
        <div class="card shadow-sm"><div class="card-body">
            <h2 class="h6">Recent sign-in attempts</h2>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>Time</th><th>IP</th><th>Username</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($recentLogins as $l): ?>
                    <tr><td class="small"><?= e(fmt_time($l['attempted_at'])) ?></td><td class="small"><code><?= e($l['ip']) ?></code></td><td class="small"><?= e($l['username'] ?? '') ?></td><td><?= $l['succeeded'] ? '<span class="badge text-bg-success">ok</span>' : '<span class="badge text-bg-danger">failed</span>' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div></div>
    </div>
</div>
