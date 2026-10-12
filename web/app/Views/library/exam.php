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
$variantIcons = ['study' => 'journal-text', 'discussions' => 'chat-left-text', 'practice' => 'patch-question', 'practice_key' => 'key'];
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/library')) ?>">Library</a></li>
    <li class="breadcrumb-item"><?= e($provider['name'] ?: $provider['slug']) ?></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e(strtoupper($exam['code'])) ?></li>
</ol></nav>

<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e(strtoupper($exam['code'])) ?></h1>
        <p class="page-subtitle"><?= $exam['name'] && strtolower($exam['name']) !== $exam['code'] ? e($exam['name']) . ' · ' : '' ?><?= e($provider['name'] ?: $provider['slug']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= e(url('/collect', ['provider' => $provider['slug'], 'exam' => $exam['code']])) ?>"><?= icon('arrow-clockwise') ?>Refresh…</a>
        <a class="btn btn-outline-primary" href="<?= e(url($base . '/view', ['version' => $selected['version'], 'mode' => 'practice'])) ?>"><?= icon('patch-question') ?>Practice</a>
        <a class="btn btn-primary" href="<?= e(url($base . '/view', ['version' => $selected['version']])) ?>"><?= icon('book') ?>Read</a>
    </div>
</header>

<?php if ($activeJob): ?>
<div class="card mb-4"><div class="card-body">
    <div class="job-item-title">A refresh is running (job #<?= (int) $activeJob['id'] ?>). This version stays available until the new one passes validation.</div>
    <?= \App\Core\View::current()->partial('partials/job_progress', ['job' => $activeJob, 'reload' => true]) ?>
</div></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card mb-4">
            <div class="card-header">
                <span class="card-title"><?= icon('archive') ?>Version <span class="mono fw-normal small"><?= e($selected['version']) ?></span></span>
                <span class="chip-group"><?= status_badge($selected['status']) ?><?= $isLatest ? chip('latest complete', 'info', 'star') : '' ?></span>
            </div>
            <div class="card-body">
                <dl class="meta-list">
                    <dt>Collected</dt><dd><?= e(fmt_time($selected['collected_at'])) ?></dd>
                    <dt>Questions</dt><dd><?= number_format((int) $selected['collected_count']) ?><?= $selected['reported_count'] !== null ? ' <span class="text-muted">(inventory reported ' . (int) $selected['reported_count'] . ')</span>' : '' ?></dd>
                    <dt>Source</dt><dd><?= e($selected['source'] === 'github-cache' ? 'Public cached dataset' : 'Live scrape') ?></dd>
                    <dt>Size and checksum</dt><dd><?= e(fmt_bytes($selected['size_bytes'])) ?> · <code><?= e(substr($selected['checksum'], 0, 16)) ?>…</code></dd>
                    <dt>Tool revision</dt><dd><code><?= e($selected['tool_revision'] ?: 'n/a') ?></code></dd>
                </dl>
                <?php foreach ($warnings as $w): ?><div class="alert alert-warning small mt-3 mb-0"><?= icon('exclamation-triangle') ?><div class="alert-body"><?= e((string) $w) ?></div></div><?php endforeach; ?>
                <div class="mt-4"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($vBase . '/json')) ?>"><?= icon('braces') ?>Download JSON</a></div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('file-earmark-text') ?>Documents for this version</span></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Variant</th><th><?= icon('filetype-md') ?> Markdown</th><th><?= icon('filetype-pdf') ?> PDF</th></tr></thead>
                    <tbody>
                    <?php foreach ($variants as $key => $v): ?>
                        <tr>
                            <td class="cell-wrap"><span class="d-inline-flex align-items-center gap-2 fw-medium"><span class="icon-bubble" data-tone="primary"><?= icon($variantIcons[$key] ?? 'file-earmark') ?></span><?= e($v['label']) ?></span></td>
                            <?php foreach (['md', 'pdf'] as $fmt): ?>
                                <td>
                                    <div class="d-flex flex-column align-items-start gap-1">
                                    <?php foreach ($docIndex[$key][$fmt] ?? [] as $d): ?>
                                        <?php if ($d['status'] === 'ready'): ?>
                                            <span class="d-inline-flex align-items-center gap-1">
                                                <a class="btn btn-sm btn-success" href="<?= e(url('/documents/' . (int) $d['id'] . '/download')) ?>" title="<?= e($d['filename']) ?>"><?= icon('download') ?>Download<?= !empty(json_decode((string) $d['options'], true)['images']) ? ' (images)' : '' ?> <span class="opacity-75 fw-normal"><?= e(fmt_bytes($d['size_bytes'])) ?></span></a>
                                                <form method="post" action="<?= e(url('/documents/' . (int) $d['id'] . '/delete')) ?>" data-confirm="The cached exam data is not affected. You can generate it again later." data-confirm-title="Delete this document?" data-confirm-button="Delete" data-confirm-tone="danger"><?= csrf_field() ?>
                                                    <button class="icon-btn btn-link-danger" type="submit" aria-label="Delete document" title="Delete document"><?= icon('trash3') ?></button></form>
                                            </span>
                                        <?php else: ?>
                                            <?= chip('generation failed', 'danger', 'x-circle') ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php $ready = array_filter($docIndex[$key][$fmt] ?? [], static fn($d) => $d['status'] === 'ready'); ?>
                                    <?php if ($ready === [] || ($fmt === 'pdf' && $imagesAvailable)): ?>
                                    <form class="d-inline-flex align-items-center gap-2" method="post" action="<?= e(url($vBase . '/documents')) ?>" data-once data-busy="Generating…"><?= csrf_field() ?>
                                        <input type="hidden" name="variant" value="<?= e($key) ?>"><input type="hidden" name="format" value="<?= $fmt ?>">
                                        <?php if ($fmt === 'pdf' && $imagesAvailable): ?>
                                            <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="images" value="1"> <span class="form-check-label">images</span></label>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon('plus-lg') ?><?= empty($docIndex[$key][$fmt]) ? 'Generate' : ($ready === [] ? 'Retry' : 'Generate') ?></button>
                                    </form>
                                    <?php endif; ?>
                                    </div>
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

    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('layers') ?>Version history</span></div>
            <ul class="list-rows">
                <?php foreach ($versions as $v): $current = (int) $v['id'] === (int) $selected['id']; $latest = (int) $exam['latest_version_id'] === (int) $v['id']; ?>
                    <li class="<?= $current ? 'is-current' : '' ?>">
                        <div class="row-text">
                            <a class="row-title" href="<?= e(url($base, ['version' => $v['version']])) ?>"<?= $current ? ' aria-current="true"' : '' ?>><?= e(fmt_time($v['collected_at'])) ?></a>
                            <div class="row-meta"><?= number_format((int) $v['collected_count']) ?> questions · <?= (int) $v['document_count'] ?> documents<?= $latest ? ' · latest complete' : '' ?></div>
                        </div>
                        <div class="row-side">
                            <?= status_badge($v['status']) ?>
                            <?php if (!$latest): ?>
                                <form method="post" action="<?= e(url($base . '/' . rawurlencode($v['version']) . '/delete')) ?>" data-confirm="This version's cached data and its generated documents are deleted permanently. Other versions are not affected." data-confirm-title="Delete this version?" data-confirm-button="Delete version" data-confirm-tone="danger"><?= csrf_field() ?>
                                    <button class="btn btn-sm btn-link btn-link-danger p-0" type="submit">Delete</button></form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('clock-history') ?>Job history</span></div>
            <?php if ($jobs === []): ?>
                <div class="card-body"><p class="text-muted small mb-0">No jobs for this exam.</p></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($jobs as $j): ?>
                    <li>
                        <a class="row-title" href="<?= e(url('/jobs/' . (int) $j['id'])) ?>">#<?= (int) $j['id'] ?></a>
                        <span class="row-time me-auto"><?= e(fmt_time($j['queued_at'])) ?></span>
                        <?= status_badge($j['status']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
