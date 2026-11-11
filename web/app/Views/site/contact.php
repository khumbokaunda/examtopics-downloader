<?php /** @var array $site @var array<string,string> $old @var bool $sent @var list<array> $plans */
$val = static fn(string $k): string => e((string) ($old[$k] ?? ''));
$chosen = (string) ($old['plan'] ?? '');
?>
<section class="site-hero site-hero--compact">
    <div class="site-container site-hero-inner">
        <span class="site-eyebrow">Contact</span>
        <h1 class="site-title">Let's talk about your <span class="site-accent">institution</span></h1>
        <p class="site-lead">Ask about a plan, book a demonstration or get help setting up. We reply by email.</p>
    </div>
</section>
<section class="site-section site-section--tight">
    <div class="site-container site-contact">
        <div class="site-contact-form">
            <?php if ($sent): ?>
                <div class="site-empty"><?= icon('check2-circle') ?><h2>Message sent</h2><p>Thank you. We will reply by email.</p>
                    <a class="btn site-btn-outline" href="<?= e(url('/')) ?>">Back to the home page</a></div>
            <?php else: ?>
            <form method="post" action="<?= e(url('/contact')) ?>">
                <?= csrf_field() ?>
                <div class="site-hp" aria-hidden="true"><label for="c-website">Leave this empty</label><input id="c-website" name="website" tabindex="-1" autocomplete="off"></div>
                <div class="row g-3">
                    <div class="col-sm-6"><label class="form-label" for="c-name">Your name</label><input class="form-control" id="c-name" name="name" required maxlength="120" value="<?= $val('name') ?>" autocomplete="name"></div>
                    <div class="col-sm-6"><label class="form-label" for="c-email">Email</label><input class="form-control" id="c-email" name="email" type="email" required maxlength="190" value="<?= $val('email') ?>" autocomplete="email"></div>
                    <div class="col-sm-6"><label class="form-label" for="c-org">Institution <span class="text-muted small">(optional)</span></label><input class="form-control" id="c-org" name="organisation" maxlength="160" value="<?= $val('organisation') ?>" autocomplete="organization"></div>
                    <div class="col-sm-6"><label class="form-label" for="c-phone">Phone <span class="text-muted small">(optional)</span></label><input class="form-control" id="c-phone" name="phone" type="tel" maxlength="40" value="<?= $val('phone') ?>" autocomplete="tel"></div>
                    <?php if ($plans !== []): ?>
                    <div class="col-12"><label class="form-label" for="c-plan">Plan you are interested in <span class="text-muted small">(optional)</span></label>
                        <select class="form-select" id="c-plan" name="plan">
                            <option value="">Not sure yet</option>
                            <?php foreach ($plans as $p): ?><option value="<?= e($p['uuid']) ?>"<?= $chosen === $p['uuid'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                        </select></div>
                    <?php endif; ?>
                    <div class="col-12"><label class="form-label" for="c-message">Message</label><textarea class="form-control" id="c-message" name="message" rows="6" required maxlength="5000" placeholder="How many students do you have, and what do you assess?"><?= $val('message') ?></textarea></div>
                </div>
                <button class="btn btn-primary btn-lg mt-4" type="submit"><?= icon('send') ?>Send message</button>
            </form>
            <?php endif; ?>
        </div>
        <aside class="site-contact-side">
            <div class="site-contact-card">
                <h2>What happens next</h2>
                <ol>
                    <li>We reply to you by email.</li>
                    <li>We show you around and answer your questions.</li>
                    <li>We set up your institution, and you invite your staff and students.</li>
                </ol>
            </div>
            <?php if ($site['contact_email'] !== '' || $site['contact_phone'] !== '' || $site['contact_hours'] !== ''): ?>
            <div class="site-contact-card">
                <h2>Other ways to reach us</h2>
                <ul>
                    <?php if ($site['contact_email'] !== ''): ?><li><?= icon('envelope') ?><a href="mailto:<?= e($site['contact_email']) ?>"><?= e($site['contact_email']) ?></a></li><?php endif; ?>
                    <?php if ($site['contact_phone'] !== ''): ?><li><?= icon('telephone') ?><span><?= e($site['contact_phone']) ?></span></li><?php endif; ?>
                    <?php if ($site['contact_hours'] !== ''): ?><li><?= icon('clock') ?><span><?= e($site['contact_hours']) ?></span></li><?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
        </aside>
    </div>
</section>
