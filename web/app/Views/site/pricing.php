<?php /** @var array $site @var list<array> $plans */ ?>
<section class="site-hero">
    <h1>Pricing</h1>
    <p>Plans differ by how many students and staff an institution has at once. Everything else is in every plan.</p>
</section>
<?php if ($plans === []): ?>
    <div class="card"><div class="card-body"><?= empty_state('tags', 'Prices on request', 'Tell us about your institution and we will send a price.', null, true) ?></div></div>
<?php else: ?>
    <?= \App\Core\View::current()->partial('site/_plans', ['plans' => $plans]) ?>
<?php endif; ?>
<div class="site-actions mt-4"><a class="btn btn-primary" href="<?= e(url('/contact')) ?>"><?= icon('chat-left-text') ?>Ask about a plan</a></div>
