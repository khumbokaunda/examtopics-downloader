<?php /** @var array{on:bool,eyebrow:string,headline:string,highlight:string,intro:string,contact_email:string,contact_phone:string,contact_hours:string} $site */ ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/settings')) ?>">Settings</a></li>
    <li class="breadcrumb-item active" aria-current="page">Public site</li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Public site</h1>
        <p class="page-subtitle">A home page, pricing and contact page anyone can read.</p>
    </div>
    <?php if ($site['on']): ?><div class="page-actions"><a class="btn btn-outline-secondary" href="<?= e(url('/pricing')) ?>" target="_blank" rel="noopener"><?= icon('box-arrow-up-right') ?>Open pricing</a></div><?php endif; ?>
</header>
<div class="row g-4">
    <div class="col-lg-8">
        <form class="card" method="post" action="<?= e(url('/settings/site')) ?>" data-once data-busy="Saving…"><?= csrf_field() ?>
            <div class="card-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="s-on" name="on" value="1"<?= $site['on'] ? ' checked' : '' ?>>
                    <label class="form-check-label" for="s-on">Show the public pages</label>
                    <div class="form-text">Off: people who are not signed in see only the sign-in page, as before.</div>
                </div>
                <div class="mb-3"><label class="form-label" for="s-eyebrow">Label above the headline</label>
                    <input class="form-control" id="s-eyebrow" name="eyebrow" maxlength="60" value="<?= e($site['eyebrow']) ?>" placeholder="For colleges and training providers"></div>
                <div class="mb-3"><label class="form-label" for="s-headline">Headline</label>
                    <input class="form-control" id="s-headline" name="headline" maxlength="160" value="<?= e($site['headline']) ?>" placeholder="Run every test, mark and result in one place"></div>
                <div class="mb-3"><label class="form-label" for="s-highlight">Words to highlight</label>
                    <input class="form-control" id="s-highlight" name="highlight" maxlength="80" value="<?= e($site['highlight']) ?>">
                    <div class="form-text">Part of the headline, shown in the accent colour. For example: Malawi's first exam library.</div></div>
                <div class="mb-3"><label class="form-label" for="s-intro">Introduction</label>
                    <textarea class="form-control" id="s-intro" name="intro" rows="3" maxlength="600"><?= e($site['intro']) ?></textarea></div>
                <h2 class="section-heading">Contact details</h2>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="s-email">Email</label><input class="form-control" id="s-email" name="contact_email" type="email" maxlength="190" value="<?= e($site['contact_email']) ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="s-phone">Phone</label><input class="form-control" id="s-phone" name="contact_phone" maxlength="40" value="<?= e($site['contact_phone']) ?>"></div>
                    <div class="col-12"><label class="form-label" for="s-hours">Hours</label><input class="form-control" id="s-hours" name="contact_hours" maxlength="120" value="<?= e($site['contact_hours']) ?>" placeholder="Monday to Friday, 8:00 to 17:00"></div>
                </div>
                <button class="btn btn-primary mt-3" type="submit"><?= icon('check2') ?>Save</button>
            </div>
        </form>
    </div>
    <div class="col-lg-4">
        <div class="card"><div class="card-body small text-muted">
            <p>The pricing page lists the plans set under <a href="<?= e(url('/platform/plans')) ?>">Plans</a>. Messages sent from the contact page arrive under <a href="<?= e(url('/platform/messages')) ?>">Messages</a>.</p>
            <p class="mb-0">Only these three pages can be listed by search engines. Everything else stays hidden from them.</p>
        </div></div>
    </div>
</div>
