<?php /** @var list<array> $rows @var array{provider:string,q:string,status:string} $filters @var list<array> $providers */
$filtered = $filters['provider'] !== '' || $filters['q'] !== '' || $filters['status'] !== '';
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Library</h1>
        <p class="page-subtitle">Every exam you have collected. Documents are generated from these cached versions without contacting the source again.</p>
    </div>
    <div class="page-actions"><a class="btn btn-primary" href="<?= e(url('/collect')) ?>"><?= icon('cloud-arrow-down') ?>Collect an exam</a></div>
</header>
<div class="card">
    <div class="card-header">
        <form class="toolbar w-100" method="get" action="<?= e(url('/library')) ?>">
            <select class="form-select" name="provider" aria-label="Provider" data-autosubmit>
                <option value="">All providers</option>
                <?php foreach ($providers as $p): ?>
                    <option value="<?= e($p['slug']) ?>" <?= $filters['provider'] === $p['slug'] ? 'selected' : '' ?>><?= e($p['name'] ?: $p['slug']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select" name="status" aria-label="Cache status" data-autosubmit>
                <option value="">Any status</option>
                <option value="complete" <?= $filters['status'] === 'complete' ? 'selected' : '' ?>>Complete</option>
                <option value="partial-only" <?= $filters['status'] === 'partial-only' ? 'selected' : '' ?>>Partial only</option>
            </select>
            <div class="search-field"><?= icon('search') ?><input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search exam code or name" data-filter-table="#library-table" aria-label="Search"></div>
            <button class="btn btn-outline-secondary" type="submit">Filter</button>
        </form>
    </div>
    <?php if ($rows === []): ?>
        <div class="card-body"><?= $filtered
            ? empty_state('search', 'Nothing matches these filters', '<a href="' . e(url('/library')) . '">Clear the filters</a> to see your whole library.')
            : empty_state('journal-bookmark', 'Your library is empty', 'Collect an exam to start your library. It is stored on this server and read from here.', ['Collect an exam', url('/collect')]) ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" id="library-table">
            <thead><tr><th>Exam</th><th>Provider</th><th>Collected</th><th class="text-end">Questions</th><th>Status</th><th class="text-end">Documents</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $b = $r['best']; $href = url('/library/' . rawurlencode($r['provider_slug']) . '/' . rawurlencode($r['code'])); ?>
                <tr data-filter-text="<?= e($r['code'] . ' ' . ($r['name'] ?? '')) ?>">
                    <td class="cell-wrap stack-title"><a class="table-row-link" href="<?= e($href) ?>"><?= e(strtoupper($r['code'])) ?></a>
                        <?php if ($r['name'] && strtolower($r['name']) !== $r['code']): ?><div class="small text-muted"><?= e($r['name']) ?></div><?php endif; ?></td>
                    <td class="small" data-label="Provider"><?= e($r['provider_name'] ?: $r['provider_slug']) ?></td>
                    <td class="small" data-label="Collected"><span><?= e(fmt_time($b['collected_at'] ?? null)) ?><span class="d-block text-muted"><?= (int) $r['version_count'] ?> version<?= (int) $r['version_count'] === 1 ? '' : 's' ?></span></span></td>
                    <td class="text-end" data-label="Questions"><span><?= number_format((int) ($b['collected_count'] ?? 0)) ?><?php if ($r['reported_count'] !== null && (int) $r['reported_count'] !== (int) ($b['collected_count'] ?? 0)): ?><span class="d-block small text-warning" title="Reported by the latest inventory">of <?= number_format((int) $r['reported_count']) ?></span><?php endif; ?></span></td>
                    <td data-label="Status"><span class="chip-group"><?= status_badge($r['cache_status'] === 'complete' ? 'complete' : 'partial') ?><?php if ($r['active_job_id']): ?><a class="chip chip--primary" href="<?= e(url('/jobs/' . (int) $r['active_job_id'])) ?>"><?= icon('arrow-repeat', 'spin') ?>refreshing</a><?php endif; ?></span></td>
                    <td class="text-end" data-label="Documents"><?= (int) $r['document_count'] ?></td>
                    <td class="text-end stack-actions">
                        <a class="btn btn-sm btn-primary" href="<?= e($href . '/view') ?>"><?= icon('book') ?>Open</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e($href) ?>">Manage</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
