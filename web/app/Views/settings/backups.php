<?php
/** @var list<array{name:string,size:int,modified:int,manifest:array|null}> $backups @var array<string,mixed>|null $status
 *  @var array<string,int> $sizes @var int $uploadLimit @var bool $zip @var string $dir @var array|null $last
 *  @var list<array{id:string,name:string,size:int,received:int,updated:int}> $pending @var int $confirmedFor @var bool $withCode */
$running = ($status['status'] ?? '') === 'running';
$restoring = ($status['kind'] ?? 'backup') === 'restore';
$areaLabels = ['cache' => 'Exam data', 'inventory' => 'Question links', 'branding' => 'Logo', 'uploads' => 'Pictures added by hand', 'media' => 'Saved images', 'documents' => 'Built documents'];
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

<?php if ($confirmedFor === 0): ?>
<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-end gap-3">
        <div class="flex-grow-1">
            <div class="fw-semibold"><?= icon('shield-lock') ?> Confirm it is you to make, download, upload, restore or delete backups</div>
            <div class="small text-muted"><?= $withCode ? 'Type the code from your authenticator app.' : 'Type your password.' ?> It is good for the next ten minutes.</div>
        </div>
        <form method="post" action="<?= e(url('/confirm')) ?>" class="d-flex flex-wrap gap-2" data-once><?= csrf_field() ?>
            <input type="hidden" name="next" value="/backups">
            <?php if ($withCode): ?>
                <input class="form-control code-input flex-grow-0" name="code" maxlength="20" autocomplete="one-time-code" placeholder="000000" aria-label="Code from your app" required>
            <?php else: ?>
                <input class="form-control w-auto" name="password" type="password" autocomplete="current-password" placeholder="Password" aria-label="Your password" required>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= icon('check2-circle') ?>Confirm</button>
        </form>
    </div>
</div>
<?php else: ?>
    <p class="small text-muted"><?= icon('shield-check') ?> Confirmed for <?= e(\App\Services\Auth::minutes($confirmedFor)) ?> more. After that, backups ask again.</p>
<?php endif; ?>
<?php if (!$zip): ?>
    <div class="alert alert-danger"><?= icon('exclamation-octagon') ?><div class="alert-body">The PHP zip extension is missing, so backups cannot be made or read. On the server: <code>apt install php-zip</code>, then restart PHP.</div></div>
<?php endif; ?>
<?php if ($status !== null && ($running || in_array($status['status'], ['failed', 'cancelled'], true))): ?>
<div class="card mb-4" id="backup-progress">
    <div class="card-header">
        <span class="card-title"><?= icon($restoring ? 'arrow-counterclockwise' : 'archive') ?><?= $running ? ($restoring ? 'Restoring a backup' : 'Making a backup') : ($restoring ? 'The last restore did not finish' : 'The last backup did not finish') ?></span>
        <?php if ($running && !$restoring): ?>
            <form method="post" action="<?= e(url('/backups/cancel')) ?>" data-confirm="The unfinished backup file is deleted. Backups already made are kept." data-confirm-title="Cancel this backup?" data-confirm-button="Cancel backup" data-confirm-tone="danger"><?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-danger" type="submit"><?= icon('x-circle') ?>Cancel</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="job-progress" data-job-progress data-status-url="<?= e(url('/api/backups/status')) ?>"
             data-final="<?= $running ? '0' : '1' ?>" data-reload-on-finish="1"<?php if ($restoring): ?> data-signed-out-url="<?= e(url('/login', ['restored' => '1'])) ?>"<?php endif; ?>>
            <div class="job-progress-head">
                <div>
                    <span data-field="status"><?= status_badge((string) $status['status']) ?></span>
                    <span class="job-phase" data-field="phase"><?= e((string) $status['phase']) ?></span>
                </div>
                <div class="job-numbers">
                    <span data-field="counts"><?= $status['done'] !== null ? e($status['done'] . ($status['total'] !== null ? ' / ' . $status['total'] : '')) : '' ?></span>
                    <span data-field="elapsed" data-elapsed="<?= e((string) ($status['elapsed_seconds'] ?? '')) ?>"></span>
                </div>
            </div>
            <div class="progress" role="progressbar" aria-label="<?= $restoring ? 'Restore' : 'Backup' ?> progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) ($status['percent'] ?? 0)) ?>">
                <div class="progress-bar" data-field="bar" data-percent="<?= e($status['percent'] === null ? '' : (string) $status['percent']) ?>"></div>
            </div>
            <div class="job-message" data-field="message"><?= e($status['message']) ?></div>
        </div>
        <?php if ($running && $restoring): ?><p class="small text-muted mb-0 mt-2">It runs on the server, so closing this page does not stop it. When it finishes, everyone, you included, is signed out, and this page goes to the sign-in form.</p>
        <?php elseif ($running): ?><p class="small text-muted mb-0 mt-2">You can leave this page: the backup carries on, and it is listed below when it is ready.</p><?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php if (!$restoring && ($status['status'] ?? '') === 'completed' && (int) ($status['finished_at'] ?? 0) > time() - 600): ?>
    <div class="alert alert-success"><?= icon('check-circle') ?><div class="alert-body"><?= e($status['message']) ?> It is at the top of the list below.</div></div>
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
            <?php if ($backups === []): ?>
                <div class="card-body"><?= empty_state('archive', $running ? 'The first backup is being made' : 'No backups yet', $running ? 'It appears here when it is ready.' : 'Make one with the form on this page.', null, true) ?></div>
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
                        <div class="backup-actions">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/backups/' . rawurlencode($b['name']) . '/download')) ?>"><?= icon('download') ?>Download</a>
                            <?php if ($m !== null): ?>
                            <details class="request-answer">
                                <summary class="btn btn-sm btn-outline-danger"><?= icon('arrow-counterclockwise') ?>Restore</summary>
                                <form method="post" action="<?= e(url('/backups/' . rawurlencode($b['name']) . '/restore')) ?>" class="request-answer-body" data-once data-busy="Starting…" data-confirm="Every account, setting and exam record is replaced with the backup's. A backup of the current data is made first. Everyone, you included, is signed out." data-confirm-title="Restore this backup?" data-confirm-button="Restore" data-confirm-tone="danger"><?= csrf_field() ?>
                                    <p class="small mb-2">Every account, setting and exam record is replaced with this backup's, after a safety backup of the current data.</p>
                                    <button class="btn btn-sm btn-danger" type="submit" <?= $running ? 'disabled' : '' ?>>Restore this backup</button>
                                    <div class="form-text">It runs on the server in the background, and this page shows its progress.</div>
                                </form>
                            </details>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url('/backups/' . rawurlencode($b['name']) . '/delete')) ?>" data-confirm="The file is deleted from the server." data-confirm-title="Delete this backup?" data-confirm-button="Delete" data-confirm-tone="danger"><?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit"><?= icon('trash3') ?>Delete</button>
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
                    <p class="small text-muted mb-2">Always included: accounts, settings, requests, <?= e(strtolower($areaLabels['cache'])) ?> (<?= e(fmt_bytes($sizes['cache'] ?? 0)) ?>), <?= e(strtolower($areaLabels['inventory'])) ?>, <?= e(strtolower($areaLabels['uploads'])) ?> (<?= e(fmt_bytes($sizes['uploads'] ?? 0)) ?>) and the logo.</p>
                    <?php foreach (['media', 'documents'] as $area): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="opt-<?= $area ?>" name="<?= $area ?>" value="1" checked>
                            <label class="form-check-label" for="opt-<?= $area ?>"><?= e($areaLabels[$area]) ?> <span class="text-muted">(<?= e(fmt_bytes($sizes[$area] ?? 0)) ?>)</span></label>
                        </div>
                    <?php endforeach; ?>
                    <p class="form-text">Without images they can be downloaded again later; without documents they can be built again.</p>
                    <button class="btn btn-primary" type="submit" <?= $zip && !$running ? '' : 'disabled' ?>><?= icon('archive') ?>Make a backup</button>
                </form>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('upload') ?>Upload a backup</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/backups/upload')) ?>" enctype="multipart/form-data" data-chunked-upload
                      data-start-url="<?= e(url('/backups/uploads')) ?>" data-done-url="<?= e(url('/backups')) ?>"><?= csrf_field() ?>
                    <input class="form-control mb-2" type="file" name="archive" accept=".zip,application/zip" required>
                    <div class="form-text mb-3" data-upload-hint>The file is sent in small pieces, so a large one is fine. If the connection drops or the page closes, choose the same file again and it carries on where it stopped.</div>
                    <div class="job-progress mb-3" data-upload-progress hidden>
                        <div class="job-progress-head">
                            <div><span data-field="status"></span><span class="job-phase" data-field="phase"></span></div>
                            <div class="job-numbers"><span data-field="counts"></span><span data-field="rate"></span></div>
                        </div>
                        <div class="progress" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" data-field="bar"></div></div>
                        <div class="job-message" data-field="message" aria-live="polite"></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-primary" type="submit" data-upload-submit <?= $zip ? '' : 'disabled' ?>><?= icon('upload') ?>Upload</button>
                        <button class="btn btn-outline-danger" type="button" data-upload-cancel hidden><?= icon('x-circle') ?>Cancel upload</button>
                    </div>
                </form>
                <?php if ($pending !== []): ?>
                    <div class="upload-pending">
                        <div class="small fw-semibold">Unfinished uploads</div>
                        <?php foreach ($pending as $p): ?>
                            <div class="upload-pending-row">
                                <div class="small">
                                    <div class="text-break"><?= e($p['name']) ?></div>
                                    <div class="text-muted"><?= e(fmt_bytes($p['received'])) ?> of <?= e(fmt_bytes($p['size'])) ?> (<?= (int) floor(100 * $p['received'] / max(1, $p['size'])) ?>%) · <?= e(fmt_time(gmdate('Y-m-d H:i:s', $p['updated']))) ?>. Choose this file again to carry on.</div>
                                </div>
                                <form method="post" action="<?= e(url('/backups/uploads/' . $p['id'] . '/discard')) ?>"><?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><?= icon('trash3') ?>Discard</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('truck') ?>Moving to a new server</span></div>
            <div class="card-body small">
                <ol class="ps-3 mb-0">
                    <li>Make a backup here with images and documents, and download it.</li>
                    <li>On the new server, install the app (docs/INSTALL.md): code, <code>composer install --no-dev</code>, the database and <code>config.local.php</code>.</li>
                    <li>Either create an account there (<code>php bin/create-user.php</code>), sign in, upload the backup on this page and restore it; or copy it to the server and run <code>php bin/backup.php restore &lt;file&gt;</code> in the <code>web</code> folder.</li>
                    <li>Sign in with your usual account, and point the domain at the new server.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
