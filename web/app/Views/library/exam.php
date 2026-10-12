<?php
/** @var array $provider @var array $exam @var list<array> $versions @var array $selected @var list<array> $documents
 *  @var array<string,array> $variants @var bool $imagesAvailable @var list<array> $jobs @var array|null $activeJob */
$base = '/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']);
$vBase = $base . '/' . rawurlencode($selected['version']);
$isLatest = (int) $exam['latest_version_id'] === (int) $selected['id'];
$docIndex = [];
foreach ($documents as $d) {
    $docIndex[$d['variant']][$d['format']][] = $d;
}
$warnings = json_decode((string) $selected['warnings'], true) ?: [];
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= e(url('/library')) ?>">Library</a></li>
    <li class="breadcrumb-item"><?= e($provider['name'] ?: $provider['slug']) ?></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e(strtoupper($exam['code'])) ?></li>
</ol></nav>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><?= e(strtoupper($exam['code'])) ?></h1>
        <?php if ($exam['name'] && strtolower($exam['name']) !== $exam['code']): ?><div class="text-muted"><?= e($exam['name']) ?></div><?php endif; ?>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-primary" href="<?= e(url($base . '/view', ['version' => $selected['version']])) ?>">Read</a>
        <a class="btn btn-outline-primary" href="<?= e(url($base . '/view', ['version' => $selected['version'], 'mode' => 'practice'])) ?>">Practice</a>
        <a class="btn btn-outline-secondary" href="<?= e(url('/collect', ['provider' => $provider['slug'], 'exam' => $exam['code']])) ?>">Refresh…</a>
    </div>
</div>

<?php if ($activeJob): ?>
<div class="card shadow-sm mb-4"><div class="card-body">
    <div class="fw-semibold mb-2">A refresh is running (job #<?= (int) $activeJob['id'] ?>). This version stays available until the new one passes validation.</div>
    <?= \App\Core\View::current()->partial('partials/job_progress', ['job' => $activeJob, 'reload' => true]) ?>
</div></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-semibold">Version <?= e($selected['version']) ?></span>
                <span><?= status_badge($selected['status']) ?> <?= $isLatest ? '<span class="badge text-bg-info">latest complete</span>' : '' ?></span>
            </div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-sm-4">Collected</dt><dd class="col-sm-8"><?= e(fmt_time($selected['collected_at'])) ?></dd>
                    <dt class="col-sm-4">Questions</dt><dd class="col-sm-8"><?= (int) $selected['collected_count'] ?><?= $selected['reported_count'] !== null ? ' (inventory reported ' . (int) $selected['reported_count'] . ')' : '' ?></dd>
                    <dt class="col-sm-4">Source</dt><dd class="col-sm-8"><?= e($selected['source'] === 'github-cache' ? 'Public cached dataset' : 'Live scrape') ?></dd>
                    <dt class="col-sm-4">Size / checksum</dt><dd class="col-sm-8"><?= e(fmt_bytes($selected['size_bytes'])) ?> · <code class="small"><?= e(substr($selected['checksum'], 0, 16)) ?>…</code></dd>
                    <dt class="col-sm-4">Tool revision</dt><dd class="col-sm-8"><code><?= e($selected['tool_revision'] ?: 'n/a') ?></code></dd>
                </dl>
                <?php foreach ($warnings as $w): ?><div class="alert alert-warning small py-2 mt-2 mb-0"><?= e((string) $w) ?></div><?php endforeach; ?>
                <div class="mt-3"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($vBase . '/json')) ?>">Download JSON</a></div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold">Documents for this version</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Variant</th><th>Markdown</th><th>PDF</th></tr></thead>
                    <tbody>
                    <?php foreach ($variants as $key => $v): ?>
                        <tr>
                            <td><div class="fw-semibold small"><?= e($v['label']) ?></div></td>
                            <?php foreach (['md', 'pdf'] as $fmt): ?>
                                <td class="text-nowrap">
                                    <?php foreach ($docIndex[$key][$fmt] ?? [] as $d): ?>
                                        <?php if ($d['status'] === 'ready'): ?>
                                            <a class="btn btn-sm btn-success mb-1" href="<?= e(url('/documents/' . (int) $d['id'] . '/download')) ?>" title="<?= e($d['filename']) ?>">Download<?= !empty(json_decode((string) $d['options'], true)['images']) ? ' (images)' : '' ?> <span class="small opacity-75"><?= e(fmt_bytes($d['size_bytes'])) ?></span></a>
                                            <form class="d-inline" method="post" action="<?= e(url('/documents/' . (int) $d['id'] . '/delete')) ?>" data-confirm="Delete this generated document? The cached exam data is not affected."><?= csrf_field() ?>
                                                <button class="btn btn-sm btn-link text-danger p-0 ms-1 mb-1" type="submit" aria-label="Delete document">delete</button></form><br>
                                        <?php else: ?>
                                            <span class="badge text-bg-danger mb-1" title="<?= e($d['error'] ?? '') ?>">generation failed</span><br>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <form class="d-inline" method="post" action="<?= e(url($vBase . '/documents')) ?>" data-once data-busy="Generating…"><?= csrf_field() ?>
                                        <input type="hidden" name="variant" value="<?= e($key) ?>"><input type="hidden" name="format" value="<?= $fmt ?>">
                                        <?php if ($fmt === 'pdf' && $imagesAvailable): ?>
                                            <label class="small me-1"><input type="checkbox" name="images" value="1"> images</label>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-primary" type="submit"><?= empty($docIndex[$key][$fmt]) ? 'Generate' : 'Get' ?></button>
                                    </form>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-muted">Existing documents are reused when nothing has changed. PDFs of large exams can take a minute to build.</div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold">Version history</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($versions as $v): $current = (int) $v['id'] === (int) $selected['id']; $latest = (int) $exam['latest_version_id'] === (int) $v['id']; ?>
                    <li class="list-group-item <?= $current ? 'list-group-item-primary' : '' ?>">
                        <div class="d-flex justify-content-between">
                            <a href="<?= e(url($base, ['version' => $v['version']])) ?>" class="small fw-semibold"><?= e(fmt_time($v['collected_at'])) ?></a>
                            <span><?= status_badge($v['status']) ?></span>
                        </div>
                        <div class="small text-muted"><?= (int) $v['collected_count'] ?> questions · <?= (int) $v['document_count'] ?> docs<?= $latest ? ' · latest complete' : '' ?></div>
                        <?php if (!$latest): ?>
                            <form method="post" action="<?= e(url($base . '/' . rawurlencode($v['version']) . '/delete')) ?>" data-confirm="Permanently delete this version's cached data and its generated documents? Other versions are not affected."><?= csrf_field() ?>
                                <button class="btn btn-link btn-sm text-danger p-0" type="submit">Delete version</button></form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Job history</div>
            <ul class="list-group list-group-flush">
                <?php if ($jobs === []): ?><li class="list-group-item small text-muted">No jobs.</li><?php endif; ?>
                <?php foreach ($jobs as $j): ?>
                    <li class="list-group-item small d-flex justify-content-between">
                        <a href="<?= e(url('/jobs/' . (int) $j['id'])) ?>">#<?= (int) $j['id'] ?> <?= e(fmt_time($j['queued_at'])) ?></a>
                        <?= status_badge($j['status']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
