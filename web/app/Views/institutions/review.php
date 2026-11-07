<?php /** @var array $institution @var array $membership @var string $tab @var list<array> $rows */
use App\Services\QuestionBanks;
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Question banks' => '/institutions/' . $institution['uuid'] . '/banks', 'Waiting for review' => null]]) ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('check2-square') ?>Waiting for review: <?= count($rows) ?></span></div>
    <?php if ($rows === []): ?>
        <div class="card-body"><?= empty_state('check2-square', 'Nothing waits for review', 'Questions sent for review in any bank show here.', null, true) ?></div>
    <?php else: ?>
    <ul class="list-rows">
        <?php foreach ($rows as $r): ?>
            <li class="request-row"><div class="row-main"><div class="row-text">
                <div class="row-title fw-normal"><a href="<?= e(url('/institutions/' . $institution['uuid'] . '/banks/' . $r['bank_uuid'] . '/questions/' . $r['uuid'])) ?>"><?= e(QuestionBanks::excerpt((string) $r['text'], 140)) ?></a></div>
                <div class="row-meta"><?= e($r['bank_name']) ?> · <?= e(QuestionBanks::TYPES[$r['type']] ?? $r['type']) ?> · version <?= (int) $r['latest_version'] ?><?= $r['author'] !== null ? ' by ' . e($r['author']) : '' ?> · sent <?= e(fmt_ago((string) $r['updated_at'])) ?></div>
            </div></div></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
