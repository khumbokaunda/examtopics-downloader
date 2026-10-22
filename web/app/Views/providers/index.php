<?php /** @var list<array> $providers @var array<string,array> $activeJobs */
$collector = can('exams.collect');
$first = can('inventory.first');
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= $collector ? 'Providers &amp; inventory' : 'Providers' ?></h1>
        <p class="page-subtitle">Providers come from ExamTopics itself. <strong>Listed</strong> is how many exams ExamTopics shows for a provider; <strong>Exams</strong> is how many have discussions you can collect, found by refreshing its inventory.</p>
    </div>
    <?php if ($collector): ?>
    <div class="page-actions">
        <form method="post" action="<?= e(url('/providers/discover')) ?>" data-once><?= csrf_field() ?>
            <button class="btn btn-outline-primary" type="submit" <?= isset($activeJobs['*']) ? 'disabled' : '' ?>>
                <?= icon('radar') ?><?= isset($activeJobs['*']) ? 'Discovering…' : 'Discover providers' ?></button>
        </form>
    </div>
    <?php endif; ?>
</header>

<div class="card mb-4">
    <?php if ($providers === []): ?>
        <div class="card-body"><?= $collector
            ? empty_state('buildings', 'No providers yet', 'Use <strong>Discover providers</strong>, or add one by its identifier below (for example <code>huawei</code>).')
            : empty_state('buildings', 'No providers yet', 'An administrator has not loaded the providers yet.') ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="providers" data-order='[[0,"asc"]]'>
            <thead><tr>
                <th>Provider</th>
                <th>Identifier</th>
                <th class="text-end" data-type="num">Exams</th>
                <th class="text-end" data-type="num">Listed</th>
                <th class="text-end" data-type="num">In library</th>
                <th>Last inventory refresh</th>
                <th class="text-end" data-nosort data-novis data-nosearch data-export="false"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($providers as $p): $job = $activeJobs[$p['slug']] ?? null; $name = provider_name($p);
                $mayRefresh = $collector || ($first && (int) $p['exam_count'] === 0 && (int) $p['success_count'] === 0); ?>
                <tr>
                    <td class="stack-title" data-order="<?= e(strtolower($name)) ?>" data-export="<?= e($name) ?>"><a class="table-row-link" href="<?= e(url('/providers/' . rawurlencode($p['slug']))) ?>"><?= e($name) ?></a><?= $p['source'] === 'manual' ? ' ' . chip('manual', 'neutral') : '' ?></td>
                    <td data-label="Identifier"><code><?= e($p['slug']) ?></code></td>
                    <td class="text-end" data-label="Exams" data-order="<?= $p['refresh_at'] ? (int) $p['exam_count'] : -1 ?>"><?= $p['refresh_at'] ? number_format((int) $p['exam_count']) : '<span class="text-muted">n/a</span>' ?></td>
                    <td class="text-end" data-label="Listed" data-order="<?= (int) ($p['listed_exam_count'] ?? -1) ?>"><?= $p['listed_exam_count'] !== null ? number_format((int) $p['listed_exam_count']) : '<span class="text-muted">n/a</span>' ?></td>
                    <td class="text-end" data-label="In library" data-order="<?= (int) $p['cached_count'] ?>"><?= (int) $p['cached_count'] > 0 ? chip(number_format((int) $p['cached_count']), 'primary') : '<span class="text-muted">0</span>' ?></td>
                    <td data-label="Last refresh" data-order="<?= e($job ? '9999' : (string) ($p['refresh_at'] ?? '')) ?>" data-export="<?= e($p['refresh_at'] ? $p['refresh_status'] . ' ' . fmt_time($p['refresh_at']) : 'never') ?>"><span>
                        <?php if ($job): ?><?= status_badge($job['status']) ?> <span class="cell-sub">refresh in progress</span>
                        <?php elseif ($p['refresh_at']): ?><?= status_badge($p['refresh_status']) ?><span class="cell-sub"><?= e(fmt_time($p['refresh_at'])) ?></span>
                            <?php if ($p['refresh_error']): ?><a class="cell-sub text-danger" href="<?= e($p['refresh_job_uuid'] ? url('/jobs/' . $p['refresh_job_uuid']) : url('/providers/' . rawurlencode($p['slug']))) ?>" title="<?= e(error_summary((string) $p['refresh_error'])) ?>"><?= icon('exclamation-circle') ?> <?= e(substr_count(trim((string) $p['refresh_error']), "\n") + 1) ?> error<?= substr_count(trim((string) $p['refresh_error']), "\n") === 0 ? '' : 's' ?></a><?php endif; ?>
                        <?php else: ?><span class="text-muted">never</span><?php endif; ?>
                    </span></td>
                    <td class="text-end stack-actions">
                        <?php if ($job): ?>
                            <?= \App\Core\View::current()->partial('partials/job_chip', ['job' => $job]) ?>
                        <?php elseif ($mayRefresh): ?>
                        <form method="post" action="<?= e(url('/providers/' . rawurlencode($p['slug']) . '/refresh')) ?>" data-job-form><?= csrf_field() ?>
                            <input type="hidden" name="back" value="/providers">
                            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= icon('arrow-clockwise') ?><?= $collector ? 'Refresh inventory' : 'Load exam list' ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($collector): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('plus-circle') ?>Add a provider by identifier</span></div>
    <div class="card-body">
        <p class="small text-muted">Use the identifier from ExamTopics addresses, for example <code>huawei</code> in <code>examtopics.com/exams/huawei/</code>. Its first inventory refresh confirms that it exists.</p>
        <form method="post" action="<?= e(url('/providers/add')) ?>" class="toolbar" data-once><?= csrf_field() ?>
            <label class="visually-hidden" for="new-slug">Provider identifier</label>
            <input class="form-control flex-grow-0" id="new-slug" name="slug" placeholder="provider-identifier" pattern="[a-z0-9](?:[a-z0-9\-]{0,62}[a-z0-9])?" required>
            <button class="btn btn-primary" type="submit"><?= icon('plus-lg') ?>Add &amp; refresh</button>
        </form>
    </div>
</div>
<?php endif; ?>
