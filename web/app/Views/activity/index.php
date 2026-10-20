<?php
/** @var list<array<string,mixed>> $rows @var bool $more @var array{from:string,to:string,who:string,group:string} $filters
 *  @var list<array<string,mixed>> $people @var array<string,array{label:string,prefixes:list<string>}> $groups
 *  @var array<int,string> $names @var array<string,string> $query */
$filtered = $filters['to'] !== '' || $filters['who'] !== '' || $filters['group'] !== '';
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Activity</h1>
        <p class="page-subtitle">Who did what, and when: sign-ins, changes to accounts and settings, collections and backups. Entries are only ever added, never changed.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/activity.csv', $query)) ?>"><?= icon('download') ?>Download as CSV</a>
        <form method="post" action="<?= e(url('/activity/verify', $query)) ?>" data-once data-busy="Checking…"><?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= icon('shield-check') ?>Check the history</button>
        </form>
    </div>
</header>
<div class="card">
    <div class="card-header">
        <form class="toolbar flex-wrap" method="get" action="<?= e(url('/activity')) ?>">
            <?= icon('funnel', 'text-subtle') ?>
            <label class="visually-hidden" for="activity-from">From</label>
            <input class="form-control form-control-sm w-auto" type="date" id="activity-from" name="from" value="<?= e($filters['from']) ?>" title="From">
            <span class="small text-muted">to</span>
            <label class="visually-hidden" for="activity-to">To</label>
            <input class="form-control form-control-sm w-auto" type="date" id="activity-to" name="to" value="<?= e($filters['to']) ?>" title="To">
            <select class="form-select form-select-sm w-auto" name="who" aria-label="Who" data-autosubmit>
                <option value="">Everyone</option>
                <?php foreach ($people as $p): ?>
                    <option value="u<?= (int) $p['id'] ?>" <?= $filters['who'] === 'u' . (int) $p['id'] ? 'selected' : '' ?>><?= e(\App\Services\Access::displayName($p)) ?></option>
                <?php endforeach; ?>
                <option value="system" <?= $filters['who'] === 'system' ? 'selected' : '' ?>>The system</option>
                <option value="visitor" <?= $filters['who'] === 'visitor' ? 'selected' : '' ?>>Not signed in</option>
            </select>
            <select class="form-select form-select-sm w-auto" name="group" aria-label="Kind" data-autosubmit>
                <option value="">Everything</option>
                <?php foreach ($groups as $key => $g): ?>
                    <option value="<?= e($key) ?>" <?= $filters['group'] === $key ? 'selected' : '' ?>><?= e($g['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-secondary" type="submit">Show</button>
            <?php if ($filtered || $filters['from'] !== gmdate('Y-m-d', time() - 30 * 86400)): ?><a class="btn btn-sm btn-link" href="<?= e(url('/activity')) ?>">Last 30 days</a><?php endif; ?>
            <?php if ($filters['from'] !== ''): ?><a class="btn btn-sm btn-link" href="<?= e(url('/activity', ['from' => 'all', 'who' => $filters['who'], 'group' => $filters['group']])) ?>">All time</a><?php endif; ?>
        </form>
    </div>
    <?php if ($rows === []): ?>
        <div class="card-body"><?= empty_state('activity', 'Nothing recorded for these filters', 'Widen the dates, or choose Everyone and Everything.', null, true) ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="activity" data-order='[[0,"desc"]]'>
            <thead><tr><th>When</th><th>What happened</th><th>Who</th><th data-optional>Address</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="small text-muted" data-label="When" data-order="<?= (int) $r['id'] ?>"><?= e(fmt_time((string) $r['created_at'])) ?></td>
                    <td class="stack-title" data-export="<?= e($r['text']) ?>">
                        <span class="activity-line"><span class="icon-bubble" data-tone="<?= e($r['tone']) ?>"><?= icon($r['icon']) ?></span><span><?= e($r['text']) ?></span></span>
                    </td>
                    <td class="small" data-label="Who"><?= e(\App\Services\AuditText::who($r, $names)) ?></td>
                    <td class="small mono text-muted" data-label="Address"><?= e((string) ($r['ip'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if ($more): ?>
    <div class="card-footer small text-muted"><?= icon('info-circle') ?> Showing the newest 1,000 entries. Narrow the dates or the filters to see older ones, or download them all as CSV.</div>
    <?php endif; ?>
</div>
