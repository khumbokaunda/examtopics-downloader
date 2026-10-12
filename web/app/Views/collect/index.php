<?php
/** @var list<array> $providers @var array|null $provider @var list<array> $exams @var string $q @var array|null $exam
 *  @var list<array> $versions @var array|null $best @var list<array> $documents @var array|null $activeCollect
 *  @var list<float> $allowedRps @var float $defaultRps @var array|null $lastRefresh */
$examUrl = static fn(array $p, array $e): string => url('/library/' . rawurlencode($p['slug']) . '/' . rawurlencode($e['code']));
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Collect exam</h1>
        <p class="page-subtitle">Choose a provider and an exam. Exams already in your library are reused, not collected again, unless you ask for a refresh.</p>
    </div>
</header>

<?php if ($activeCollect): ?>
    <div class="alert alert-primary">
        <?= icon('arrow-repeat', 'spin') ?>
        <div class="alert-body">A collection is in progress: <strong><?= e(job_label($activeCollect)) ?></strong>. Only one collection runs at a time.</div>
        <a class="btn btn-sm btn-primary" href="<?= e(url('/jobs/' . (int) $activeCollect['id'])) ?>">View progress</a>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header">
                <form class="toolbar w-100" method="get" action="<?= e(url('/collect')) ?>">
                    <label class="visually-hidden" for="provider-select">Provider</label>
                    <select class="form-select" id="provider-select" name="provider" data-autosubmit>
                        <option value="">Choose a provider…</option>
                        <?php foreach ($providers as $p): ?>
                            <option value="<?= e($p['slug']) ?>" <?= $provider && $provider['slug'] === $p['slug'] ? 'selected' : '' ?>><?= e((provider_name($p)) . ' (' . (int) $p['exam_count'] . ' exams)') ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php if (!$provider): ?>
                <div class="card-body">
                    <?= $providers === []
                        ? empty_state('buildings', 'No providers yet', 'Add one on the <a href="' . e(url('/providers')) . '">Providers &amp; inventory</a> page first.')
                        : empty_state('hand-index', 'Choose a provider', 'Its exams are listed here, with what is already in your library.') ?>
                </div>
            <?php else: ?>
                <?php if ($lastRefresh === null): ?>
                    <div class="card-body pb-0"><div class="alert alert-warning"><?= icon('exclamation-triangle') ?><div class="alert-body">This provider's inventory has never been loaded. <a href="<?= e(url('/providers/' . rawurlencode($provider['slug']))) ?>">Refresh it</a> to see its exams.</div></div></div>
                <?php endif; ?>
                <?php if ($exams === [] && $lastRefresh !== null): ?>
                    <div class="card-body"><?= empty_state('search', 'No exams match', 'Try a shorter code, or clear the search.', null, true) ?></div>
                <?php elseif ($exams !== []): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="collect-exams" data-table="collect-exams" data-page-length="10" data-order='[[0,"asc"]]'>
                        <thead><tr><th>Exam</th><th class="text-end" data-type="num">Reported</th><th data-type="num">Library</th></tr></thead>
                        <tbody>
                        <?php foreach ($exams as $ex): $sel = $exam && $exam['code'] === $ex['code']; ?>
                            <tr class="<?= $sel ? 'is-selected' : '' ?>">
                                <td class="cell-wrap" data-order="<?= e($ex['code']) ?>" data-export="<?= e(strtoupper($ex['code'])) ?>"><a class="table-row-link" href="<?= e(url('/collect', ['provider' => $provider['slug'], 'exam' => $ex['code'], 'q' => $q])) ?>"<?= $sel ? ' aria-current="true"' : '' ?>><?= e(strtoupper($ex['code'])) ?></a>
                                    <?php if ($ex['name'] && strtolower($ex['name']) !== $ex['code']): ?><div class="small text-muted"><?= e($ex['name']) ?></div><?php endif; ?></td>
                                <td class="text-end" data-order="<?= $ex['reported_count'] === null ? -1 : (int) $ex['reported_count'] ?>"><?= $ex['reported_count'] === null ? '<span class="text-muted">n/a</span>' : number_format((int) $ex['reported_count']) ?></td>
                                <td data-order="<?= $ex['latest_version'] ? 1 : 0 ?>"><?= $ex['latest_version'] ? chip('cached', 'success', 'check2-circle') : '<span class="small text-muted">not cached</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-5">
        <?php if (!$exam): ?>
            <div class="card"><div class="card-body"><?= empty_state('ui-checks', 'Select an exam', 'Its question count, library status and collection options appear here.', null, true) ?></div></div>
        <?php else: ?>
            <div class="card exam-panel">
                <div class="card-body">
                    <div class="eyebrow"><?= e(provider_name($provider)) ?></div>
                    <h2 class="exam-code"><?= e(strtoupper($exam['code'])) ?></h2>
                    <?php if ($exam['name'] && strtolower($exam['name']) !== $exam['code']): ?><div class="exam-name"><?= e($exam['name']) ?></div><?php endif; ?>
                    <dl class="meta-list mt-3 mb-2">
                        <dt>Reported questions</dt><dd><?= $exam['reported_count'] === null ? 'n/a' : (int) $exam['reported_count'] ?></dd>
                        <dt>Inventory updated</dt><dd><?= e(fmt_time($exam['inventory_refreshed_at'])) ?></dd>
                        <dt>In library</dt>
                        <dd><?php if ($best): ?><?= status_badge($best['status']) ?> <?= (int) $best['collected_count'] ?> questions<div class="text-muted small"><?= e(fmt_time($best['collected_at'])) ?></div><?php else: ?><span class="text-muted">not collected</span><?php endif; ?></dd>
                    </dl>
                    <p class="form-text mb-4">The reported count comes from the inventory. It shows what is listed, not a promise that every question can be collected.</p>

                    <?php if ($best): ?>
                        <div class="d-grid gap-2 mb-4">
                            <a class="btn btn-primary" href="<?= e($examUrl($provider, $exam) . '/view') ?>"><?= icon('book') ?>Open cached exam</a>
                            <a class="btn btn-outline-secondary" href="<?= e($examUrl($provider, $exam)) ?>"><?= icon('file-earmark-text') ?>Documents &amp; versions<?= $documents ? ' (' . count(array_filter($documents, static fn($d) => $d['status'] === 'ready')) . ' ready)' : '' ?></a>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('/collect')) ?>" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="provider" value="<?= e($provider['slug']) ?>">
                        <input type="hidden" name="exam" value="<?= e($exam['code']) ?>">
                        <div class="option-box">
                            <h3 class="section-heading"><?= $best ? 'Refresh' : 'Collection options' ?></h3>
                            <div class="mb-3">
                                <label class="form-label" for="source_mode">Data source</label>
                                <select class="form-select" id="source_mode" name="source_mode">
                                    <option value="auto">Public cached dataset if it has this exam, otherwise live scrape</option>
                                    <option value="live">Live scrape only (newest discussions)</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="rps">Request pacing</label>
                                <select class="form-select" id="rps" name="rps">
                                    <?php foreach ($allowedRps as $r): ?>
                                        <option value="<?= e((string) $r) ?>" <?= (float) $r === $defaultRps ? 'selected' : '' ?>><?= e((string) $r) ?> request<?= (float) $r === 1.0 ? '' : 's' ?> per second<?= (float) $r === $defaultRps ? ' (default)' : '' ?><?= (float) $r < $defaultRps ? ' (gentler, slower)' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($best): ?>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" value="1" id="confirm_refresh" name="confirm_refresh" required>
                                    <label class="form-check-label small" for="confirm_refresh">Collect a fresh version. The current version stays available until the new one passes validation.</label>
                                </div>
                            <?php endif; ?>
                            <button class="btn <?= $best ? 'btn-outline-primary' : 'btn-primary' ?> w-100" type="submit" <?= $activeCollect ? 'disabled' : '' ?>>
                                <?= icon($best ? 'arrow-clockwise' : 'cloud-arrow-down') ?><?= $activeCollect ? 'Another collection is running' : ($best ? 'Refresh this exam' : 'Start collection') ?></button>
                            <p class="form-text mb-0 mt-2">Comments are always collected. Choose whether to include them when you generate documents.</p>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
