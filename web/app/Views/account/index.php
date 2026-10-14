<?php
/** @var array $user @var array<string,string> $appearance @var array<string,list<string>> $appearanceOptions @var bool $customAccent
 *  @var string $systemTimezone @var string $myTimezone @var list<string> $timezones */
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
