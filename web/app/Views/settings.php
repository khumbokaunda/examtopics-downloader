<?php
/** @var array $user @var bool $toolInstalled @var string $toolPath @var bool $tokenConfigured @var bool $workerRunning
 *  @var int|null $workerPid @var bool $autostart @var float $rps @var string $storage @var bool $pdfImages @var list<array> $recentLogins
 *  @var array<string,string> $appearance @var array<string,list<string>> $appearanceOptions */
$labels = [
    'theme' => ['system' => ['Match my device', 'Follows the light or dark setting of this device'], 'light' => ['Light', 'Bright surfaces'], 'dark' => ['Dark', 'Dim surfaces for low light']],
    'density' => ['compact' => ['Compact', '13px text, more on screen'], 'comfortable' => ['Comfortable', '14px text, the default'], 'cozy' => ['Cozy', '15px text, more room']],
    'surface' => ['classic' => ['Classic', 'White cards on a pale page'], 'soft' => ['Soft', 'A greyer page so cards stand out (light theme)']],
    'motion' => ['system' => ['Match my device', 'Animations unless the device asks for less'], 'reduced' => ['Reduce motion', 'No animations or transitions']],
];
$themeIcons = ['system' => 'circle-half', 'light' => 'sun', 'dark' => 'moon-stars'];
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Settings</h1>
        <p class="page-subtitle">How this installation is set up, how it looks for you, and your account.</p>
    </div>
</header>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('hdd-stack') ?>System status</span></div>
            <div class="card-body">
                <dl class="meta-list is-wide mb-4">
                    <dt>Collection tool</dt>
                    <dd><?= $toolInstalled ? chip('installed', 'success', 'check2-circle') : chip('missing', 'danger', 'x-circle') ?><div class="mt-1"><code><?= e($toolPath) ?></code></div>
                        <?php if (!$toolInstalled): ?><div class="form-text">Build it on the server with <code>go build -o bin/examtopics-downloader ./cmd</code>.</div><?php endif; ?></dd>
                    <dt>GitHub token</dt><dd><?= $tokenConfigured ? chip('configured', 'success', 'key') . ' <span class="text-muted">(not shown)</span>' : chip('not configured', 'neutral', 'key') ?></dd>
                    <dt>Worker</dt><dd><?= $workerRunning ? chip('running' . ($workerPid ? ', pid ' . (int) $workerPid : ''), 'success', 'cpu') : chip('idle', 'neutral', 'cpu') ?> <span class="text-muted"><?= $autostart ? 'started on demand' : 'systemd daemon mode' ?></span></dd>
                    <dt>Default pacing</dt><dd><?= e((string) $rps) ?> request<?= $rps === 1.0 ? '' : 's' ?> per second</dd>
                    <dt>Images in PDFs</dt><dd><?= $pdfImages ? 'enabled' : 'disabled' ?></dd>
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
            <div class="card-header"><span class="card-title"><?= icon('shield-lock') ?>Change password</span><span class="text-muted small"><?= e($user['username']) ?></span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/settings/password')) ?>" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="cp">Current password</label><input class="form-control" id="cp" name="current_password" type="password" autocomplete="current-password" required></div>
                    <div class="mb-3"><label class="form-label" for="np">New password</label><input class="form-control" id="np" name="new_password" type="password" minlength="12" autocomplete="new-password" required><div class="form-text">At least 12 characters.</div></div>
                    <div class="mb-3"><label class="form-label" for="rp">Repeat new password</label><input class="form-control" id="rp" name="repeat_password" type="password" minlength="12" autocomplete="new-password" required></div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit">Change password</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card" id="appearance">
            <div class="card-header"><span class="card-title"><?= icon('palette') ?>Appearance</span><span class="text-muted small">Saved to your account, previewed as you choose</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/settings/appearance')) ?>" data-appearance><?= csrf_field() ?>
                    <div class="settings-section">
                        <h2 class="section-heading">Theme</h2>
                        <div class="choice-grid">
                            <?php foreach ($appearanceOptions['theme'] as $v): [$t, $h] = $labels['theme'][$v]; ?>
                                <label class="choice-card">
                                    <input type="radio" name="theme" value="<?= e($v) ?>" <?= $appearance['theme'] === $v ? 'checked' : '' ?>>
                                    <span class="choice-body">
                                        <span class="theme-preview"><?php if ($v !== 'dark'): ?><span class="tp-light"></span><?php endif; ?><?php if ($v !== 'light'): ?><span class="tp-dark"></span><?php endif; ?></span>
                                        <span class="choice-title"><?= icon($themeIcons[$v]) ?><?= e($t) ?></span>
                                        <span class="choice-hint"><?= e($h) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="settings-section">
                        <h2 class="section-heading">Accent colour</h2>
                        <div class="swatch-row">
                            <?php foreach ($appearanceOptions['accent'] as $v): ?>
                                <label class="swatch">
                                    <input type="radio" name="accent" value="<?= e($v) ?>" <?= $appearance['accent'] === $v ? 'checked' : '' ?>>
                                    <span class="swatch-dot" data-swatch="<?= e($v) ?>"></span>
                                    <span class="swatch-name"><?= e(ucfirst($v)) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php foreach (['density' => 'Text size and spacing', 'surface' => 'Page surface', 'motion' => 'Motion'] as $key => $heading): ?>
                        <div class="settings-section">
                            <h2 class="section-heading"><?= e($heading) ?></h2>
                            <div class="choice-grid">
                                <?php foreach ($appearanceOptions[$key] as $v): [$t, $h] = $labels[$key][$v]; ?>
                                    <label class="choice-card">
                                        <input type="radio" name="<?= e($key) ?>" value="<?= e($v) ?>" <?= $appearance[$key] === $v ? 'checked' : '' ?>>
                                        <span class="choice-body"><span class="choice-title"><?= e($t) ?></span><span class="choice-hint"><?= e($h) ?></span></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save appearance</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('person-lock') ?>Recent sign-in attempts</span></div>
            <?php if ($recentLogins === []): ?>
                <div class="card-body"><?= empty_state('person-check', 'No sign-in attempts recorded', '', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack" data-table="sign-ins" data-page-length="10" data-order='[[0,"desc"]]'>
                    <thead><tr><th>Time</th><th>IP address</th><th>Username</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentLogins as $l): ?>
                        <tr><td class="small stack-title" data-order="<?= e((string) $l['attempted_at']) ?>"><?= e(fmt_time($l['attempted_at'])) ?></td><td data-label="IP address"><code><?= e($l['ip']) ?></code></td><td class="small" data-label="Username"><?= e($l['username'] ?? '') ?></td><td data-label="Result"><?= $l['succeeded'] ? chip('signed in', 'success', 'check2') : chip('failed', 'danger', 'x') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
