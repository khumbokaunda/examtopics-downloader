<?php /** @var array $institution @var array $membership @var string $tab @var list<array> $rows */ ?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<div class="card">
    <?php if ($rows === []): ?>
        <div class="card-body"><?= empty_state('activity', 'Nothing recorded yet', '', null, true) ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="institution-activity" data-order='[[0,"desc"]]'>
            <thead><tr><th>When</th><th>What happened</th><th>Who</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="small text-muted" data-label="When" data-order="<?= (int) $r['id'] ?>"><?= e(fmt_time((string) $r['created_at'])) ?></td>
                    <td class="stack-title"><span class="activity-line"><span class="icon-bubble" data-tone="<?= e($r['tone']) ?>"><?= icon($r['icon']) ?></span><span><?= e($r['text']) ?></span></span></td>
                    <td class="small" data-label="Who"><?= e($r['who']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <div class="card-footer small text-muted"><?= icon('info-circle') ?> The newest 500 entries about this institution: its profile, its verification and its people.</div>
</div>
