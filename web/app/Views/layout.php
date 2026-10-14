<?php
/**
 * The page shell. Signed in: sidebar, top bar and content. Signed out: one centred card.
 * Appearance preferences are rendered into attributes on <html>; js/theme-init.js resolves a
 * "system" theme before first paint (it is an external file: the CSP allows no inline script).
 *
 * @var string $title @var string $content @var array|null $user @var list<array{type:string,message:string}> $flashes @var string $nav
 */
use App\Services\Appearance;

$branding = \App\Core\View::current()->branding();
$brand = $branding->get();
$appName = $brand['name'];
$logoUrl = $branding->logoUrl();
$prefs = Appearance::forUser($user, $brand);
$accent = Appearance::pageAccent($prefs, $brand);
$collector = $user && can('exams.collect');
$groups = [
    'Overview' => [
        'dashboard' => ['/', 'Dashboard', 'grid-1x2', 'library.view'],
    ],
    'Study' => [
        'library' => ['/library', 'Library', 'journal-bookmark', 'library.view'],
        'providers' => ['/providers', $collector ? 'Providers & inventory' : 'Providers', 'buildings', 'library.view'],
    ],
    'Collect' => [
        'collect' => ['/collect', 'Collect exam', 'cloud-arrow-down', 'exams.collect'],
        'jobs' => ['/jobs', 'Jobs & history', 'clock-history', 'exams.collect'],
    ],
    'System' => [
        'users' => ['/users', 'Users', 'people', 'users.manage'],
        'branding' => ['/settings/branding', 'Branding', 'brush', 'settings.manage'],
        'settings' => ['/settings', 'Settings', 'gear', 'settings.manage'],
    ],
];
// Only the pages this person may open.
foreach ($groups as $heading => $links) {
    $groups[$heading] = $user ? array_filter($links, static fn(array $l): bool => can($l[3])) : [];
    if ($groups[$heading] === []) {
        unset($groups[$heading]);
    }
}
$flashIcons = ['success' => 'check-circle', 'info' => 'info-circle', 'warning' => 'exclamation-triangle', 'danger' => 'exclamation-octagon'];
?><!doctype html>
<html lang="en"<?php if ($user): ?>
      data-prefs="server"
      data-theme="<?= e(Appearance::initialTheme($prefs)) ?>"
      data-theme-choice="<?= e($prefs['theme']) ?>"
      data-accent="<?= e($accent) ?>"
      data-density="<?= e($prefs['density']) ?>"
      data-surface="<?= e($prefs['surface']) ?>"<?= $prefs['motion'] === 'reduced' ? ' data-motion="reduced"' : '' ?><?php else: ?>
      data-theme="<?= e(Appearance::initialTheme($prefs)) ?>" data-theme-choice="<?= e($prefs['theme']) ?>" data-accent="<?= e($accent) ?>" data-density="comfortable" data-surface="classic"<?php endif; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e(url('')) ?>">
    <title><?= e($title !== '' ? $title . ' · ' : '') ?><?= e($appName) ?></title>
    <?php if ($logoUrl !== null): ?><link rel="icon" href="<?= e($logoUrl) ?>" type="<?= str_contains($logoUrl, '.svg') ? 'image/svg+xml' : 'image/png' ?>"><?php else: ?><link rel="icon" href="<?= e(asset('/assets/img/icon.svg')) ?>" type="image/svg+xml"><?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('/assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/layout.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/components.css')) ?>">
    <?php if ($brand['accent'] === 'custom'): ?><link rel="stylesheet" href="<?= e(url('/branding/theme.css', ['v' => substr(md5($brand['color']), 0, 8)])) ?>"><?php endif; ?>
    <script src="<?= e(asset('/assets/js/theme-init.js')) ?>"></script>
</head>
<?php if ($user): ?>
<body>
<a class="skip-link" href="#page-body">Skip to content</a>
<div class="app" data-sidebar="expanded" data-drawer="closed">
    <aside class="app-sidebar" id="app-sidebar" aria-label="Main navigation">
        <a class="sidebar-brand" href="<?= e(url('/')) ?>" title="<?= e($appName) ?>">
            <?php if ($logoUrl !== null): ?><img class="brand-logo" src="<?= e($logoUrl) ?>" alt=""><?php else: ?><span class="brand-mark"><?= icon('mortarboard-fill') ?></span><?php endif; ?><span class="sidebar-label"><?= e($appName) ?></span>
        </a>
        <nav class="sidebar-nav">
            <?php foreach ($groups as $heading => $links): ?>
                <div class="sidebar-heading"><?= e($heading) ?></div>
                <?php foreach ($links as $key => [$href, $label, $iconName]): $active = ($nav ?? '') === $key; ?>
                    <a class="nav-link<?= $active ? ' is-active' : '' ?>" href="<?= e(url($href)) ?>" title="<?= e($label) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                        <?= icon($iconName) ?><span class="sidebar-label"><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <?php if ($collector): ?>
        <div class="sidebar-footer" title="Background worker">
            <span class="status-dot" data-worker-dot></span><span class="sidebar-label" data-worker-text>No jobs running</span>
        </div>
        <?php endif; ?>
    </aside>
    <div class="sidebar-backdrop"></div>

    <div class="app-main">
        <header class="app-topbar">
            <div class="topbar-left">
                <button class="icon-btn" type="button" data-action="sidebar-toggle" aria-controls="app-sidebar" aria-label="Toggle the sidebar"><?= icon('list') ?></button>
                <p class="topbar-title"><?= e($title !== '' ? $title : $appName) ?></p>
            </div>
            <button class="search-chip" type="button" data-action="palette-open" aria-label="Go to a page or exam">
                <?= icon('search') ?><span class="search-placeholder">Go to a page or exam</span><kbd>Ctrl K</kbd>
            </button>
            <div class="topbar-right">
                <?php if ($collector): ?>
                <a id="active-indicator" class="topbar-chip is-running" hidden href="<?= e(url('/jobs', ['status' => 'running'])) ?>" data-active-url="<?= e(url('/api/jobs/active')) ?>" title="Running jobs">
                    <?= icon('arrow-repeat', 'spin') ?><span class="chip-text label">Job running</span>
                </a>
                <?php endif; ?>
                <button class="icon-btn" type="button" data-action="theme-toggle" data-save-url="<?= e(url('/api/appearance')) ?>" aria-label="Switch between light and dark">
                    <span class="when-light"><?= icon('moon-stars') ?></span><span class="when-dark"><?= icon('sun') ?></span>
                </button>
                <div class="user-menu" data-open="false">
                    <button class="user-menu-trigger" type="button" data-action="user-menu-toggle" aria-haspopup="true" aria-expanded="false">
                        <span class="avatar"><?= e(initials(\App\Services\Access::displayName($user))) ?></span>
                        <span class="user-name"><?= e(\App\Services\Access::displayName($user)) ?></span><?= icon('chevron-down', 'caret') ?>
                    </button>
                    <div class="user-menu-panel" hidden>
                        <div class="menu-header"><div class="name"><?= e(\App\Services\Access::displayName($user)) ?></div><div class="meta"><?= e(\App\Services\Access::roleName((string) $user['role'])) ?> · <?= e($user['username']) ?></div></div>
                        <a class="menu-item" href="<?= e(url('/account')) ?>"><?= icon('person-circle') ?>Account</a>
                        <a class="menu-item" href="<?= e(url('/account')) ?>#appearance"><?= icon('palette') ?>Appearance</a>
                        <?php if (can('settings.manage')): ?><a class="menu-item" href="<?= e(url('/settings')) ?>"><?= icon('gear') ?>Settings</a><?php endif; ?>
                        <div class="menu-divider"></div>
                        <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?>
                            <button class="menu-item is-danger" type="submit"><?= icon('box-arrow-right') ?>Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="app-content">
            <?php $pending = \App\Core\View::current()->pendingMigrations(); if ($pending !== []): ?>
                <div class="alert alert-warning" role="alert">
                    <?= icon('database-exclamation') ?>
                    <div class="alert-body"><strong>The database needs an update.</strong> On the server, run <code>php bin/migrate.php</code> in the <code>web</code> folder. Until then some settings, such as your theme, cannot be saved. Missing: <?= e(implode(', ', $pending)) ?>.</div>
                </div>
            <?php endif; ?>
            <div class="flash-stack"<?= $flashes === [] ? ' hidden' : '' ?>>
                <?php foreach ($flashes as $f): $type = in_array($f['type'], ['success', 'info', 'warning', 'danger'], true) ? $f['type'] : 'info'; ?>
                    <div class="alert alert-<?= e($type) ?>" role="<?= $type === 'danger' ? 'alert' : 'status' ?>">
                        <?= icon($flashIcons[$type]) ?><div class="alert-body"><?= e($f['message']) ?></div>
                        <button type="button" class="alert-close" data-dismiss="alert" aria-label="Dismiss"><?= icon('x-lg') ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="page-body" tabindex="-1">
                <?= $content ?>
            </div>
        </main>
    </div>
</div>

<div class="palette" id="palette" hidden data-source="<?= e(url('/api/palette')) ?>">
    <div class="palette-backdrop" data-action="palette-close"></div>
    <div class="palette-panel" role="dialog" aria-modal="true" aria-label="Go to">
        <div class="palette-search"><?= icon('search') ?><input id="palette-input" type="text" placeholder="Go to a page or exam" autocomplete="off" spellcheck="false" aria-label="Search pages and exams"><kbd>Esc</kbd></div>
        <ul class="palette-list" id="palette-list" role="listbox">
            <?php foreach ($groups as $heading => $links): foreach ($links as [$href, $label, $iconName]): ?>
                <li class="palette-item" role="option" data-path="<?= e(url($href)) ?>" data-label="<?= e(strtolower($label)) ?>"><?= icon($iconName) ?><span class="palette-label"><?= e($label) ?></span><span class="palette-section">Page</span></li>
            <?php endforeach; endforeach; ?>
            <li class="palette-item" role="option" data-path="<?= e(url('/account')) ?>" data-label="account profile password email"><?= icon('person-circle') ?><span class="palette-label">Account</span><span class="palette-section">Page</span></li>
            <li class="palette-item" role="option" data-path="<?= e(url('/account')) ?>#appearance" data-label="appearance theme dark light accent"><?= icon('palette') ?><span class="palette-label">Appearance</span><span class="palette-section">Page</span></li>
        </ul>
        <div class="palette-empty" hidden>Nothing matches.</div>
        <div class="palette-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> close</span></div>
    </div>
</div>
<?php else: ?>
<body class="is-signed-out">
<main class="auth-page">
    <button class="icon-btn auth-theme" type="button" data-action="theme-toggle" aria-label="Switch between light and dark">
        <span class="when-light"><?= icon('moon-stars') ?></span><span class="when-dark"><?= icon('sun') ?></span>
    </button>
    <div class="auth-wrap">
        <div class="auth-brand">
            <?php if ($logoUrl !== null): ?><img class="brand-logo-lg" src="<?= e($logoUrl) ?>" alt=""><?php else: ?><span class="brand-mark brand-mark-lg"><?= icon('mortarboard-fill') ?></span><?php endif; ?>
            <span class="name"><?= e($appName) ?></span>
            <?php if ($brand['tagline'] !== ''): ?><span class="tagline"><?= e($brand['tagline']) ?></span><?php endif; ?>
        </div>
        <?php foreach ($flashes as $f): $type = in_array($f['type'], ['success', 'info', 'warning', 'danger'], true) ? $f['type'] : 'info'; ?>
            <div class="alert alert-<?= e($type) ?>" role="status"><?= icon($flashIcons[$type]) ?><div class="alert-body"><?= e($f['message']) ?></div></div>
        <?php endforeach; ?>
        <?= $content ?>
    </div>
</main>
<?php endif; ?>
<script src="<?= e(asset('/assets/js/app.js')) ?>"></script>
<script src="<?= e(asset('/assets/js/data-table.js')) ?>"></script>
</body>
</html>
