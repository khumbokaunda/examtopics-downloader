<?php
/** @var array{name:string,tagline:string,logo:?string,accent:string,color:string,theme:string} $brand @var string|null $logoUrl */
$themes = ['system' => ['Match each device', 'circle-half'], 'light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon-stars']];
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/settings')) ?>">Settings</a></li>
    <li class="breadcrumb-item active" aria-current="page">Branding</li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Branding</h1>
        <p class="page-subtitle">The name, logo and colour everyone sees: in the sidebar, on the sign-in page, in the browser tab and in emails.</p>
    </div>
</header>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('brush') ?>Identity and colour</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/settings/branding')) ?>" enctype="multipart/form-data" data-once data-busy="Saving…"><?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="name">System name</label>
                            <input class="form-control" id="name" name="name" maxlength="60" value="<?= e($brand['name']) ?>" placeholder="Examtopics">
                            <div class="form-text">Leave empty for "Examtopics".</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="tagline">Tagline</label>
                            <input class="form-control" id="tagline" name="tagline" maxlength="120" value="<?= e($brand['tagline']) ?>">
                            <div class="form-text">Under the name on the sign-in page. Leave empty for none.</div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <h2 class="section-heading">Logo</h2>
                        <?php if ($logoUrl !== null): ?>
                            <div class="logo-preview mb-2"><img src="<?= e($logoUrl) ?>" alt="Current logo"><span class="small text-muted">Current logo. Upload another to replace it.</span></div>
                        <?php endif; ?>
                        <input class="form-control" type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
                        <div class="form-text">PNG, JPEG, GIF, WebP or SVG, up to 5 MB. Photos are scaled down to 512 pixels and saved as PNG; a transparent background looks best. Emails show PNG logos only, so an SVG logo leaves the name on its own there.</div>
                    </div>

                    <div class="settings-section">
                        <h2 class="section-heading">Colour</h2>
                        <div class="swatch-row mb-3">
                            <?php foreach (\App\Services\Branding::ACCENTS as $v): ?>
                                <label class="swatch">
                                    <input type="radio" name="accent" value="<?= e($v) ?>" <?= $brand['accent'] === $v ? 'checked' : '' ?>>
                                    <span class="swatch-dot" data-swatch="<?= e($v) ?>"></span>
                                    <span class="swatch-name"><?= e(ucfirst($v)) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label class="form-label" for="color">Custom colour</label>
                        <div class="color-field"><input class="form-control" type="color" id="color" name="color" value="<?= e(strtolower($brand['color'] !== '' ? $brand['color'] : '#1C7293')) ?>"><span class="small text-muted">Used when Custom is chosen.</span></div>
                        <div class="form-text">A preset is the starting colour for each person, who can pick their own on their account page. A custom colour applies to everyone; it is darkened in the light theme and lightened in the dark one only as far as it takes to stay readable.</div>
                    </div>

                    <div class="settings-section">
                        <h2 class="section-heading">Default theme</h2>
                        <div class="choice-grid">
                            <?php foreach ($themes as $v => [$label, $iconName]): ?>
                                <label class="choice-card">
                                    <input type="radio" name="theme" value="<?= e($v) ?>" <?= $brand['theme'] === $v ? 'checked' : '' ?>>
                                    <span class="choice-body"><span class="choice-title"><?= icon($iconName) ?><?= e($label) ?></span></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">For people who have not chosen their own, and for the sign-in page.</div>
                    </div>

                    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save branding</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('eye') ?>Where it shows</span></div>
            <div class="card-body small">
                <ul class="mb-3 ps-3">
                    <li>The sidebar and the browser tab, with the logo as the tab icon.</li>
                    <li>The sign-in page.</li>
                    <li>Every email the system sends.</li>
                </ul>
                <?php if ($logoUrl !== null): ?>
                    <form method="post" action="<?= e(url('/settings/branding/logo/remove')) ?>" data-confirm="The name is shown on its own." data-confirm-title="Remove the logo?" data-confirm-button="Remove"><?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-danger" type="submit"><?= icon('trash') ?>Remove the logo</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
