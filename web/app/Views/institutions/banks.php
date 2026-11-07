<?php /** @var array $institution @var array $membership @var string $tab @var list<array> $banks @var bool $all @var bool $archived @var bool $canCreate @var int|null $inReview @var list<array> $programmes */
$base = '/institutions/' . $institution['uuid'] . '/banks';
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<?php if ($inReview !== null && $inReview > 0): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="status"><?= icon('hourglass-split') ?><div class="alert-body flex-grow-1"><?= $inReview ?> <?= $inReview === 1 ? 'question waits' : 'questions wait' ?> for review.</div>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/review')) ?>"><?= icon('check2-square') ?>Review them</a></div>
<?php endif; ?>
<div class="row g-4">
    <div class="<?= $canCreate ? 'col-xl-8' : 'col-12' ?>">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('database') ?><?= $archived ? 'Archived question banks' : ($all ? 'Question banks' : 'Your question banks') ?></span>
                <a class="small" href="<?= e(url($base, $archived ? [] : ['status' => 'archived'])) ?>"><?= $archived ? 'Open banks' : 'Archived banks' ?></a></div>
            <?php if ($banks === []): ?>
                <div class="card-body"><?= empty_state('database', $archived ? 'No archived banks' : 'No question banks yet', $canCreate ? 'A bank holds questions on one subject, organised by topic. Create the first one here.' : 'Banks you create, or are added to, show here.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack" data-table="question-banks" data-order='[[0,"asc"]]'>
                    <thead><tr><th>Bank</th><th>Subject</th><th data-type="num">Questions</th><th data-type="num">Published</th><th data-type="num">In review</th></tr></thead>
                    <tbody>
                    <?php foreach ($banks as $b): ?>
                        <tr>
                            <td class="stack-title" data-order="<?= e(strtolower($b['name'])) ?>"><a class="table-row-link" href="<?= e(url($base . '/' . $b['uuid'])) ?>"><?= e($b['name']) ?></a>
                                <div class="small text-muted"><?= e($b['programme_name'] ?? '') ?><?= $b['programme_name'] !== null && $b['creator_name'] !== null ? ' · ' : '' ?><?= $b['creator_name'] !== null ? 'by ' . e($b['creator_name']) : '' ?></div></td>
                            <td class="small" data-label="Subject"><?= e((string) ($b['subject'] ?? '')) ?></td>
                            <td class="small" data-label="Questions" data-order="<?= (int) $b['question_count'] ?>"><?= (int) $b['question_count'] ?></td>
                            <td class="small" data-label="Published" data-order="<?= (int) $b['published_count'] ?>"><?= (int) $b['published_count'] ?></td>
                            <td class="small" data-label="In review" data-order="<?= (int) $b['review_count'] ?>"><?= (int) $b['review_count'] > 0 ? chip((string) $b['review_count'], 'warning') : '0' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canCreate): ?>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('database-add') ?>New question bank</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base)) ?>" data-once><?= csrf_field() ?>
                    <?= \App\Core\View::current()->partial('institutions/_bank_fields', ['b' => null, 'programmes' => $programmes]) ?>
                    <button class="btn btn-primary" type="submit"><?= icon('plus') ?>Create the bank</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
