<?php /** @var list<array> $rows @var array{provider:string,q:string,status:string} $filters @var list<array> $providers */
$filtered = $filters['provider'] !== '' || $filters['q'] !== '' || $filters['status'] !== '';
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Library</h1>
        <p class="page-subtitle">Every exam <?= can('exams.collect') ? 'you have' : 'that has been' ?> collected. Documents are generated from these cached versions without contacting the source again.</p>
    </div>
    <?php if (can('exams.collect')): ?><div class="page-actions"><a class="btn btn-primary" href="<?= e(url('/collect')) ?>"><?= icon('cloud-arrow-down') ?>Collect an exam</a></div><?php endif; ?>
</header>
<div class="card">
    <div class="card-header">
        <form class="toolbar w-100" method="get" action="<?= e(url('/library')) ?>">
            <select class="form-select" name="provider" aria-label="Provider" data-autosubmit>
                <option value="">All providers</option>
                <?php foreach ($providers as $p): ?>
                    <option value="<?= e($p['slug']) ?>" <?= $filters['provider'] === $p['slug'] ? 'selected' : '' ?>><?= e(provider_name($p)) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select" name="status" aria-label="Cache status" data-autosubmit>
                <option value="">Any status</option>
                <option value="complete" <?= $filters['status'] === 'complete' ? 'selected' : '' ?>>Complete</option>
                <option value="partial-only" <?= $filters['status'] === 'partial-only' ? 'selected' : '' ?>>Partial only</option>
            </select>
            <?php if ($filtered): ?><a class="btn btn-sm btn-link" href="<?= e(url('/library')) ?>">Clear filters</a><?php endif; ?>
        </form>
    </div>
    <?php if ($rows === []): ?>
        <div class="card-body"><?= $filtered
            ? empty_state('search', 'Nothing matches these filters', '<a href="' . e(url('/library')) . '">Clear the filters</a> to see your whole library.')
            : (can('exams.collect')
                ? empty_state('journal-bookmark', 'Your library is empty', 'Collect an exam to start your library. It is stored on this server and read from here.', ['Collect an exam', url('/collect')])
                : empty_state('journal-bookmark', 'The library is empty', 'Collected exams appear here.')) ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" id="library-table" data-table="library" data-order='[[0,"asc"]]'>
            <thead><tr>
                <th>Exam</th><th>Name</th><th>Provider</th><th>Collected</th><th class="text-end" data-type="num" data-hidden>Versions</th>
                <th class="text-end" data-type="num">Questions</th><th>Status</th>
                <th class="text-end" data-type="num">Documents</th>
                <th class="text-end" data-nosort data-novis data-nosearch data-export="false"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $b = $r['best']; $href = url('/library/' . rawurlencode($r['provider_slug']) . '/' . rawurlencode($r['code']));
                $name = $r['name'] && strtolower($r['name']) !== $r['code'] ? (string) $r['name'] : '';
                $collected = (int) ($b['collected_count'] ?? 0); $reported = $r['reported_count'] === null ? null : (int) $r['reported_count']; ?>
                <tr>
                    <td class="stack-title" data-order="<?= e($r['code']) ?>" data-export="<?= e(strtoupper($r['code'])) ?>"><a class="table-row-link" href="<?= e($href) ?>"><?= e(strtoupper($r['code'])) ?></a></td>
                    <td data-label="Name"><span class="truncate is-narrow text-muted" title="<?= e($name) ?>"><?= e($name !== '' ? $name : '') ?></span></td>
                    <td data-label="Provider"><?= e(provider_name($r, 'provider_name', 'provider_slug')) ?></td>
                    <td data-label="Collected" data-order="<?= e((string) ($b['collected_at'] ?? '')) ?>"><?= e(fmt_time($b['collected_at'] ?? null)) ?></td>
                    <td class="text-end" data-label="Versions"><?= (int) $r['version_count'] ?></td>
                    <td class="text-end" data-label="Questions" data-order="<?= $collected ?>" data-export="<?= $collected ?><?= $reported !== null && $reported > $collected ? ' of ' . $reported : '' ?>"><?= number_format($collected) ?><?php if ($reported !== null && $reported > $collected): ?><span class="cell-sub text-warning" title="The provider inventory lists <?= $reported ?> questions for this exam; <?= $reported - $collected ?> were not collected">of <?= number_format($reported) ?></span><?php elseif ($reported !== null && $reported < $collected): ?><span class="cell-sub text-muted" title="More than the <?= $reported ?> the provider inventory counted: that count is low (a partial or older inventory refresh). Refresh the provider to update it."><?= icon('info-circle') ?></span><?php endif; ?></td>
                    <td data-label="Status"><span class="chip-group"><?= status_badge($r['cache_status'] === 'complete' ? 'complete' : 'partial') ?><?php if ($r['active_job_uuid'] && can('exams.collect')): ?><a class="chip chip--primary" href="<?= e(url('/jobs/' . $r['active_job_uuid'])) ?>"><?= icon('arrow-repeat', 'spin') ?>refreshing</a><?php elseif ($r['active_job_uuid']): ?><?= chip('refreshing', 'primary', 'arrow-repeat') ?><?php endif; ?></span></td>
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
