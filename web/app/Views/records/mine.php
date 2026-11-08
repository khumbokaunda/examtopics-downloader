<?php /** @var list<array> $records */
use App\Services\AcademicRecords;
?>
<header class="page-header"><div class="page-header-text"><h1 class="page-title">My academic record</h1>
    <p class="page-subtitle">Your record with each institution you are a student of: final results, the modules you completed and the transcripts issued to you. Each institution keeps its own record.</p></div></header>
<?php if ($records === []): ?>
    <div class="card"><div class="card-body"><?= empty_state('journal-bookmark', 'No institutional record', 'You are not a student of an institution on ExamLib.', ['Join with a code', url('/enrol')]) ?></div></div>
<?php endif; ?>
<?php foreach ($records as $item): $r = $item['record']; ?>
<div class="card mb-4">
    <div class="card-header"><span class="card-title"><?= icon('building') ?><?= e((string) $r['institution']['name']) ?></span><?= $item['active'] ? '' : chip('You no longer belong', 'neutral') ?></div>
    <div class="card-body">
        <?php foreach ($r['programmes'] as $p): ?>
            <p class="mb-1"><strong><?= e($p['name']) ?></strong> <?= chip(AcademicRecords::OUTCOMES[$p['outcome']] ?? $p['outcome'], $p['outcome'] === 'completed' ? 'success' : 'neutral') ?></p>
            <ul class="small"><?php foreach ($p['modules'] as $m): ?><li><?= e($m['name']) ?>: <?= $m['completed_at'] !== null ? 'completed ' . e(substr((string) $m['completed_at'], 0, 10)) : 'not completed yet' ?></li><?php endforeach; ?></ul>
        <?php endforeach; ?>
        <?php if ($r['results'] !== []): ?>
            <p class="small text-muted mb-1">Published results</p>
            <ul class="small"><?php foreach ($r['results'] as $s): ?><li><?= e($s['title']) ?>: <?= $s['percent'] !== null ? e(rtrim(rtrim(number_format($s['percent'], 2), '0'), '.')) . '%' : 'no score' ?><?= $s['passed'] === null ? '' : ($s['passed'] ? ', passed' : ', not passed') ?></li><?php endforeach; ?></ul>
        <?php elseif ($r['programmes'] === []): ?><p class="small text-muted mb-0">Nothing published yet.</p><?php endif; ?>
        <?php if ($item['transcripts'] !== []): ?>
            <p class="small text-muted mb-1">Transcripts</p>
            <?php foreach ($item['transcripts'] as $t): ?><a class="btn btn-sm btn-outline-primary me-2 mb-2" href="<?= e(url('/my/record/' . $r['institution']['uuid'] . '/transcripts/' . $t['uuid'] . '.pdf')) ?>"><?= icon('download') ?><?= e((string) $t['number']) ?></a><?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
