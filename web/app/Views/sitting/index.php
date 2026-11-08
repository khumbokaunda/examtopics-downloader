<?php /** @var list<array> $rows @var list<array> $history */
use App\Services\Sittings;
$tone = ['open' => 'success', 'upcoming' => 'info', 'closed' => 'neutral'];
$label = ['open' => 'Open', 'upcoming' => 'Not open yet', 'closed' => 'Closed'];
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">My assessments</h1>
        <p class="page-subtitle">Assessments set for you.</p>
    </div>
</header>
<div class="card mb-4">
    <div class="card-header"><span class="card-title"><?= icon('pencil-square') ?>Set for you</span></div>
    <?php if ($rows === []): ?>
        <div class="card-body"><?= empty_state('pencil-square', 'Nothing set for you', 'Assigned assessments show here.', null, true) ?></div>
    <?php else: ?>
    <ul class="list-rows">
        <?php foreach ($rows as $a): $w = Sittings::window($a); $left = (int) $a['max_attempts'] - (int) $a['attempts_used']; ?>
            <li class="request-row">
                <div class="row-main"><span class="icon-bubble" data-tone="<?= e($tone[$w]) ?>"><?= icon($a['open_session'] !== null ? 'play-circle' : 'clipboard-check') ?></span><div class="row-text">
                    <div class="row-title"><a href="<?= e(url('/my/assessments/' . $a['institution_uuid'] . '/' . $a['uuid'])) ?>"><?= e($a['title']) ?></a>
                        <?= $a['open_session'] !== null ? chip('In progress', 'warning') : chip($label[$w], $tone[$w]) ?></div>
                    <div class="row-meta"><?= e($a['institution_short'] ?: $a['institution_name']) ?> · <?= (int) $a['question_count'] ?> questions · <?= (int) $a['duration_minutes'] ?> minutes
                        · <?= $left > 0 ? $left . ' of ' . (int) $a['max_attempts'] . ' attempts left' : 'no attempts left' ?>
                        <?= $a['closes_at'] !== null && $w !== 'closed' ? ' · closes ' . e(fmt_time((string) $a['closes_at'])) : '' ?><?= $w === 'upcoming' ? ' · opens ' . e(fmt_time((string) $a['opens_at'])) : '' ?></div>
                </div></div>
                <?php if ($a['open_session'] !== null): ?>
                    <div class="request-actions"><a class="btn btn-sm btn-primary" href="<?= e(url('/sit/' . $a['open_session'])) ?>"><?= icon('play-fill') ?>Carry on</a></div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('clock-history') ?>What you have sat</span></div>
    <?php if ($history === []): ?>
        <div class="card-body text-muted small">Nothing yet.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead><tr><th>Assessment</th><th>Attempt</th><th>Started</th><th>Status</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td class="stack-title"><?= e($h['title']) ?><div class="small text-muted"><?= e($h['institution_name']) ?></div></td>
                    <td class="small" data-label="Attempt"><?= (int) $h['attempt'] ?></td>
                    <td class="small" data-label="Started"><?= e(fmt_time((string) $h['started_at'])) ?></td>
                    <td data-label="Status"><?= chip(Sittings::STATUSES[$h['status']] ?? $h['status'], $h['status'] === 'in_progress' ? 'warning' : 'success') ?></td>
                    <td class="small" data-label="Result"><?= $h['status'] === 'in_progress' ? '<a href="' . e(url('/sit/' . $h['uuid'])) . '">Carry on</a>'
                        : ($h['results_published_at'] !== null ? '<a href="' . e(url('/sit/' . $h['uuid'] . '/result')) . '">See your result</a>' : 'Shown when the institution publishes results') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
