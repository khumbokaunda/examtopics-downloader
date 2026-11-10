<?php
/** The public home page, for someone signed out. @var array $site @var list<array> $plans */
$brand = \App\Core\View::current()->branding()->get();
$features = [
    ['collection', 'Question banks', 'Write, review and approve questions. Import them from a spreadsheet or Aiken text.'],
    ['pencil-square', 'Assessments', 'Timed sittings with invigilation, saved as candidates go, even offline.'],
    ['bar-chart', 'Results', 'Publish results when they are ready, with reports by topic, class and student.'],
    ['easel', 'Courses', 'Notices, materials, assignments and a gradebook for each class.'],
    ['patch-check', 'Records', 'Transcripts and signed credentials anyone can check.'],
];
?>
<section class="site-hero">
    <h1><?= e($site['headline'] !== '' ? $site['headline'] : $brand['name']) ?></h1>
    <p><?= e($site['intro'] !== '' ? $site['intro'] : 'Assessments for training providers: question banks, invigilated tests, results, courses and records in one place.') ?></p>
    <div class="site-actions">
        <a class="btn btn-primary" href="<?= e(url('/contact')) ?>"><?= icon('chat-left-text') ?>Contact us</a>
        <?php if ($plans !== []): ?><a class="btn btn-outline-primary" href="<?= e(url('/pricing')) ?>"><?= icon('tags') ?>See pricing</a><?php endif; ?>
    </div>
</section>
<div class="site-features">
    <?php foreach ($features as [$iconName, $name, $line]): ?>
        <div class="card site-feature"><div class="card-body"><?= icon($iconName) ?><h2 class="h6 mt-2 mb-1"><?= e($name) ?></h2><p class="small text-muted mb-0"><?= e($line) ?></p></div></div>
    <?php endforeach; ?>
</div>
