<?php
/** @var bool $toolInstalled @var string $toolPath @var bool $tokenConfigured @var bool $workerRunning
 *  @var int|null $workerPid @var bool $autostart @var float $rps @var string $storage @var bool $imagesDownload @var list<string> $toolMissing @var array{files:int,bytes:int} $imageTotals @var int $lockMinutes @var int $lockAttempts @var int $networkMax @var int $networkMinutes
 *  @var array{idle_timeout:int,absolute_timeout:int,timezone:string} $system @var list<string> $timezones */
use App\Services\SystemSettings;
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Settings</h1>
        <p class="page-subtitle">How this installation is set up. Your own password and appearance are on your <a href="<?= e(url('/account')) ?>">account page</a>.</p>
    </div>
</header>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('hdd-stack') ?>System status</span></div>
            <div class="card-body">
                <dl class="meta-list is-wide mb-4">
                    <dt>Collection tool</dt>
                    <dd><?= !$toolInstalled ? chip('missing', 'danger', 'x-circle') : ($toolMissing !== [] ? chip('out of date', 'warning', 'exclamation-triangle') : chip('installed', 'success', 'check2-circle')) ?><div class="mt-1"><code><?= e($toolPath) ?></code></div>
                        <?php if (!$toolInstalled): ?><div class="form-text">Build it on the server with <code>go build -o bin/examtopics-downloader ./cmd</code>.</div><?php endif; ?>
                        <?php if ($toolInstalled && $toolMissing !== []): ?><div class="form-text">It was built before the web app was updated and does not know <?= e(implode(', ', $toolMissing)) ?>. Rebuild it in the project folder with <code>go build -o bin/examtopics-downloader ./cmd</code>.</div><?php endif; ?></dd>
                    <dt>GitHub token</dt><dd><?= $tokenConfigured ? chip('configured', 'success', 'key') . ' <span class="text-muted">(not shown)</span>' : chip('not configured', 'neutral', 'key') ?></dd>
                    <dt>Worker</dt><dd><?= $workerRunning ? chip('running' . ($workerPid ? ', pid ' . (int) $workerPid : ''), 'success', 'cpu') : chip('idle', 'neutral', 'cpu') ?> <span class="text-muted"><?= $autostart ? 'started on demand' : 'systemd daemon mode' ?></span></dd>
                    <dt>Default pacing</dt><dd><?= e((string) $rps) ?> request<?= $rps === 1.0 ? '' : 's' ?> per second</dd>
                    <dt>Question images</dt><dd><?= $imagesDownload ? chip('saved', 'success', 'images') : chip('off', 'neutral', 'images') ?> <span class="text-muted"><?= number_format($imageTotals['files']) ?> files, <?= e(fmt_bytes($imageTotals['bytes'])) ?></span></dd>
                    <dt>Storage</dt><dd><code><?= e($storage) ?></code></dd>
                </dl>
                <p class="small text-muted">These values come from <code>config/config.local.php</code> on the server. Secrets are never shown here.</p>
                <form method="post" action="<?= e(url('/settings/reconcile')) ?>" data-confirm="Jobs left running by a stopped worker are marked failed, and the worker restarts if jobs are queued." data-confirm-title="Reconcile stale jobs?" data-confirm-button="Reconcile"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= icon('arrow-repeat') ?>Reconcile stale jobs</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('sliders') ?>More settings</span></div>
            <div class="list-group list-group-flush">
                <a class="list-group-item list-group-item-action" href="<?= e(url('/users')) ?>"><?= icon('people') ?> <strong>Users</strong><div class="small text-muted">Accounts, roles and what each person may do.</div></a>
                <a class="list-group-item list-group-item-action" href="<?= e(url('/settings/branding')) ?>"><?= icon('brush') ?> <strong>Branding</strong><div class="small text-muted">System name, logo and colours.</div></a>
                <a class="list-group-item list-group-item-action" href="<?= e(url('/backups')) ?>"><?= icon('archive') ?> <strong>Backups</strong><div class="small text-muted">Back up and restore everything, or move it to another server.</div></a>
                <a class="list-group-item list-group-item-action" href="<?= e(url('/settings/email')) ?>"><?= icon('envelope-at') ?> <strong>Email</strong><div class="small text-muted">The mail server for sign-up codes, password resets and notifications.</div></a>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card" id="time">
            <div class="card-header"><span class="card-title"><?= icon('clock') ?>Time and sign-in</span><span class="text-muted small">Times are stored in UTC and shown in each person's timezone</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/settings/time')) ?>" data-once><?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="timezone">System default timezone</label>
                            <select class="form-select" id="timezone" name="timezone">
                                <?php foreach (array_merge(['UTC'], $timezones) as $tz): ?><option value="<?= e($tz) ?>" <?= $system['timezone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?>
                            </select>
                            <div class="form-text">For accounts without their own timezone (each person can choose one on their account page). Malawi, for example, is Africa/Blantyre.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="idle_timeout">Sign out after inactivity</label>
                            <select class="form-select" id="idle_timeout" name="idle_timeout">
                                <?php foreach (SystemSettings::IDLE_CHOICES as $s): ?><option value="<?= $s ?>" <?= $system['idle_timeout'] === $s ? 'selected' : '' ?>><?= e(SystemSettings::describe($s)) ?></option><?php endforeach; ?>
                            </select>
                            <div class="form-text">How long a sign-in lasts without any page being opened.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="absolute_timeout">Sign out after</label>
                            <select class="form-select" id="absolute_timeout" name="absolute_timeout">
                                <?php foreach (SystemSettings::ABSOLUTE_CHOICES as $s): ?><option value="<?= $s ?>" <?= $system['absolute_timeout'] === $s ? 'selected' : '' ?>><?= $s === 0 ? 'Never, only after inactivity' : e(SystemSettings::describe($s)) . ' from signing in' ?></option><?php endforeach; ?>
                            </select>
                            <div class="form-text">A limit that applies however active the sign-in is.</div>
                        </div>
                    </div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save time and sign-in</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('person-lock') ?>Sign-in security</span></div>
            <div class="card-body">
                <p class="mb-2">An account locks for <?= (int) $lockMinutes ?> minutes after <?= (int) $lockAttempts ?> wrong passwords in a row, and a network that fails <?= (int) $networkMax ?> times in a short while is refused for <?= (int) $networkMinutes ?> minutes. Shared addresses and Cloudflare's are never blocked.</p>
                <p class="small text-muted mb-3">These limits, and the proxies whose forwarded addresses are believed, are set in <code>config/config.local.php</code> on the server (docs/INSTALL.md).</p>
                <?php if (can('security.manage')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/security/sign-ins')) ?>"><?= icon('shield-lock') ?>Review sign-in attempts</a><?php endif; ?>
            </div>
        </div>
    </div>
</div>
