<?php
/** @var list<array> $rows @var array{provider:string,q:string,status:string} $filters @var list<array> $providers
 *  @var array<string,int> $counts @var int $total @var string $view @var int $staleDays */
use App\Services\Editions;

$filtered = $filters['provider'] !== '' || $filters['q'] !== '' || $filters['status'] !== '';
$collector = can('exams.collect');
$link = static fn(array $change): string => url('/library', array_merge(['provider' => $filters['provider'], 'q' => $filters['q'], 'status' => $filters['status']], $change));
$examPath = static fn(array $r): string => '/library/' . rawurlencode((string) $r['provider_slug']) . '/' . rawurlencode((string) $r['code']);
$examHref = static fn(array $r): string => url($examPath($r));
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Library</h1>
        <p class="page-subtitle"><?= number_format($total) ?> exam<?= $total === 1 ? '' : 's' ?> to read and practise<?= $filters['q'] !== '' ? ' matching "' . e($filters['q']) . '"' : '' ?>.</p>
    </div>
    <?php if ($collector): ?><div class="page-actions"><a class="btn btn-primary" href="<?= e(url('/collect')) ?>"><?= icon('cloud-arrow-down') ?>Collect an exam</a></div><?php endif; ?>
</header>

<div class="library-bar">
    <form class="library-search" method="get" action="<?= e(url('/library')) ?>" role="search">
        <?php if ($filters['provider'] !== ''): ?><input type="hidden" name="provider" value="<?= e($filters['provider']) ?>"><?php endif; ?>
        <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
        <span class="library-search-icon"><?= icon('search') ?></span>
        <input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search by name, code or provider" aria-label="Search the library" autocomplete="off" <?= $view === 'shelves' ? 'data-shelf-search' : '' ?>>
    </form>
    <div class="btn-group btn-group-sm view-switch" role="group" aria-label="View">
        <a class="btn btn-outline-secondary <?= $view === 'shelves' ? 'active' : '' ?>" href="<?= e($link(['view' => 'shelves'])) ?>" <?= $view === 'shelves' ? 'aria-current="page"' : '' ?>><?= icon('grid') ?>Shelves</a>
        <a class="btn btn-outline-secondary <?= $view === 'table' ? 'active' : '' ?>" href="<?= e($link(['view' => 'table'])) ?>" <?= $view === 'table' ? 'aria-current="page"' : '' ?>><?= icon('table') ?>Table</a>
    </div>
</div>

<?php if ($providers !== []): ?>
<nav class="filter-chips" aria-label="Providers">
    <a class="filter-chip <?= $filters['provider'] === '' ? 'is-active' : '' ?>" href="<?= e($link(['provider' => ''])) ?>" <?= $filters['provider'] === '' ? 'aria-current="page"' : '' ?>>All <span class="filter-chip-count"><?= number_format($total) ?></span></a>
    <?php foreach ($providers as $p): $on = $filters['provider'] === $p['slug']; ?>
        <a class="filter-chip <?= $on ? 'is-active' : '' ?>" href="<?= e($link(['provider' => $on ? '' : (string) $p['slug']])) ?>" <?= $on ? 'aria-current="page"' : '' ?>><?= e(provider_name($p)) ?> <span class="filter-chip-count"><?= number_format($counts[$p['slug']] ?? 0) ?></span></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($rows === []): ?>
    <div class="card"><div class="card-body"><?= $filtered
        ? empty_state('search', 'Nothing matches', '<a href="' . e(url('/library')) . '">Clear the search</a> to see the whole library.')
        : ($collector
            ? empty_state('journal-bookmark', 'Your library is empty', 'Collect an exam to start your library. It is stored on this server and read from here.', ['Collect an exam', url('/collect')])
            : empty_state('journal-bookmark', 'The library is empty', 'Collected exams appear here.' . (can('exams.request') ? ' <a href="' . e(url('/requests')) . '">Request an exam</a>.' : ''))) ?></div></div>

<?php elseif ($view === 'shelves'): ?>
    <?php foreach (Editions::shelves($rows) as $shelf): ?>
    <section class="shelf" data-shelf>
        <h2 class="shelf-title"><?= e($shelf['name']) ?> <span class="shelf-count" data-shelf-count><?= array_sum(array_map('count', $shelf['cards'])) ?></span></h2>
        <div class="shelf-grid">
        <?php foreach ($shelf['cards'] as $editions): $r = $editions[0]; $href = $examHref($r); $b = $r['best'];
            $label = Editions::codeLabel($r);
            $haystack = [];
            foreach ($editions as $ed) {
                array_push($haystack, (string) $ed['code'], Editions::shortCode((string) $ed['code']), (string) ($ed['name'] ?? ''));
            }
            $haystack[] = $shelf['name'];
            $haystack[] = $shelf['slug']; ?>
            <article class="card exam-card" data-shelf-card data-count="<?= count($editions) ?>" data-search="<?= e(mb_strtolower(implode(' ', $haystack))) ?>">
                <div class="exam-card-body">
                    <h3 class="exam-card-title text-clamp-2"><a href="<?= e($href) ?>"><?= e(Editions::title($r)) ?></a></h3>
                    <div class="exam-card-meta">
                        <?php if ($label !== null): ?><span class="code-tag" title="<?= e(strtoupper((string) $r['code'])) ?>"><?= e($label) ?></span><?php endif; ?>
                        <span><?= number_format((int) ($b['collected_count'] ?? 0)) ?> questions</span>
                        <span>Updated <?= e(fmt_ago($b['collected_at'] ?? null)) ?></span>
                        <?php if ($r['cache_status'] !== 'complete'): ?><?= chip('Partial', 'warning', 'exclamation-triangle') ?><?php endif; ?>
                        <?php if ($r['active_job_uuid']): ?><?= chip('Refreshing', 'primary', 'arrow-repeat') ?><?php endif; ?>
                    </div>
                    <?php if (count($editions) > 1): ?>
                        <div class="exam-card-editions"><span class="text-muted">Other editions:</span>
                            <?php foreach (array_slice($editions, 1) as $ed): ?>
                                <a class="edition-link" href="<?= e($examHref($ed)) ?>" title="<?= e(Editions::title($ed)) ?>"><?= e(Editions::shortCode((string) $ed['code'])) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="exam-card-foot">
                    <a class="btn btn-sm btn-primary" href="<?= e($href . '/view') ?>"><?= icon('book') ?>Read</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($examPath($r) . '/view', ['mode' => 'practice'])) ?>"><?= icon('patch-question') ?>Practice</a>
                    <a class="btn btn-sm btn-link ms-auto" href="<?= e($href) ?>" title="Documents, versions and details">Documents</a>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
    <div class="card" data-shelf-empty hidden><div class="card-body"><?= empty_state('search', 'Nothing matches', 'Try fewer words, a code such as SAA-C03, or a provider.', null, true) ?></div></div>

<?php else: ?>
<div class="card">
    <div class="card-header">
        <form class="toolbar w-100" method="get" action="<?= e(url('/library')) ?>">
            <?php foreach (['provider', 'q'] as $k): if ($filters[$k] !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($filters[$k]) ?>"><?php endif; endforeach; ?>
            <select class="form-select" name="status" aria-label="Cache status" data-autosubmit>
                <option value="">Any status</option>
                <option value="complete" <?= $filters['status'] === 'complete' ? 'selected' : '' ?>>Complete</option>
                <option value="partial-only" <?= $filters['status'] === 'partial-only' ? 'selected' : '' ?>>Partial only</option>
            </select>
            <?php if ($filtered): ?><a class="btn btn-sm btn-link" href="<?= e(url('/library')) ?>">Clear filters</a><?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" id="library-table" data-table="library" data-order='[[0,"asc"]]'>
            <thead><tr>
                <th>Exam</th><th>Name</th><th>Provider</th><th>Collected</th><th class="text-end" data-type="num" data-hidden>Versions</th>
                <th class="text-end" data-type="num">Questions</th><th>Status</th>
                <th class="text-end" data-type="num" data-hidden>Documents</th>
                <th class="text-end" data-nosort data-novis data-nosearch data-export="false"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $b = $r['best']; $href = $examHref($r);
                $name = $r['name'] && strtolower($r['name']) !== $r['code'] ? (string) $r['name'] : '';
                $collected = (int) ($b['collected_count'] ?? 0); $reported = $r['reported_count'] === null ? null : (int) $r['reported_count'];
                $stale = $collector && isset($b['collected_at']) && strtotime($b['collected_at'] . ' UTC') < time() - $staleDays * 86400; ?>
                <tr>
                    <td class="stack-title" data-order="<?= e($r['code']) ?>" data-search="<?= e($r['code']) ?>" data-export="<?= e(strtoupper($r['code'])) ?>"><a class="table-row-link" href="<?= e($href) ?>" title="<?= e(strtoupper($r['code'])) ?>"><?= e(Editions::shortCode((string) $r['code'])) ?></a></td>
                    <td data-label="Name"><span class="cell-wrap text-clamp-2 text-muted" title="<?= e($name) ?>"><?= e($name) ?></span></td>
                    <td data-label="Provider"><?= e(provider_name($r, 'provider_name', 'provider_slug')) ?></td>
                    <td data-label="Collected" data-order="<?= e((string) ($b['collected_at'] ?? '')) ?>"><?= e(fmt_time($b['collected_at'] ?? null)) ?><?php if ($stale): ?><div class="cell-sub ms-0 text-warning" title="Collected more than <?= (int) $staleDays ?> days ago: collect a fresh version to pick up new questions">older than <?= (int) $staleDays ?> days</div><?php endif; ?></td>
                    <td class="text-end" data-label="Versions"><?= (int) $r['version_count'] ?></td>
                    <td class="text-end" data-label="Questions" data-order="<?= $collected ?>" data-export="<?= $collected ?><?= $reported !== null && $reported > $collected ? ' of ' . $reported : '' ?>"><?= number_format($collected) ?><?php if ($reported !== null && $reported > $collected): ?><span class="cell-sub text-warning" title="The provider inventory lists <?= $reported ?> questions for this exam; <?= $reported - $collected ?> were not collected">of <?= number_format($reported) ?></span><?php elseif ($reported !== null && $reported < $collected): ?><span class="cell-sub text-muted" title="More than the <?= $reported ?> the provider inventory counted: that count is low (a partial or older inventory refresh). Refresh the provider to update it."><?= icon('info-circle') ?></span><?php endif; ?></td>
                    <td data-label="Status"><span class="chip-group"><?= status_badge($r['cache_status'] === 'complete' ? 'complete' : 'partial') ?><?php if ($r['active_job_uuid'] && $collector): ?><a class="chip chip--primary" href="<?= e(url('/jobs/' . $r['active_job_uuid'])) ?>"><?= icon('arrow-repeat', 'spin') ?>refreshing</a><?php elseif ($r['active_job_uuid']): ?><?= chip('refreshing', 'primary', 'arrow-repeat') ?><?php endif; ?></span></td>
                    <td class="text-end" data-label="Documents"><?= (int) $r['document_count'] ?></td>
                    <td class="text-end stack-actions">
                        <a class="btn btn-sm btn-primary" href="<?= e($href . '/view') ?>"><?= icon('book') ?>Read</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e($href) ?>" title="Documents, versions and details">Documents</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
