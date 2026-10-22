<?php
/** Everyone's study progress at a glance (progress.view_all). @var list<array> $people */
use App\Services\Access;
use App\Services\StudyProgress;

$me = (int) (\App\Core\View::current()->user()['id'] ?? 0);
$studying = count(array_filter($people, static fn(array $p): bool => $p['last_at'] !== null));
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Everyone's progress</h1>
        <p class="page-subtitle"><?= $studying === 0 ? 'Nobody has read or practised an exam yet.' : number_format($studying) . ' of ' . number_format(count($people)) . ' accounts have read or practised an exam. Open a person to see each exam.' ?></p>
    </div>
    <div class="page-actions">
        <nav class="segmented" aria-label="Whose progress">
            <a href="<?= e(url('/progress')) ?>"><?= icon('person') ?>Mine</a>
            <a class="is-active" href="<?= e(url('/progress/everyone')) ?>" aria-current="page"><?= icon('people') ?>Everyone</a>
        </nav>
    </div>
</header>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="progress-people" data-order='[[5,"desc"]]'>
            <thead><tr>
                <th>Person</th><th>Role</th><th class="text-end" data-type="num">Exams</th><th class="text-end" data-type="num">Answered</th>
                <th class="text-end" data-type="num">Right</th><th>Last studied</th>
            </tr></thead>
            <tbody>
            <?php foreach ($people as $p): $pct = StudyProgress::percent((int) $p['correct'], (int) $p['answered']);
                $href = (int) $p['id'] === $me ? url('/progress') : url('/progress/people/' . $p['uuid']); ?>
                <tr>
                    <td class="stack-title" data-order="<?= e(strtolower(Access::displayName($p))) ?>">
                        <a class="table-row-link" href="<?= e($href) ?>"><?= e(Access::displayName($p)) ?></a>
                        <div class="cell-sub ms-0"><?= e($p['username']) ?><?= (int) $p['id'] === $me ? ' · you' : '' ?><?= $p['status'] !== 'active' ? ' · ' . e(strtolower(Access::STATUSES[$p['status']] ?? $p['status'])) : '' ?></div>
                    </td>
                    <td data-label="Role"><?= e(Access::roleName((string) $p['role'])) ?></td>
                    <td class="text-end" data-label="Exams"><?= number_format((int) $p['exams']) ?></td>
                    <td class="text-end" data-label="Answered"><?= number_format((int) $p['answered']) ?></td>
                    <td class="text-end" data-label="Right" data-order="<?= $pct ?? -1 ?>"><?= $pct === null ? '<span class="text-muted">n/a</span>' : $pct . '%' ?></td>
                    <td data-label="Last studied" data-order="<?= e((string) ($p['last_at'] ?? '')) ?>"><?= $p['last_at'] !== null ? e(fmt_ago((string) $p['last_at'])) : '<span class="text-muted">never</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
