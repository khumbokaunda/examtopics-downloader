<?php /** @var array $site @var array<string,string> $old @var bool $sent */
$v = static fn(string $k): string => e((string) ($old[$k] ?? ''));
?>
<section class="site-hero">
    <h1>Contact</h1>
    <p>Questions about a plan, a demonstration or getting started.</p>
</section>
<div class="row g-4 justify-content-center">
    <div class="col-lg-7">
        <?php if ($sent): ?>
            <div class="card"><div class="card-body"><?= empty_state('check2-circle', 'Message sent', 'Thank you. We will reply by email.', null, true) ?></div></div>
        <?php else: ?>
        <form class="card" method="post" action="<?= e(url('/contact')) ?>"><div class="card-body">
            <?= csrf_field() ?>
            <div class="site-hp" aria-hidden="true"><label for="c-website">Leave this empty</label><input id="c-website" name="website" tabindex="-1" autocomplete="off"></div>
            <div class="row g-3">
                <div class="col-sm-6"><label class="form-label" for="c-name">Your name</label><input class="form-control" id="c-name" name="name" required maxlength="120" value="<?= $v('name') ?>" autocomplete="name"></div>
                <div class="col-sm-6"><label class="form-label" for="c-email">Email</label><input class="form-control" id="c-email" name="email" type="email" required maxlength="190" value="<?= $v('email') ?>" autocomplete="email"></div>
                <div class="col-sm-6"><label class="form-label" for="c-org">Institution <span class="text-muted small">(optional)</span></label><input class="form-control" id="c-org" name="organisation" maxlength="160" value="<?= $v('organisation') ?>" autocomplete="organization"></div>
                <div class="col-sm-6"><label class="form-label" for="c-phone">Phone <span class="text-muted small">(optional)</span></label><input class="form-control" id="c-phone" name="phone" type="tel" maxlength="40" value="<?= $v('phone') ?>" autocomplete="tel"></div>
                <div class="col-12"><label class="form-label" for="c-message">Message</label><textarea class="form-control" id="c-message" name="message" rows="6" required maxlength="5000"><?= $v('message') ?></textarea></div>
            </div>
            <button class="btn btn-primary mt-3" type="submit"><?= icon('send') ?>Send</button>
        </div></form>
        <?php endif; ?>
    </div>
    <?php if ($site['contact_email'] !== '' || $site['contact_phone'] !== '' || $site['contact_hours'] !== ''): ?>
    <div class="col-lg-4">
        <div class="card"><div class="card-body">
            <h2 class="h6">Other ways to reach us</h2>
            <ul class="list-unstyled small mb-0">
                <?php if ($site['contact_email'] !== ''): ?><li class="mb-2"><?= icon('envelope') ?> <a href="mailto:<?= e($site['contact_email']) ?>"><?= e($site['contact_email']) ?></a></li><?php endif; ?>
                <?php if ($site['contact_phone'] !== ''): ?><li class="mb-2"><?= icon('telephone') ?> <?= e($site['contact_phone']) ?></li><?php endif; ?>
                <?php if ($site['contact_hours'] !== ''): ?><li><?= icon('clock') ?> <?= e($site['contact_hours']) ?></li><?php endif; ?>
            </ul>
        </div></div>
    </div>
    <?php endif; ?>
</div>
