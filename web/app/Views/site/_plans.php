<?php /** @var list<array> $plans */ ?>
<div class="plan-cards">
    <?php foreach ($plans as $p): ?>
        <div class="card plan-card">
            <div class="card-body">
                <h2 class="h5 mb-1"><?= e($p['name']) ?></h2>
                <div class="plan-price mb-2"><?= $p['price'] !== null ? e($p['price']) : '<span class="text-muted">Ask us</span>' ?></div>
                <?php if ($p['summary'] !== null): ?><p class="text-muted small"><?= e($p['summary']) ?></p><?php endif; ?>
                <ul class="small">
                    <li><?= $p['max_students'] !== null ? 'Up to ' . number_format((int) $p['max_students']) . ' students' : 'Any number of students' ?></li>
                    <li><?= $p['max_staff'] !== null ? 'Up to ' . number_format((int) $p['max_staff']) . ' staff' : 'Any number of staff' ?></li>
                </ul>
            </div>
        </div>
    <?php endforeach; ?>
</div>
