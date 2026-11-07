<?php /** @var array $institution @var array $membership @var string $tab @var list<array> $rows @var string $status @var bool $all @var bool $canCreate */
use App\Services\Assessments;
$base = '/institutions/' . $institution['uuid'] . '/assessments';
$tone = ['draft' => 'neutral', 'published' => 'success', 'closed' => 'info', 'archived' => 'neutral'];
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<div class="row g-4">
    <div class="<?= $canCreate ? 'col-xl-8' : 'col-12' ?>">
        <form class="toolbar mb-3" method="get" action="<?= e(url($base)) ?>">
            <select class="form-select" name="status" aria-label="Status" data-autosubmit><option value="">Not archived</option>
                <?php foreach (Assessments::STATUSES as $k => $v): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
            <button class="btn btn-outline-secondary" type="submit">Show</button>
        </form>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('clipboard-check') ?><?= $all ? 'Assessments' : 'Your assessments' ?></span></div>
            <?php if ($rows === []): ?>
                <div class="card-body"><?= empty_state('clipboard-check', 'No assessments here', $canCreate ? 'Create one, then add questions from the question banks.' : '', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack" data-table="institution-assessments" data-order='[[4,"desc"]]'>
                    <thead><tr><th>Assessment</th><th>Status</th><th data-type="num">Questions</th><th data-type="num">Assigned</th><th data-type="num">Changed</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="stack-title" data-order="<?= e(strtolower($r['title'])) ?>"><a class="table-row-link" href="<?= e(url($base . '/' . $r['uuid'])) ?>"><?= e($r['title']) ?></a>
                                <div class="small text-muted"><?= e(Assessments::TYPES[$r['type']] ?? $r['type']) ?><?= $r['creator_name'] !== null ? ' · by ' . e($r['creator_name']) : '' ?></div></td>
                            <td data-label="Status"><?= chip(Assessments::STATUSES[$r['status']] ?? $r['status'], $tone[$r['status']] ?? 'neutral') ?></td>
                            <td class="small" data-label="Questions" data-order="<?= (int) $r['item_count'] + (int) $r['drawn_count'] ?>"><?= (int) $r['item_count'] + (int) $r['drawn_count'] ?></td>
                            <td class="small" data-label="Assigned" data-order="<?= (int) $r['assignment_count'] ?>"><?= (int) $r['assignment_count'] ?></td>
                            <td class="small" data-label="Changed" data-order="<?= e((string) $r['updated_at']) ?>"><?= e(fmt_ago((string) $r['updated_at'])) ?></td>
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
            <div class="card-header"><span class="card-title"><?= icon('clipboard-plus') ?>New assessment</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base)) ?>" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="atitle">Title</label>
                        <input class="form-control" id="atitle" name="title" maxlength="200" required placeholder="CCNA 1 end of module test"></div>
                    <div class="mb-3"><label class="form-label" for="atype">Kind</label>
                        <select class="form-select" id="atype" name="type">
                            <?php foreach (Assessments::TYPES as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === 'summative' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text">The kind decides how results are described. A formal examination is official only where the institution is authorised to award it.</div></div>
                    <button class="btn btn-primary" type="submit"><?= icon('plus') ?>Create as a draft</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
