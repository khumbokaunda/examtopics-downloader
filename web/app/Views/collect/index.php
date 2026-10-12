<?php
/** @var list<array> $providers @var array|null $provider @var list<array> $exams @var string $q @var array|null $exam
 *  @var list<array> $versions @var array|null $best @var list<array> $documents @var array|null $activeCollect
 *  @var list<float> $allowedRps @var float $defaultRps @var array|null $lastRefresh */
$examUrl = static fn(array $p, array $e): string => url('/library/' . rawurlencode($p['slug']) . '/' . rawurlencode($e['code']));
?>
<div class="mb-4">
    <h1 class="h3 mb-0">Collect Exam</h1>
    <div class="text-muted small">Choose a provider and exam. Exams already in your library are reused, not collected again, unless you ask for a refresh.</div>
</div>

<?php if ($activeCollect): ?>
    <div class="alert alert-primary d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>A collection is in progress: <strong><?= e(job_label($activeCollect)) ?></strong>. Only one collection runs at a time.</span>
        <a class="btn btn-sm btn-primary" href="<?= e(url('/jobs/' . (int) $activeCollect['id'])) ?>">View progress</a>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header">
                <form class="row g-2 align-items-center" method="get" action="<?= e(url('/collect')) ?>">
                    <div class="col-sm-5">
                        <label class="visually-hidden" for="provider-select">Provider</label>
                        <select class="form-select" id="provider-select" name="provider" data-autosubmit>
                            <option value="">Choose a provider…</option>
                            <?php foreach ($providers as $p): ?>
                                <option value="<?= e($p['slug']) ?>" <?= $provider && $provider['slug'] === $p['slug'] ? 'selected' : '' ?>><?= e(($p['name'] ?: $p['slug']) . ' (' . (int) $p['exam_count'] . ' exams)') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search exams" data-filter-table="#collect-exams" aria-label="Search exams" <?= $provider ? '' : 'disabled' ?>>
                    </div>
                    <div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Go</button></div>
                </form>
            </div>
            <?php if (!$provider): ?>
                <div class="card-body text-muted">
                    <?= $providers === [] ? 'No providers yet. Add one on the <a href="' . e(url('/providers')) . '">Providers &amp; Inventory</a> page.' : 'Select a provider to see its exams.' ?>
                </div>
            <?php else: ?>
                <?php if ($lastRefresh === null): ?>
                    <div class="card-body"><div class="alert alert-warning mb-0">This provider's inventory has never been loaded. <a href="<?= e(url('/providers/' . rawurlencode($provider['slug']))) ?>">Refresh it</a> to see its exams.</div></div>
                <?php endif; ?>
                <div class="table-responsive exam-picker">
                    <table class="table table-hover align-middle mb-0" id="collect-exams">
                        <thead class="sticky-top"><tr><th>Exam</th><th class="text-end">Reported</th><th>Library</th></tr></thead>
                        <tbody>
                        <?php if ($exams === [] && $lastRefresh !== null): ?><tr><td colspan="3" class="text-muted p-3">No exams match.</td></tr><?php endif; ?>
                        <?php foreach ($exams as $ex): $sel = $exam && $exam['code'] === $ex['code']; ?>
                            <tr class="<?= $sel ? 'table-primary' : '' ?>" data-filter-text="<?= e($ex['code'] . ' ' . ($ex['name'] ?? '')) ?>">
                                <td><a class="stretched-link-row fw-semibold" href="<?= e(url('/collect', ['provider' => $provider['slug'], 'exam' => $ex['code'], 'q' => $q])) ?>"><?= e(strtoupper($ex['code'])) ?></a>
                                    <?php if ($ex['name'] && strtolower($ex['name']) !== $ex['code']): ?><div class="small text-muted"><?= e($ex['name']) ?></div><?php endif; ?></td>
                                <td class="text-end"><?= $ex['reported_count'] === null ? 'n/a' : (int) $ex['reported_count'] ?></td>
                                <td><?= $ex['latest_version'] ? '<span class="badge text-bg-success">cached</span>' : '<span class="small text-muted">not cached</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if (!$exam): ?>
            <div class="card shadow-sm"><div class="card-body text-muted">Select an exam to see its question count, library status and collection options.</div></div>
        <?php else: ?>
            <div class="card shadow-sm exam-panel">
                <div class="card-body">
                    <div class="small text-muted text-uppercase fw-semibold"><?= e($provider['name'] ?: $provider['slug']) ?></div>
                    <h2 class="h4 mb-1"><?= e(strtoupper($exam['code'])) ?></h2>
                    <?php if ($exam['name'] && strtolower($exam['name']) !== $exam['code']): ?><div class="text-muted mb-2"><?= e($exam['name']) ?></div><?php endif; ?>
                    <dl class="row small mb-3">
                        <dt class="col-6">Reported questions</dt><dd class="col-6"><?= $exam['reported_count'] === null ? 'n/a' : (int) $exam['reported_count'] ?></dd>
                        <dt class="col-6">Inventory updated</dt><dd class="col-6"><?= e(fmt_time($exam['inventory_refreshed_at'])) ?></dd>
                        <dt class="col-6">In library</dt>
                        <dd class="col-6"><?php if ($best): ?><?= status_badge($best['status']) ?> <?= (int) $best['collected_count'] ?> questions<br><span class="text-muted"><?= e(fmt_time($best['collected_at'])) ?></span><?php else: ?>not collected<?php endif; ?></dd>
                    </dl>
                    <p class="small text-muted">The reported count comes from the inventory. It shows what is listed, not a guarantee that every question can be collected.</p>

                    <?php if ($best): ?>
                        <div class="d-grid gap-2 mb-3">
                            <a class="btn btn-primary" href="<?= e($examUrl($provider, $exam) . '/view') ?>">Open cached exam</a>
                            <a class="btn btn-outline-primary" href="<?= e($examUrl($provider, $exam)) ?>">Documents &amp; versions<?= $documents ? ' (' . count(array_filter($documents, static fn($d) => $d['status'] === 'ready')) . ' ready)' : '' ?></a>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('/collect')) ?>" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="provider" value="<?= e($provider['slug']) ?>">
                        <input type="hidden" name="exam" value="<?= e($exam['code']) ?>">
                        <fieldset class="border rounded p-3">
                            <legend class="float-none w-auto px-1 fs-6 mb-0"><?= $best ? 'Refresh' : 'Collection options' ?></legend>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="source_mode">Data source</label>
                                <select class="form-select form-select-sm" id="source_mode" name="source_mode">
                                    <option value="auto">Public cached dataset if it has this exam, otherwise live scrape</option>
                                    <option value="live">Live scrape only (newest discussions)</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="rps">Request pacing</label>
                                <select class="form-select form-select-sm" id="rps" name="rps">
                                    <?php foreach ($allowedRps as $r): ?>
                                        <option value="<?= e((string) $r) ?>" <?= (float) $r === $defaultRps ? 'selected' : '' ?>><?= e((string) $r) ?> request<?= (float) $r === 1.0 ? '' : 's' ?>/second<?= (float) $r === $defaultRps ? ' (default)' : '' ?><?= (float) $r < $defaultRps ? ' (gentler, slower)' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($best): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" value="1" id="confirm_refresh" name="confirm_refresh" required>
                                    <label class="form-check-label small" for="confirm_refresh">Collect a fresh version. The current version stays available until the new one passes validation.</label>
                                </div>
                            <?php endif; ?>
                            <button class="btn <?= $best ? 'btn-outline-secondary' : 'btn-primary' ?> w-100" type="submit" <?= $activeCollect ? 'disabled' : '' ?>>
                                <?= $activeCollect ? 'Another collection is running' : ($best ? 'Refresh this exam' : 'Start collection') ?></button>
                            <p class="small text-muted mt-2 mb-0">Comments are always collected; choose whether to include them when you generate documents.</p>
                        </fieldset>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
