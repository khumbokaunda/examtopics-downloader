<?php
/**
 * The plan cards, with a monthly / yearly switch when any plan has a yearly price. The switch is
 * a checkbox styled as a toggle: CSS shows one set of prices or the other, so it works without
 * a script. @var list<array> $plans @var string $currency
 */
use App\Services\Plans;

$shown = array_map(static fn(array $p): array => $p + ['_price' => Plans::display($p, $currency), '_features' => Plans::features($p)], $plans);
$yearly = array_filter($shown, static fn(array $p): bool => $p['_price']['yearly'] !== null) !== [];
$saving = max([0, ...array_map(static fn(array $p): int => (int) $p['_price']['saving'], $shown)]);
$previous = null;
?>
<div class="pricing">
    <?php if ($yearly): ?>
        <input class="pricing-switch-input" type="checkbox" id="pay-yearly" aria-label="Show yearly prices">
        <div class="pricing-toggle">
            <span class="pricing-toggle-monthly">Monthly</span>
            <label class="pricing-switch" for="pay-yearly"><span></span></label>
            <span class="pricing-toggle-yearly">Yearly</span>
            <?php if ($saving > 0): ?><span class="pricing-save">Save up to <?= $saving ?>%</span><?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="plan-grid plan-grid--<?= min(4, max(1, count($shown))) ?>">
        <?php foreach ($shown as $k => $p): $price = $p['_price']; $featured = (int) ($p['featured'] ?? 0) === 1; ?>
            <article class="plan-card<?= $featured ? ' is-featured' : '' ?>" data-reveal="<?= $k % 4 + 1 ?>">
                <div class="plan-head">
                    <h3 class="plan-name"><?= e($p['name']) ?></h3>
                    <?php if ($featured): ?><span class="plan-badge"><?= icon('stars') ?>Most popular</span><?php endif; ?>
                </div>
                <?php if ($p['summary'] !== null): ?><p class="plan-summary"><?= e($p['summary']) ?></p><?php endif; ?>
                <div class="plan-price">
                    <?php if ($price['monthly'] === null): ?>
                        <span class="plan-amount"><?= e($price['text']) ?></span>
                    <?php else: ?>
                        <div class="when-monthly"><span class="plan-amount"><?= e($price['monthly']) ?></span><span class="plan-per">/month</span></div>
                        <?php if ($yearly): ?>
                        <div class="when-yearly">
                            <?php if ($price['yearly'] !== null): ?>
                                <span class="plan-amount"><?= e((string) $price['yearly_monthly']) ?></span><span class="plan-per">/month</span>
                                <span class="plan-billed"><?= e((string) $price['yearly']) ?> billed yearly<?= $price['saving'] !== null ? ', save ' . $price['saving'] . '%' : '' ?></span>
                            <?php else: ?>
                                <span class="plan-amount"><?= e($price['monthly']) ?></span><span class="plan-per">/month</span>
                                <span class="plan-billed">Paid monthly only</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <a class="btn plan-cta <?= $featured ? 'btn-primary' : 'site-btn-outline' ?>" href="<?= e(url('/contact', ['plan' => $p['uuid']])) ?>">
                    <?= $price['monthly'] === null ? 'Talk to us' : 'Start with ' . e($p['name']) ?></a>
                <div class="plan-features">
                    <?php if ($p['_features'] !== []): ?>
                        <p class="plan-features-head"><?= (int) ($p['includes_previous'] ?? 0) === 1 && $previous !== null ? 'Everything in ' . e($previous) . ', plus:' : 'What\'s included:' ?></p>
                        <ul>
                            <?php foreach ($p['_features'] as $f): ?><li><?= icon('check2') ?><span><?= e($f) ?></span></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="plan-limits">
                    <span><?= icon('mortarboard') ?><?= $p['max_students'] !== null ? 'Up to ' . number_format((int) $p['max_students']) . ' students' : 'Unlimited students' ?></span>
                    <span><?= icon('person-badge') ?><?= $p['max_staff'] !== null ? number_format((int) $p['max_staff']) . ' staff' : 'Unlimited staff' ?></span>
                </div>
            </article>
        <?php $previous = (string) $p['name']; endforeach; ?>
    </div>
</div>
