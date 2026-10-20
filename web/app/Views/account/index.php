<?php
/** @var list<array<string,mixed>> $recentSignIns @var int $lockAttempts @var array{enabled:bool,since:?string,pending:?string,codes_left:int} $twoStep @var string|null $twoStepUri */
/** @var array $user @var array<string,string> $appearance @var array<string,list<string>> $appearanceOptions @var bool $customAccent
 *  @var string $systemTimezone @var string $myTimezone @var list<string> $timezones @var bool $emailNotifications @var bool $mailReady */
use App\Services\Access;
$labels = [
    'theme' => ['system' => ['Match my device', 'Follows the light or dark setting of this device'], 'light' => ['Light', 'Bright surfaces'], 'dark' => ['Dark', 'Dim surfaces for low light']],
    'density' => ['compact' => ['Compact', '13px text, more on screen'], 'comfortable' => ['Comfortable', '14px text, the default'], 'cozy' => ['Cozy', '15px text, more room']],
    'surface' => ['classic' => ['Classic', 'White cards on a pale page'], 'soft' => ['Soft', 'A greyer page so cards stand out (light theme)']],
    'motion' => ['system' => ['Match my device', 'Animations unless the device asks for less'], 'reduced' => ['Reduce motion', 'No animations or transitions']],
];
$themeIcons = ['system' => 'circle-half', 'light' => 'sun', 'dark' => 'moon-stars'];
$verified = $user['email'] !== null && $user['email_verified_at'] !== null;
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Account</h1>
        <p class="page-subtitle">Your profile, password, timezone and how the pages look for you.</p>
    </div>
</header>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card h-100" id="profile">
            <div class="card-header"><span class="card-title"><?= icon('person-circle') ?>Profile</span><span class="chip-group"><?= chip(Access::roleName((string) $user['role']), $user['role'] === 'admin' ? 'primary' : 'neutral', 'shield') ?></span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/account/profile')) ?>" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="username">Username</label><input class="form-control" id="username" value="<?= e($user['username']) ?>" disabled><div class="form-text">Used to sign in, with your email address. An administrator can change it.</div></div>
                    <div class="mb-3"><label class="form-label" for="display_name">Name</label><input class="form-control" id="display_name" name="display_name" maxlength="128" value="<?= e($user['display_name'] ?? '') ?>" autocomplete="name"><div class="form-text">Shown in the top bar and on your requests.</div></div>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email address</label>
                        <input class="form-control" id="email" name="email" type="email" maxlength="190" value="<?= e($user['email'] ?? '') ?>" autocomplete="email">
                        <div class="form-text"><?php if ($user['email'] === null): ?>Add one to receive notifications by email and to reset a forgotten password.<?php elseif ($verified): ?><?= chip('verified', 'success', 'check2-circle') ?><?php else: ?><?= chip('not verified', 'warning', 'exclamation-triangle') ?><?php endif; ?></div>
                    </div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save profile</button></div>
                </form>
                <?php if ($user['email'] !== null && !$verified): ?>
                    <div class="settings-section">
                        <h2 class="section-heading">Confirm your email address</h2>
                        <?php if (!$mailReady): ?>
                            <p class="small text-muted mb-0">Email is not set up on this server yet, so a code cannot be sent.</p>
                        <?php else: ?>
                            <p class="small text-muted">Type the code we emailed to <?= e($user['email']) ?>. Notifications are only emailed to a confirmed address.</p>
                            <div class="d-flex flex-wrap gap-2 align-items-start">
                                <form method="post" action="<?= e(url('/account/email/verify')) ?>" class="toolbar" data-once><?= csrf_field() ?>
                                    <label class="visually-hidden" for="code">Code</label>
                                    <input class="form-control code-input flex-grow-0" id="code" name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="000000" required>
                                    <button class="btn btn-primary" type="submit">Confirm</button>
                                </form>
                                <form method="post" action="<?= e(url('/account/email/send')) ?>" data-once><?= csrf_field() ?>
                                    <button class="btn btn-outline-secondary" type="submit"><?= icon('send') ?>Send a code</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100" id="password">
            <div class="card-header"><span class="card-title"><?= icon('shield-lock') ?>Change password</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/account/password')) ?>" data-once><?= csrf_field() ?>
                    <input class="d-none" name="username" value="<?= e($user['username']) ?>" autocomplete="username" readonly tabindex="-1" aria-hidden="true">
                    <div class="mb-3"><label class="form-label" for="cp">Current password</label><input class="form-control" id="cp" name="current_password" type="password" autocomplete="current-password" required></div>
                    <div class="mb-3"><label class="form-label" for="np">New password</label><input class="form-control" id="np" name="new_password" type="password" minlength="12" autocomplete="new-password" required><div class="form-text">At least 12 characters.</div></div>
                    <div class="mb-3"><label class="form-label" for="rp">Repeat new password</label><input class="form-control" id="rp" name="repeat_password" type="password" minlength="12" autocomplete="new-password" required></div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit">Change password</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card" id="two-step">
            <div class="card-header"><span class="card-title"><?= icon('phone') ?>Two-step sign-in</span>
                <?= $twoStep['enabled'] ? chip('On', 'success', 'shield-check') : ($twoStep['pending'] !== null ? chip('Being set up', 'warning') : chip('Off', 'neutral')) ?></div>
            <div class="card-body">
            <?php if ($twoStep['enabled']): ?>
                <p>Signing in needs the code from your authenticator app as well as your password. On since <?= e(fmt_time((string) $twoStep['since'])) ?>.
                    <?= (int) $twoStep['codes_left'] ?> recovery code<?= (int) $twoStep['codes_left'] === 1 ? '' : 's' ?> left<?= (int) $twoStep['codes_left'] <= 3 ? ': make new ones soon.' : '.' ?></p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <form method="post" action="<?= e(url('/account/two-step/codes/new')) ?>" data-once><?= csrf_field() ?>
                            <label class="form-label small" for="codes-code">Make new recovery codes (the old ones stop working)</label>
                            <div class="d-flex gap-2"><input class="form-control code-input flex-grow-0" id="codes-code" name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="000000" required>
                                <button class="btn btn-outline-secondary" type="submit">Make new codes</button></div>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <form method="post" action="<?= e(url('/account/two-step/off')) ?>" data-once data-confirm="Signing in will need only your password." data-confirm-title="Turn off two-step sign-in?" data-confirm-button="Turn off" data-confirm-tone="danger"><?= csrf_field() ?>
                            <label class="form-label small" for="off-code">Turn it off (a code from your app, or a recovery code)</label>
                            <div class="d-flex gap-2"><input class="form-control code-input flex-grow-0" id="off-code" name="code" maxlength="20" autocomplete="one-time-code" placeholder="000000" required>
                                <button class="btn btn-outline-danger" type="submit">Turn off</button></div>
                        </form>
                    </div>
                </div>
            <?php elseif ($twoStep['pending'] !== null): ?>
                <ol class="setup-steps">
                    <li>Install an authenticator app on your phone, if you have none: Google Authenticator, Microsoft Authenticator, Authy, or the one in your password manager.</li>
                    <li>In the app, add an account and scan this code.
                        <div class="d-flex flex-wrap align-items-center gap-4 my-3">
                            <span class="qr-box"><img data-qr="<?= e((string) $twoStepUri) ?>" alt="QR code for your authenticator app" width="180" height="180"></span>
                            <div><div class="small text-muted">Cannot scan? Type this key instead:</div><div class="secret-key mono"><?= e(\App\Services\TwoStep::grouped($twoStep['pending'])) ?></div><div class="small text-muted">Time-based, 6 digits.</div></div>
                        </div>
                    </li>
                    <li>Type the six-digit code the app shows:
                        <form method="post" action="<?= e(url('/account/two-step/confirm')) ?>" class="d-flex flex-wrap gap-2 mt-2" data-once><?= csrf_field() ?>
                            <input class="form-control code-input flex-grow-0" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" placeholder="000000" aria-label="Code from the app" required>
                            <button class="btn btn-primary" type="submit"><?= icon('shield-check') ?>Turn on</button>
                        </form>
                    </li>
                </ol>
                <form method="post" action="<?= e(url('/account/two-step/cancel')) ?>"><?= csrf_field() ?><button class="btn btn-link btn-sm px-0" type="submit">Cancel setting up</button></form>
                <script src="<?= e(asset('/assets/vendor/qrcode-generator/qrcode.js')) ?>"></script>
            <?php else: ?>
                <p>Ask for a code from an app on your phone as well as your password when you sign in, so a stolen password is not enough on its own.</p>
                <form method="post" action="<?= e(url('/account/two-step/begin')) ?>"><?= csrf_field() ?>
                    <button class="btn btn-outline-primary" type="submit"><?= icon('phone') ?>Set up two-step sign-in</button>
                </form>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card" id="security">
            <div class="card-header"><span class="card-title"><?= icon('shield-check') ?>Recent sign-ins</span><span class="text-muted small">Your sign-ins and failed attempts at your account</span></div>
            <?php if ($recentSignIns === []): ?>
                <div class="card-body"><p class="text-muted mb-0">Nothing recorded yet.</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead><tr><th>When</th><th>Address</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentSignIns as $a): ?>
                        <tr>
                            <td class="small stack-title"><?= e(fmt_time((string) $a['attempted_at'])) ?></td>
                            <td class="small mono" data-label="Address"><?= e((string) $a['ip']) ?></td>
                            <td data-label="Result"><?= $a['succeeded'] ? chip('Signed in', 'success', 'check2') : match ((string) $a['reason']) {
                                'account locked' => chip('Refused: account locked', 'warning', 'lock'),
                                'account not active' => chip('Refused: account not active', 'neutral', 'slash-circle'),
                                'wrong two-step code' => chip('Wrong two-step code', 'danger', 'phone'),
                                default => chip('Wrong password', 'danger', 'x'),
                            } ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-muted">If you see a wrong password you did not type, change your password. After <?= (int) $lockAttempts ?> in a row the account locks for a while.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-12">
        <div class="card" id="notifications">
            <div class="card-header"><span class="card-title"><?= icon('bell') ?>Notifications</span><a class="small" href="<?= e(url('/inbox')) ?>">Open your inbox</a></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/account/notifications')) ?>" data-once><?= csrf_field() ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="email_notifications" name="email_notifications" value="1" <?= $emailNotifications ? 'checked' : '' ?>>
                        <label class="form-check-label" for="email_notifications">Also email me my notifications<?= can('requests.manage') ? ', such as new exam requests' : ', such as an exam I asked for being collected' ?></label>
                    </div>
                    <div class="form-text mb-3"><?php if ($user['email'] === null): ?>Add an email address above first.<?php elseif (!$verified): ?>Confirm your email address above first.<?php else: ?>They go to <?= e($user['email']) ?>. Everything also shows in your inbox (the bell at the top).<?php endif; ?></div>
                    <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card" id="time">
            <div class="card-header"><span class="card-title"><?= icon('clock') ?>Timezone</span><span class="text-muted small">Times are shown in this timezone</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/account/time')) ?>" class="row g-3 align-items-end" data-once><?= csrf_field() ?>
                    <div class="col-md-6">
                        <label class="form-label" for="my_timezone">Your timezone</label>
                        <select class="form-select" id="my_timezone" name="my_timezone">
                            <option value="">Use the system default (<?= e($systemTimezone) ?>)</option>
                            <?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>" <?= $myTimezone === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text">Malawi, for example, is Africa/Blantyre.</div>
                    </div>
                    <div class="col-md-6"><button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save timezone</button></div>
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
                    <?php if ($customAccent): ?>
                    <div class="settings-section">
                        <h2 class="section-heading">Accent colour</h2>
                        <p class="small text-muted mb-0">This installation uses its own colour for everyone (Settings &gt; Branding).</p>
                    </div>
                    <?php else: ?>
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
                    <?php endif; ?>
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

</div>
