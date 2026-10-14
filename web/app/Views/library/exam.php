<?php
/** @var array $provider @var array $exam @var list<array> $versions @var array $selected @var list<array> $documents
 *  @var array<string,array> $variants @var array{total:int,saved:int,failed:int,skipped:int,pending:int}|null $images
 *  @var list<array> $jobs @var array|null $activeJob
 *  @var array{check:int,corrected:int,checked:int,fingerprint:?string}|null $answers */
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
    <li class="breadcrumb-item"><?= e(provider_name($provider)) ?></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e(strtoupper($exam['code'])) ?></li>
</ol></nav>

<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e(strtoupper($exam['code'])) ?></h1>
        <p class="page-subtitle"><?= $exam['name'] && strtolower($exam['name']) !== $exam['code'] ? e($exam['name']) . ' · ' : '' ?><?= e(provider_name($provider)) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= e(url('/collect', ['provider' => $provider['slug'], 'exam' => $exam['code']])) ?>"><?= icon('arrow-clockwise') ?>Refresh…</a>
        <a class="btn btn-outline-primary" href="<?= e(url($base . '/view', ['version' => $selected['version'], 'mode' => 'practice'])) ?>"><?= icon('patch-question') ?>Practice</a>
        <a class="btn btn-primary" href="<?= e(url($base . '/view', ['version' => $selected['version']])) ?>"><?= icon('book') ?>Read</a>
    </div>
</header>

<?php if ($activeJob): ?>
<div class="card mb-4"><div class="card-body">
    <div class="job-item-title"><?= ($activeJob['type'] ?? '') === 'images'
        ? 'Images for this exam are being downloaded. They are added when the download finishes.'
        : 'A refresh is running. This version stays available until the new one passes validation.' ?></div>
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
                    <dt>Questions</dt><dd><?= number_format((int) $selected['collected_count']) ?><?php if ($selected['reported_count'] !== null && (int) $selected['reported_count'] !== (int) $selected['collected_count']): ?> <span class="text-muted">(the provider inventory counted <?= number_format((int) $selected['reported_count']) ?><?= (int) $selected['reported_count'] < (int) $selected['collected_count'] ? '; that count is low, refresh the provider to update it' : '' ?>)</span><?php endif; ?></dd>
                    <dt>Source</dt><dd><?= e($selected['source'] === 'github-cache' ? 'Public cached dataset' : 'Live scrape') ?></dd>
                    <dt>Size and checksum</dt><dd><?= e(fmt_bytes($selected['size_bytes'])) ?> · <code><?= e(substr($selected['checksum'], 0, 16)) ?>…</code></dd>
                    <dt>Tool revision</dt><dd><code><?= e($selected['tool_revision'] ?: 'n/a') ?></code></dd>
                    <?php if ($images !== null): ?>
                    <dt>Images</dt>
                    <dd>
                        <?php if ($images['total'] === 0): ?>
                            <span class="text-muted">None in these questions</span>
                        <?php else: $toFetch = $images['failed'] + $images['pending']; ?>
                            <?= number_format($images['saved']) ?> of <?= number_format($images['total']) ?> saved on this server
                            <?php if ($images['skipped'] > 0): ?><span class="text-muted">(<?= (int) $images['skipped'] ?> cannot be saved: not ExamTopics images or too large)</span><?php endif; ?>
                            <?php if ($toFetch > 0 && !$activeJob): ?>
                                <form class="d-inline" method="post" action="<?= e(url($vBase . '/images')) ?>" data-once data-busy="Queuing…"><?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-primary ms-2" type="submit"><?= icon('images') ?>Download <?= $toFetch ?> missing</button></form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </dd>
                    <?php endif; ?>
                    <?php if ($answers !== null): ?>
                    <dt>Answers</dt>
                    <dd>
                        <?php if ($answers['check'] > 0): ?>
                            <a class="btn btn-sm btn-outline-warning me-2" href="<?= e(url($base . '/view', ['version' => $selected['version'], 'review' => '1'])) ?>"><?= icon('exclamation-triangle') ?><?= $answers['check'] ?> to check</a>
                        <?php endif; ?>
                        <?php $done = array_filter([$answers['corrected'] > 0 ? $answers['corrected'] . ' corrected by you' : null, $answers['checked'] > 0 ? $answers['checked'] . ' checked by you' : null]); ?>
                        <span class="<?= $answers['check'] > 0 ? 'text-muted' : '' ?>"><?= $done !== [] ? e(implode(', ', $done)) : ($answers['check'] > 0 ? 'Doubtful answers can be corrected in the reader.' : 'None look doubtful') ?></span>
                    </dd>
                    <?php endif; ?>
                </dl>
                <?php foreach ($warnings as $w): ?><div class="alert alert-warning small mt-3 mb-0"><?= icon('exclamation-triangle') ?><div class="alert-body"><?= e((string) $w) ?></div></div><?php endforeach; ?>
                <div class="mt-4"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($vBase . '/json')) ?>"><?= icon('braces') ?>Download JSON</a></div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('file-earmark-text') ?>Documents for this version</span></div>
            <div class="table-responsive overflow-visible">
                <table class="table align-middle mb-0 table-stack">
                    <thead><tr><th>Variant</th><th><?= icon('filetype-md') ?> Markdown</th><th><?= icon('filetype-pdf') ?> PDF</th></tr></thead>
                    <tbody>
                    <?php foreach ($variants as $key => $v): ?>
                        <tr>
                            <td class="text-wrap stack-title"><span class="d-inline-flex align-items-center gap-2 fw-medium"><span class="icon-bubble" data-tone="primary"><?= icon($variantIcons[$key] ?? 'file-earmark') ?></span><?= e($v['label']) ?></span></td>
                            <?php foreach (['md', 'pdf'] as $fmt): ?>
                                <td data-label="<?= $fmt === 'md' ? 'Markdown' : 'PDF' ?>">
                                    <div class="d-flex flex-column align-items-start gap-1">
                                    <?php foreach ($docIndex[$key][$fmt] ?? [] as $d): ?>
                                        <?php if ($d['status'] === 'ready'): $docParts = \App\Services\DocumentStore::parts($d); ?>
                                            <span class="d-inline-flex align-items-center gap-1">
                                                <?php if ($docParts === []): ?>
                                                <a class="btn btn-sm btn-success" href="<?= e(url('/documents/' . $d['uuid'] . '/download')) ?>" title="<?= e($d['filename']) ?>"><?= icon('download') ?>Download <span class="opacity-75 fw-normal"><?= e(fmt_bytes($d['size_bytes'])) ?></span></a>
                                                <?php else: ?>
                                                <span class="doc-parts" title="Large exams are made in parts, so each fits in the server's memory">
                                                    <?php foreach ($docParts as $i => $part): ?>
                                                        <a class="btn btn-sm btn-success" href="<?= e(url('/documents/' . $d['uuid'] . '/download', ['part' => (string) ($i + 1)])) ?>"><?= icon('download') ?>Part <?= $i + 1 ?> <span class="opacity-75 fw-normal">Q<?= (int) $part['first'] ?><?= (int) $part['last'] !== (int) $part['first'] ? '–' . (int) $part['last'] : '' ?> · <?= e(fmt_bytes($part['size'])) ?></span></a>
                                                    <?php endforeach; ?>
                                                </span>
                                                <?php endif; ?>
                                                <form method="post" action="<?= e(url('/documents/' . $d['uuid'] . '/delete')) ?>" data-confirm="The cached exam data is not affected. You can generate it again later." data-confirm-title="Delete this document?" data-confirm-button="Delete" data-confirm-tone="danger"><?= csrf_field() ?>
                                                    <button class="icon-btn btn-link-danger" type="submit" aria-label="Delete document" title="Delete document"><?= icon('trash3') ?></button></form>
                                            </span>
                                        <?php elseif ($d['status'] === 'building'): ?>
                                            <span class="doc-building" data-doc-status="<?= e(url('/api/documents/' . $d['uuid'])) ?>">
                                                <?= chip('building', 'info', 'arrow-repeat') ?>
                                                <span class="small text-muted" data-field="progress"><?= $d['progress_total'] ? (int) floor((int) $d['progress_done'] * 100 / (int) $d['progress_total']) . '% (' . (int) $d['progress_done'] . ' of ' . (int) $d['progress_total'] . ' questions)' : 'starting' ?></span>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php
                                    // Only the latest failure is shown, as a small marker whose reason opens on hover or click.
                                    $failures = array_filter($docIndex[$key][$fmt] ?? [], static fn($d) => $d['status'] === 'failed');
                                    usort($failures, static fn($a, $b) => strcmp((string) $b['generated_at'], (string) $a['generated_at']));
                                    $failed = $failures[0] ?? null;
                                    $ready = array_filter($docIndex[$key][$fmt] ?? [], static fn($d) => $d['status'] === 'ready');
                                    $building = array_filter($docIndex[$key][$fmt] ?? [], static fn($d) => $d['status'] === 'building') !== [];
                                    // A PDF built before more images were saved can be rebuilt with them, and any
                                    // document made before answers were corrected, with the corrections.
                                    $staleImages = $fmt === 'pdf' && $ready !== [] && $images !== null
                                        && max(array_map(static fn($d) => (int) (json_decode((string) $d['options'], true)['images'] ?? 0), $ready)) < $images['saved'];
                                    $staleEdits = $ready !== [] && $answers !== null
                                        && !in_array($answers['fingerprint'], array_map(static fn($d) => json_decode((string) $d['options'], true)['edits'] ?? null, $ready), true);
                                    // Made with an older layout than the current one.
                                    $staleLayout = $ready !== [] && max(array_map(static fn($d) => (int) (json_decode((string) $d['options'], true)['renderer'] ?? 0), $ready))
                                        < \App\Services\DocumentStore::RENDERER_VERSION;
                                    $stale = $staleImages || $staleEdits || $staleLayout;
                                    ?>
                                    <?php if (!$building && ($ready === [] || $stale || $failed !== null)): ?>
                                    <span class="d-inline-flex align-items-center gap-2">
                                        <?php if ($ready === [] || $stale): ?>
                                        <form method="post" action="<?= e(url($vBase . '/documents')) ?>" data-once data-busy="Generating…"><?= csrf_field() ?>
                                            <input type="hidden" name="variant" value="<?= e($key) ?>"><input type="hidden" name="format" value="<?= $fmt ?>">
                                            <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon($stale ? 'arrow-repeat' : ($failed !== null ? 'arrow-clockwise' : 'plus-lg')) ?><?= $staleEdits ? 'Rebuild with your corrections' : ($staleImages ? 'Rebuild with new images' : ($staleLayout ? 'Rebuild with the latest layout' : ($failed !== null ? 'Retry' : 'Generate'))) ?></button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($failed !== null): $why = trim((string) $failed['error']) ?: 'No reason was recorded.'; ?>
                                        <details class="error-pop" title="<?= e($why) ?>">
                                            <summary class="error-pop-trigger" aria-label="The last attempt failed. Show why."><?= icon('exclamation-circle-fill') ?></summary>
                                            <div class="error-pop-body" role="note"><strong>Last attempt failed</strong><span class="d-block"><?= e($why) ?></span></div>
                                        </details>
                                        <?php endif; ?>
                                    </span>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-muted">Existing documents are reused when nothing has changed. PDFs include the images saved on this server; Markdown files link to them on ExamTopics. PDFs are built in the background: large exams take a few minutes, and the progress shows here.</div>
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
                        <a class="row-title" href="<?= e(url('/jobs/' . $j['uuid'])) ?>">#<?= (int) $j['id'] ?></a>
                        <span class="row-time me-auto"><?= e(fmt_time($j['queued_at'])) ?></span>
                        <?= status_badge($j['status']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
