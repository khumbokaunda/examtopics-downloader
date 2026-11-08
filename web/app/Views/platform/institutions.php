<?php /** @var list<array> $rows @var string $status @var array<string,int> $counts */
use App\Services\Institutions;
$tone = \App\Services\Institutions::TONES;
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Institutions</h1>
        <p class="page-subtitle">Every registered institution, with its status. Verify the ones you have checked. Reviewing an institution shows its profile and its owners, not its people or what they do there.</p>
    </div>
    <div class="page-actions">
        <nav class="segmented" aria-label="Status">
            <a class="<?= $status === '' ? 'is-active' : '' ?>" href="<?= e(url('/platform/institutions')) ?>">All <?= array_sum($counts) ?></a>
            <?php foreach (Institutions::STATUSES as $k => $label): ?>
                <a class="<?= $status === $k ? 'is-active' : '' ?>" href="<?= e(url('/platform/institutions', ['status' => $k])) ?>"><?= e($label) ?> <?= (int) ($counts[$k] ?? 0) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>
<div class="card">
    <?php if ($rows === []): ?>
        <div class="card-body"><?= empty_state('buildings', 'No institutions here', 'Registrations appear here, and reviewers are told in their inbox.', null, true) ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="platform-institutions" data-order='[[4,"desc"]]'>
            <thead><tr><th>Institution</th><th>Kind</th><th>Status</th><th data-type="num">People</th><th data-type="num">Registered</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i): ?>
                <tr>
                    <td class="stack-title" data-order="<?= e(strtolower($i['name'])) ?>"><a class="table-row-link" href="<?= e(url('/platform/institutions/' . $i['uuid'])) ?>"><?= e($i['name']) ?></a>
                        <div class="small text-muted"><?= e($i['district']) ?></div></td>
                    <td class="small" data-label="Kind"><?= e(Institutions::TYPES[$i['type']] ?? $i['type']) ?></td>
                    <td data-label="Status"><?= chip(Institutions::STATUSES[$i['status']] ?? $i['status'], $tone[$i['status']] ?? 'neutral') ?></td>
                    <td class="small" data-label="People" data-order="<?= (int) $i['member_count'] ?>"><?= (int) $i['member_count'] ?></td>
                    <td class="small" data-label="Registered" data-order="<?= e((string) $i['created_at']) ?>"><?= e(fmt_time((string) $i['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
