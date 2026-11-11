<?php /** The public pages' footer. @var array $site @var string $appName @var string|null $logoUrl @var string $tagline */ ?>
<footer class="site-footer">
    <div class="site-container site-footer-grid">
        <div class="site-footer-brand">
            <a class="site-brand" href="<?= e(url('/')) ?>"><?php if ($logoUrl !== null): ?><img class="brand-logo" src="<?= e($logoUrl) ?>" alt=""><?php else: ?><span class="brand-mark"><?= icon('mortarboard-fill') ?></span><?php endif; ?><span><?= e($appName) ?></span></a>
            <?php if ($tagline !== ''): ?><p><?= e($tagline) ?></p><?php endif; ?>
        </div>
        <div>
            <h2 class="site-footer-head">Product</h2>
            <ul>
                <li><a href="<?= e(url('/') . '#features') ?>">Features</a></li>
                <li><a href="<?= e(url('/') . '#how-it-works') ?>">How it works</a></li>
                <li><a href="<?= e(url('/pricing')) ?>">Pricing</a></li>
            </ul>
        </div>
        <div>
            <h2 class="site-footer-head">Get started</h2>
            <ul>
                <li><a href="<?= e(url('/contact')) ?>">Ask for a demonstration</a></li>
                <li><a href="<?= e(url('/login')) ?>">Sign in</a></li>
            </ul>
        </div>
        <?php if ($site['contact_email'] !== '' || $site['contact_phone'] !== ''): ?>
        <div>
            <h2 class="site-footer-head">Contact</h2>
            <ul>
                <?php if ($site['contact_email'] !== ''): ?><li><a href="mailto:<?= e($site['contact_email']) ?>"><?= e($site['contact_email']) ?></a></li><?php endif; ?>
                <?php if ($site['contact_phone'] !== ''): ?><li><?= e($site['contact_phone']) ?></li><?php endif; ?>
                <?php if ($site['contact_hours'] !== ''): ?><li class="text-muted"><?= e($site['contact_hours']) ?></li><?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
    <div class="site-container site-footer-bottom">© <?= gmdate('Y') ?> <?= e($appName) ?></div>
</footer>
