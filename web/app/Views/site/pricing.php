<?php /** @var array $site @var list<array> $plans @var string $currency */
$v = \App\Core\View::current();
$everyPlan = [
    ['collection', 'Question banks with review'], ['stopwatch', 'Timed, invigilated sittings'], ['bar-chart', 'Results, reports and exports'],
    ['journal-text', 'Courses, assignments and gradebook'], ['patch-check', 'Transcripts and credentials'], ['people', 'Class lists and enrolment codes'],
    ['shield-lock', 'Two-step sign-in and activity history'], ['box-arrow-down', 'Export all of your data'],
];
$faq = [
    ['How do we pay?', 'We send an invoice in ' . $currency . '. Pay by mobile money, bank transfer or cash, then enter the transaction reference on your billing page. We confirm it and the invoice becomes your receipt.'],
    ['What counts as a student or a member of staff?', 'Everyone who belongs to your institution at the same time: students on one side, everyone else (owners, administrators, instructors, examination and invigilation staff) on the other. Former students do not count.'],
    ['Can we change plans later?', 'Yes. Ask us and we move you to the new plan. A smaller plan is possible once you have no more people than it allows.'],
    ['What happens to our data if we leave?', 'It is yours. Download all of your records and files as one ZIP of spreadsheets at any time.'],
];
?>
<section class="site-hero site-hero--compact">
    <div class="site-container site-hero-inner">
        <span class="site-eyebrow">Pricing</span>
        <h1 class="site-title">Simple pricing for every <span class="site-accent">institution</span></h1>
        <p class="site-lead">Choose the plan that fits your institution. Plans are sized by how many students and staff you have at once.</p>
    </div>
</section>

<section class="site-section site-section--tight">
    <div class="site-container">
        <?php if ($plans === []): ?>
            <div class="site-empty"><?= icon('tags') ?><h2>Prices on request</h2><p>Tell us about your institution and we will send you a price.</p>
                <a class="btn btn-primary" href="<?= e(url('/contact')) ?>">Ask for a price<?= icon('arrow-right') ?></a></div>
        <?php else: ?>
            <?= $v->partial('site/_pricing', ['plans' => $plans, 'currency' => $currency]) ?>
            <p class="site-note">Not sure which plan fits? <a href="<?= e(url('/contact')) ?>">Ask for a demonstration<?= icon('arrow-up-right') ?></a></p>
        <?php endif; ?>
    </div>
</section>

<section class="site-section site-section--alt">
    <div class="site-container">
        <div class="site-section-head">
            <span class="site-eyebrow">In every plan</span>
            <h2>What every plan includes</h2>
        </div>
        <div class="site-included">
            <?php foreach ($everyPlan as [$iconName, $label]): ?><div><?= icon($iconName) ?><span><?= e($label) ?></span></div><?php endforeach; ?>
        </div>
    </div>
</section>

<section class="site-section">
    <div class="site-container site-narrow">
        <div class="site-section-head">
            <span class="site-eyebrow">Questions</span>
            <h2>About plans and paying</h2>
        </div>
        <div class="site-faq">
            <?php foreach ($faq as [$q, $a]): ?><details><summary><?= e($q) ?><?= icon('plus-lg') ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?>
        </div>
    </div>
</section>
