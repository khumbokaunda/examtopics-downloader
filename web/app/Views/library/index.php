<?php /** @var list<array> $rows @var array{provider:string,q:string,status:string} $filters @var list<array> $providers */ ?>
<div class="mb-3">
    <h1 class="h3 mb-0">Exam Library</h1>
    <div class="text-muted small">Every exam you have collected. Documents are generated from these cached versions, without contacting the source again.</div>
</div>
<form class="row g-2 mb-3" method="get" action="<?= e(url('/library')) ?>">
    <div class="col-sm-4 col-lg-3">
        <select class="form-select" name="provider" aria-label="Provider" data-autosubmit>
            <option value="">All providers</option>
            <?php foreach ($providers as $p): ?>
                <option value="<?= e($p['slug']) ?>" <?= $filters['provider'] === $p['slug'] ? 'selected' : '' ?>><?= e($p['name'] ?: $p['slug']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-4 col-lg-3">
        <select class="form-select" name="status" aria-label="Cache status" data-autosubmit>
            <option value="">Any status</option>
            <option value="complete" <?= $filters['status'] === 'complete' ? 'selected' : '' ?>>Complete</option>
            <option value="partial-only" <?= $filters['status'] === 'partial-only' ? 'selected' : '' ?>>Partial only</option>
        </select>
    </div>
    <div class="col"><input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search exam code or name" data-filter-table="#library-table" aria-label="Search"></div>
    <div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Filter</button></div>
</form>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="library-table">
            <thead><tr><th>Exam</th><th>Provider</th><th>Collected</th><th class="text-end">Questions</th><th>Status</th><th class="text-end">Docs</th><th></th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?><tr><td colspan="7" class="text-muted p-4">Nothing here yet. <a href="<?= e(url('/collect')) ?>">Collect an exam</a> to start your library.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): $b = $r['best']; $href = url('/library/' . rawurlencode($r['provider_slug']) . '/' . rawurlencode($r['code'])); ?>
                <tr data-filter-text="<?= e($r['code'] . ' ' . ($r['name'] ?? '')) ?>">
                    <td><a class="fw-semibold" href="<?= e($href) ?>"><?= e(strtoupper($r['code'])) ?></a>
                        <?php if ($r['name'] && strtolower($r['name']) !== $r['code']): ?><div class="small text-muted"><?= e($r['name']) ?></div><?php endif; ?></td>
                    <td class="small"><?= e($r['provider_name'] ?: $r['provider_slug']) ?></td>
                    <td class="small"><?= e(fmt_time($b['collected_at'] ?? null)) ?><div class="text-muted"><?= (int) $r['version_count'] ?> version<?= (int) $r['version_count'] === 1 ? '' : 's' ?></div></td>
                    <td class="text-end"><?= (int) ($b['collected_count'] ?? 0) ?><?php if ($r['reported_count'] !== null && (int) $r['reported_count'] !== (int) ($b['collected_count'] ?? 0)): ?><div class="small text-warning-emphasis" title="Reported by the latest inventory">of <?= (int) $r['reported_count'] ?></div><?php endif; ?></td>
                    <td><?= status_badge($r['cache_status'] === 'complete' ? 'complete' : 'partial') ?><?php if ($r['active_job_id']): ?> <a class="badge text-bg-primary text-decoration-none" href="<?= e(url('/jobs/' . (int) $r['active_job_id'])) ?>">refreshing</a><?php endif; ?></td>
                    <td class="text-end"><?= (int) $r['document_count'] ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-primary" href="<?= e($href . '/view') ?>">Open</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e($href) ?>">Manage</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
