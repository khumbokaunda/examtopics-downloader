<?php
/**
 * The page shell. Signed in: sidebar, top bar and content. Signed out: one centred card.
 * Appearance preferences are rendered into attributes on <html>; js/theme-init.js resolves a
 * "system" theme before first paint (it is an external file: the CSP allows no inline script).
 *
 * @var string $title @var string $content @var array|null $user @var list<array{type:string,message:string}> $flashes @var string $nav
 */
use App\Services\Appearance;

$appName = (string) \App\Core\View::current()->config('app_name', 'Examtopics');
$prefs = $user ? Appearance::forUser($user) : null;
$groups = [
    'Overview' => [
        'dashboard' => ['/', 'Dashboard', 'grid-1x2'],
    ],
    'Collect' => [
        'collect' => ['/collect', 'Collect exam', 'cloud-arrow-down'],
        'providers' => ['/providers', 'Providers & inventory', 'buildings'],
        'jobs' => ['/jobs', 'Jobs & history', 'clock-history'],
    ],
    'Study' => [
        'library' => ['/library', 'Library', 'journal-bookmark'],
    ],
    'System' => [
        'settings' => ['/settings', 'Settings', 'gear'],
    ],
];
$flashIcons = ['success' => 'check-circle', 'info' => 'info-circle', 'warning' => 'exclamation-triangle', 'danger' => 'exclamation-octagon'];
?><!doctype html>
<html lang="en"<?php if ($prefs !== null): ?>
      data-prefs="server"
      data-theme="<?= e(Appearance::initialTheme($prefs)) ?>"
      data-theme-choice="<?= e($prefs['theme']) ?>"
      data-accent="<?= e($prefs['accent']) ?>"
      data-density="<?= e($prefs['density']) ?>"
      data-surface="<?= e($prefs['surface']) ?>"<?= $prefs['motion'] === 'reduced' ? ' data-motion="reduced"' : '' ?><?php else: ?>
      data-theme="light" data-accent="teal" data-density="comfortable" data-surface="classic"<?php endif; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e(url('')) ?>">
    <title><?= e($title !== '' ? $title . ' · ' : '') ?><?= e($appName) ?></title>
    <link rel="icon" href="<?= e(asset('/assets/img/icon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/layout.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/components.css')) ?>">
    <script src="<?= e(asset('/assets/js/theme-init.js')) ?>"></script>
</head>
<?php if ($user): ?>
<body>
<a class="skip-link" href="#page-body">Skip to content</a>
<div class="app" data-sidebar="expanded" data-drawer="closed">
    <aside class="app-sidebar" id="app-sidebar" aria-label="Main navigation">
        <a class="sidebar-brand" href="<?= e(url('/')) ?>" title="<?= e($appName) ?>">
            <span class="brand-mark"><?= icon('mortarboard-fill') ?></span><span class="sidebar-label"><?= e($appName) ?></span>
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
        <div class="sidebar-footer" title="Background worker">
            <span class="status-dot" data-worker-dot></span><span class="sidebar-label" data-worker-text>No jobs running</span>
        </div>
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
                <a id="active-indicator" class="topbar-chip is-running" hidden href="<?= e(url('/jobs', ['status' => 'running'])) ?>" data-active-url="<?= e(url('/api/jobs/active')) ?>" title="Running jobs">
                    <?= icon('arrow-repeat', 'spin') ?><span class="chip-text label">Job running</span>
                </a>
                <button class="icon-btn" type="button" data-action="theme-toggle" data-save-url="<?= e(url('/api/appearance')) ?>" aria-label="Switch between light and dark">
                    <span class="when-light"><?= icon('moon-stars') ?></span><span class="when-dark"><?= icon('sun') ?></span>
                </button>
                <div class="user-menu" data-open="false">
                    <button class="user-menu-trigger" type="button" data-action="user-menu-toggle" aria-haspopup="true" aria-expanded="false">
                        <span class="avatar"><?= e(initials((string) $user['username'])) ?></span>
                        <span class="user-name"><?= e($user['username']) ?></span><?= icon('chevron-down', 'caret') ?>
                    </button>
                    <div class="user-menu-panel" hidden>
                        <div class="menu-header"><div class="name"><?= e($user['username']) ?></div><div class="meta">Signed in</div></div>
                        <a class="menu-item" href="<?= e(url('/settings')) ?>#appearance"><?= icon('palette') ?>Appearance</a>
                        <a class="menu-item" href="<?= e(url('/settings')) ?>"><?= icon('gear') ?>Settings</a>
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
            <li class="palette-item" role="option" data-path="<?= e(url('/settings')) ?>#appearance" data-label="appearance theme dark light accent"><?= icon('palette') ?><span class="palette-label">Appearance</span><span class="palette-section">Page</span></li>
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
            <span class="brand-mark brand-mark-lg"><?= icon('mortarboard-fill') ?></span>
            <span class="name"><?= e($appName) ?></span>
            <span class="tagline">Your private exam study library</span>
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
