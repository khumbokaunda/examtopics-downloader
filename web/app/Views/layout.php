<?php
/** @var string $title @var string $content @var array|null $user @var list<array{type:string,message:string}> $flashes @var string $nav */
$items = [
    'dashboard' => ['/', 'Dashboard'],
    'collect' => ['/collect', 'Collect Exam'],
    'library' => ['/library', 'Library'],
    'providers' => ['/providers', 'Providers & Inventory'],
    'jobs' => ['/jobs', 'Jobs & History'],
    'settings' => ['/settings', 'Settings'],
];
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e(url('')) ?>">
    <title><?= e($title !== '' ? $title . ' · ' : '') ?>Examtopics</title>
    <link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
</head>
<body class="<?= $user ? 'app' : 'app-auth' ?>">
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg app-nav sticky-top" data-bs-theme="dark">
    <div class="container-xxl">
        <a class="navbar-brand fw-semibold" href="<?= e(url('/')) ?>"><span class="brand-mark">EL</span> Examtopics</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto">
                <?php foreach ($items as $key => [$href, $label]): ?>
                    <li class="nav-item"><a class="nav-link<?= ($nav ?? '') === $key ? ' active' : '' ?>" <?= ($nav ?? '') === $key ? 'aria-current="page"' : '' ?> href="<?= e(url($href)) ?>"><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <a id="active-indicator" class="badge rounded-pill text-bg-primary me-3 d-none text-decoration-none" href="<?= e(url('/jobs', ['status' => 'running'])) ?>" data-active-url="<?= e(url('/api/jobs/active')) ?>">
                <span class="spinner-grow spinner-grow-sm me-1" aria-hidden="true"></span><span class="label">Job running</span>
            </a>
            <span class="navbar-text me-2 small"><?= e($user['username']) ?></span>
            <form method="post" action="<?= e(url('/logout')) ?>" class="d-inline"><?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-light" type="submit">Sign out</button>
            </form>
        </div>
    </div>
</nav>
<?php endif; ?>
<main class="container-xxl py-4">
    <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= e(in_array($f['type'], ['success', 'info', 'warning', 'danger'], true) ? $f['type'] : 'info') ?> alert-dismissible fade show" role="alert">
            <?= e($f['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<script src="<?= e(url('/assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('/assets/app.js')) ?>"></script>
</body>
</html>
