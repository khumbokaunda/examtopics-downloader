<?php
/** The public home page, for someone signed out. @var array $site @var list<array> $plans @var string $currency */
$v = \App\Core\View::current();
$brand = $v->branding()->get();
$headline = $site['headline'] !== '' ? $site['headline'] : 'Run every test, mark and result in one place';
$highlight = $site['highlight'] !== '' ? $site['highlight'] : ($site['headline'] === '' ? 'in one place' : '');
// The highlighted words, in the accent colour, when they are part of the headline.
$h1 = $highlight !== '' && str_contains($headline, $highlight)
    ? str_replace(e($highlight), '<span class="site-accent">' . e($highlight) . '</span>', e($headline))
    : e($headline);
$features = [
    ['collection', 'Question banks', 'Write questions, have them reviewed and approved, and keep every version. Bring in hundreds at once from a spreadsheet or Aiken text.'],
    ['stopwatch', 'Invigilated sittings', 'A clock kept by the server, invigilators who can pause, extend and message, and answers kept on the device when the connection drops.'],
    ['bar-chart', 'Results and reports', 'Publish results when they are ready. See how each topic, class and student did, and download CSV and PDF reports.'],
    ['journal-text', 'Courses and gradebook', 'Notices, materials and assignments marked with a rubric, all adding up in a weighted gradebook for each class.'],
    ['patch-check', 'Transcripts and credentials', 'Issue numbered transcripts and signed credentials that anyone can check with a link.'],
    ['people', 'Students and enrolment', 'Import a class list, hand out an enrolment code, and keep each student\'s programmes and modules on record.'],
];
$steps = [
    ['Build your question banks', 'Add questions by hand or import them. Reviewers approve them before they are used.'],
    ['Run the assessment', 'Assign it to a class, open it at the set time and watch the sitting live.'],
    ['Publish and certify', 'Mark written answers, publish results and issue transcripts and credentials.'],
];
$points = [
    ['wallet2', 'Pay locally', 'Plans in ' . $currency . ', paid by mobile money, bank transfer or cash against an invoice.'],
    ['wifi-off', 'Ready for weak connections', 'Answers are kept on the candidate\'s device until the server has them.'],
    ['box-arrow-down', 'Your data stays yours', 'Download everything your institution holds as one file, at any time.'],
    ['shield-lock', 'Safe by design', 'Two-step sign-in, roles for every member of staff, and a history of every change.'],
];
$faq = [
    ['Who is it for?', 'Colleges, training centres and anyone who runs tests for a class: from a short course with one test to a programme with many modules.'],
    ['Do students need to install anything?', 'No. Students sit tests in their web browser, on a computer or a phone.'],
    ['What if the internet goes down during a test?', 'The student carries on answering. Answers are kept on their device and sent when the connection comes back, and the clock is kept by the server, so it is the same for everyone.'],
    ['How do we pay?', 'We send an invoice in ' . $currency . '. Pay by mobile money, bank transfer or cash, then tell us the transaction reference on your billing page.'],
    ['Can we take our data with us?', 'Yes. Your institution\'s owner can download all of its records and files as one ZIP of spreadsheets at any time.'],
];
?>
<section class="site-hero">
    <div class="site-container site-hero-inner">
        <span class="site-eyebrow"><?= e($site['eyebrow'] !== '' ? $site['eyebrow'] : 'For colleges and training providers') ?></span>
        <h1 class="site-title"><?= $h1 ?></h1>
        <p class="site-lead"><?= e($site['intro'] !== '' ? $site['intro'] : 'Question banks, invigilated tests, results, courses and certificates for institutions that assess their students.') ?></p>
        <div class="site-actions">
            <a class="btn btn-primary btn-lg" href="<?= e(url('/contact')) ?>">Ask for a demonstration<?= icon('arrow-right') ?></a>
            <?php if ($plans !== []): ?><a class="btn btn-lg site-btn-outline" href="<?= e(url('/pricing')) ?>">See pricing</a><?php endif; ?>
        </div>

        <div class="site-preview" aria-hidden="true">
            <div class="site-preview-bar"><span></span><span></span><span></span><em>Module test · Question 4 of 20</em></div>
            <div class="site-preview-body">
                <div class="site-preview-main">
                    <div class="site-preview-row"><strong>Question 4</strong><span class="site-preview-timer"><?= icon('stopwatch') ?>24:16</span></div>
                    <p>Which layer of the OSI model decides the path a packet takes?</p>
                    <ul>
                        <li>Physical</li>
                        <li class="is-picked"><?= icon('check-circle-fill') ?>Network</li>
                        <li>Session</li>
                        <li>Presentation</li>
                    </ul>
                    <div class="site-preview-dots"><?php for ($n = 1; $n <= 20; $n++): ?><i class="<?= $n < 4 ? 'is-done' : ($n === 4 ? 'is-now' : '') ?>"></i><?php endfor; ?></div>
                </div>
                <div class="site-preview-side">
                    <div class="site-preview-card"><span>Saved</span><strong><?= icon('cloud-check') ?>All answers</strong></div>
                    <div class="site-preview-card"><span>Class average</span><div class="site-preview-bars"><i></i><i></i><i></i><i></i><i></i></div></div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="site-section" id="features">
    <div class="site-container">
        <div class="site-section-head">
            <span class="site-eyebrow">What you get</span>
            <h2>Everything an assessment needs, from the first question to the certificate</h2>
        </div>
        <div class="site-feature-grid">
            <?php foreach ($features as [$iconName, $name, $line]): ?>
                <div class="site-feature"><span class="site-feature-icon"><?= icon($iconName) ?></span><h3><?= e($name) ?></h3><p><?= e($line) ?></p></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="site-section site-section--alt" id="how-it-works">
    <div class="site-container">
        <div class="site-section-head">
            <span class="site-eyebrow">How it works</span>
            <h2>Three steps to your next assessment</h2>
        </div>
        <ol class="site-steps">
            <?php foreach ($steps as $n => [$name, $line]): ?>
                <li><span class="site-step-number"><?= $n + 1 ?></span><h3><?= e($name) ?></h3><p><?= e($line) ?></p></li>
            <?php endforeach; ?>
        </ol>
        <div class="site-points">
            <?php foreach ($points as [$iconName, $name, $line]): ?>
                <div class="site-point"><?= icon($iconName) ?><div><strong><?= e($name) ?></strong><p><?= e($line) ?></p></div></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($plans !== []): ?>
<section class="site-section" id="pricing">
    <div class="site-container">
        <div class="site-section-head">
            <span class="site-eyebrow">Pricing</span>
            <h2>Plans that grow with your <span class="site-accent">institution</span></h2>
            <p>Plans are sized by how many students and staff you have at once.</p>
        </div>
        <?= $v->partial('site/_pricing', ['plans' => $plans, 'currency' => $currency]) ?>
    </div>
</section>
<?php endif; ?>

<section class="site-section<?= $plans !== [] ? ' site-section--alt' : '' ?>" id="questions">
    <div class="site-container site-narrow">
        <div class="site-section-head">
            <span class="site-eyebrow">Questions</span>
            <h2>Asked often</h2>
        </div>
        <div class="site-faq">
            <?php foreach ($faq as [$q, $a]): ?><details><summary><?= e($q) ?><?= icon('plus-lg') ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?>
        </div>
    </div>
</section>

<section class="site-cta">
    <div class="site-container site-cta-inner">
        <h2>Ready to run your next assessment on <?= e($brand['name']) ?>?</h2>
        <p>Tell us about your institution and we will set you up and show you around.</p>
        <div class="site-actions"><a class="btn btn-primary btn-lg" href="<?= e(url('/contact')) ?>">Ask for a demonstration<?= icon('arrow-right') ?></a></div>
    </div>
</section>
