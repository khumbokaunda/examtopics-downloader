<?php
/** @var list<array{name:string,size:int,modified:int,manifest:array|null}> $backups @var array{name:string,size:int}|null $running
 *  @var array<string,int> $sizes @var int $uploadLimit @var bool $zip @var string $dir @var array|null $last */
$areaLabels = ['cache' => 'Exam data', 'inventory' => 'Question links', 'branding' => 'Logo', 'media' => 'Saved images', 'documents' => 'Built documents'];
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/settings')) ?>">Settings</a></li>
    <li class="breadcrumb-item active" aria-current="page">Backups</li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Backups</h1>
        <p class="page-subtitle">One file with everything: accounts, settings, the exam data and, if you choose, saved images and built documents. Use it to keep a copy, or to move the system to a new server.</p>
    </div>
</header>

<?php if (!$zip): ?>
    <div class="alert alert-danger"><?= icon('exclamation-octagon') ?><div class="alert-body">The PHP zip extension is missing, so backups cannot be made or read. On the server: <code>apt install php-zip</code>, then restart PHP.</div></div>
<?php endif; ?>
<?php if ($last !== null): ?>
    <div class="alert alert-<?= $last['warnings'] === [] ? 'success' : 'warning' ?>"><?= icon('clock-history') ?><div class="alert-body">
        <strong>Last restore:</strong> <?= e($last['file']) ?>, <?= e(fmt_time($last['at'])) ?> by <?= e($last['by']) ?>, <?= (int) $last['files'] ?> files written.
        <?php if ($last['safety'] !== null): ?>The data from before is kept as <?= e($last['safety']) ?>.<?php endif; ?>
        <?php if ($last['warnings'] !== []): ?><ul class="mb-0 mt-1"><?php foreach ($last['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('archive') ?>Backups on this server</span><span class="small text-muted"><code><?= e($dir) ?></code></span></div>
            <?php if ($running !== null): ?>
                <div class="card-body border-bottom"><?= chip('being made', 'info', 'arrow-repeat') ?> <?= e($running['name']) ?>, <?= e(fmt_bytes($running['size'])) ?> so far. <a href="<?= e(url('/backups')) ?>">Refresh</a></div>
            <?php endif; ?>
            <?php if ($backups === []): ?>
                <div class="card-body"><?= empty_state('archive', 'No backups yet', 'Make one with the form on this page.', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($backups as $b): $m = $b['manifest']; $files = (array) ($m['files'] ?? []); ?>
                    <li class="flex-wrap">
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="<?= str_contains($b['name'], 'before-restore') ? 'warning' : 'primary' ?>"><?= icon('file-earmark-zip') ?></span>
                            <div class="row-text">
                                <div class="row-title text-break"><?= e($b['name']) ?></div>
                                <div class="row-meta"><?= e(fmt_bytes($b['size'])) ?> · <?= e(fmt_time(gmdate('Y-m-d H:i:s', $b['modified']))) ?><?= $m !== null ? ' · by ' . e((string) ($m['exported_by'] ?? '?')) : '' ?></div>
                                <?php if ($m !== null): ?>
                                    <div class="row-meta"><?= number_format(array_sum((array) ($m['tables'] ?? []))) ?> rows<?php foreach ($files as $area => $f): if (is_array($f)): ?> · <?= e($areaLabels[$area] ?? $area) ?> <?= number_format((int) $f['count']) ?><?php endif; endforeach; ?>
                                        <?php foreach (['media', 'documents'] as $area): if (array_key_exists($area, $files) && $files[$area] === null): ?> · <span class="text-muted">no <?= e(strtolower($areaLabels[$area])) ?></span><?php endif; endforeach; ?></div>
                                <?php else: ?><div class="row-meta text-danger">Not readable as a backup.</div><?php endif; ?>
                                <?php if (str_contains($b['name'], 'before-restore')): ?><div class="row-meta">Made automatically before a restore.</div><?php endif; ?>
                            </div>
                        </div>
                        <div class="row-side flex-wrap gap-2">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/backups/' . rawurlencode($b['name']) . '/download')) ?>"><?= icon('download') ?>Download</a>
                            <?php if ($m !== null): ?>
                            <details class="request-answer">
                                <summary class="btn btn-sm btn-outline-danger"><?= icon('arrow-counterclockwise') ?>Restore</summary>
                                <form method="post" action="<?= e(url('/backups/' . rawurlencode($b['name']) . '/restore')) ?>" class="request-answer-body" data-confirm="Every account, setting and exam record is replaced with the backup's. A backup of the current data is made first. Everyone, you included, is signed out." data-confirm-title="Restore this backup?" data-confirm-button="Restore" data-confirm-tone="danger"><?= csrf_field() ?>
                                    <label class="form-label small" for="pw-<?= e(md5($b['name'])) ?>">Your password, to confirm</label>
                                    <input class="form-control form-control-sm mb-2" id="pw-<?= e(md5($b['name'])) ?>" name="password" type="password" autocomplete="current-password" required>
                                    <button class="btn btn-sm btn-danger" type="submit">Restore this backup</button>
                                    <div class="form-text">Large backups are better restored on the server: <code>php bin/backup.php restore <?= e($b['name']) ?></code></div>
                                </form>
                            </details>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url('/backups/' . rawurlencode($b['name']) . '/delete')) ?>" data-confirm="The file is deleted from the server." data-confirm-title="Delete this backup?" data-confirm-button="Delete" data-confirm-tone="danger"><?= csrf_field() ?>
                                <button class="icon-btn btn-link-danger" type="submit" aria-label="Delete backup" title="Delete"><?= icon('trash3') ?></button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <div class="card-footer small text-muted">Backups hold password hashes and the email password. Keep downloaded copies private, and keep one off this server.</div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('plus-circle') ?>Make a backup</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/backups')) ?>" data-once data-busy="Starting…"><?= csrf_field() ?>
                    <p class="small text-muted mb-2">Always included: accounts, settings, requests, <?= e(strtolower($areaLabels['cache'])) ?> (<?= e(fmt_bytes($sizes['cache'] ?? 0)) ?>), <?= e(strtolower($areaLabels['inventory'])) ?> and the logo.</p>
                    <?php foreach (['media', 'documents'] as $area): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="opt-<?= $area ?>" name="<?= $area ?>" value="1" checked>
                            <label class="form-check-label" for="opt-<?= $area ?>"><?= e($areaLabels[$area]) ?> <span class="text-muted">(<?= e(fmt_bytes($sizes[$area] ?? 0)) ?>)</span></label>
                        </div>
                    <?php endforeach; ?>
                    <p class="form-text">Without images they can be downloaded again later; without documents they can be built again.</p>
                    <button class="btn btn-primary" type="submit" <?= $zip && $running === null ? '' : 'disabled' ?>><?= icon('archive') ?>Make a backup</button>
                </form>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('upload') ?>Upload a backup</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/backups/upload')) ?>" enctype="multipart/form-data" data-once data-busy="Uploading…"><?= csrf_field() ?>
                    <input class="form-control mb-2" type="file" name="archive" accept=".zip,application/zip" required>
                    <div class="form-text mb-2">Up to <?= e(fmt_bytes($uploadLimit)) ?> (the server's upload limit). For a larger file, copy it to <code><?= e($dir) ?></code> on the server, or restore it there with <code>php bin/backup.php restore &lt;file&gt;</code>.</div>
                    <button class="btn btn-outline-primary" type="submit"><?= icon('upload') ?>Upload</button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('truck') ?>Moving to a new server</span></div>
            <div class="card-body small">
                <ol class="ps-3 mb-0">
                    <li>Make a backup here with images and documents, and download it.</li>
                    <li>On the new server, install the app (docs/INSTALL.md): code, <code>composer install --no-dev</code>, the database and <code>config.local.php</code>.</li>
                    <li>Copy the backup there and run <code>php bin/backup.php restore &lt;file&gt;</code> in the <code>web</code> folder.</li>
                    <li>Sign in with your usual account, and point the domain at the new server.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
