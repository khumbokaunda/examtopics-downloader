<?php
/**
 * The page shell for sitting an assessment: no sidebar, no inbox, no search, nothing to wander
 * off to. Appearance follows the person's own settings.
 *
 * @var string $title @var string $content @var array $user @var list<array{type:string,message:string}> $flashes
 */
use App\Services\Appearance;

$branding = \App\Core\View::current()->branding();
$brand = $branding->get();
$prefs = Appearance::forUser($user, $brand);
?><!doctype html>
<html lang="en" data-prefs="server" data-theme="<?= e(Appearance::initialTheme($prefs)) ?>" data-theme-choice="<?= e($prefs['theme']) ?>"
      data-accent="<?= e(Appearance::pageAccent($prefs, $brand)) ?>" data-density="<?= e($prefs['density']) ?>" data-surface="<?= e($prefs['surface']) ?>"<?= $prefs['motion'] === 'reduced' ? ' data-motion="reduced"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e(url('')) ?>">
    <title><?= e($title) ?> · <?= e($brand['name']) ?></title>
    <link rel="icon" href="<?= e($branding->logoUrl() ?? asset('/assets/img/icon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/layout.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/components.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/dark.css')) ?>">
    <?php if ($brand['accent'] === 'custom'): ?><link rel="stylesheet" href="<?= e(url('/branding/theme.css', ['v' => substr(md5($brand['color']), 0, 8)])) ?>"><?php endif; ?>
    <script src="<?= e(asset('/assets/js/theme-init.js')) ?>"></script>
</head>
<body class="sitting-body">
<main class="sitting-shell" id="page-body">
    <?php foreach ($flashes as $f): $type = in_array($f['type'], ['success', 'info', 'warning', 'danger'], true) ? $f['type'] : 'info'; ?>
        <div class="alert alert-<?= e($type) ?>" role="status"><div class="alert-body"><?= e($f['message']) ?></div></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<script src="<?= e(asset('/assets/js/app.js')) ?>"></script>
<script src="<?= e(asset('/assets/js/sitting.js')) ?>"></script>
</body>
</html>
