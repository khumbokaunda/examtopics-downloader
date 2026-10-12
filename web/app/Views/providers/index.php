<?php /** @var list<array> $providers @var array<string,array> $activeJobs */ ?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Providers &amp; inventory</h1>
        <p class="page-subtitle">Providers come from ExamTopics itself. <strong>Listed</strong> is how many exams ExamTopics shows for a provider; <strong>Exams</strong> is how many have discussions you can collect, found by refreshing its inventory.</p>
    </div>
    <div class="page-actions">
        <form method="post" action="<?= e(url('/providers/discover')) ?>" data-once><?= csrf_field() ?>
            <button class="btn btn-outline-primary" type="submit" <?= isset($activeJobs['*']) ? 'disabled' : '' ?>>
                <?= icon('radar') ?><?= isset($activeJobs['*']) ? 'Discovering…' : 'Discover providers' ?></button>
        </form>
    </div>
</header>

<div class="card mb-4">
    <?php if ($providers === []): ?>
        <div class="card-body"><?= empty_state('buildings', 'No providers yet', 'Use <strong>Discover providers</strong>, or add one by its identifier below (for example <code>huawei</code>).') ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="providers" data-order='[[0,"asc"]]'>
            <thead><tr>
                <th>Provider</th>
                <th class="text-end" data-type="num">Exams</th>
                <th class="text-end" data-type="num">Listed</th>
                <th class="text-end" data-type="num">In library</th>
                <th>Last inventory refresh</th>
                <th class="text-end" data-nosort data-novis data-nosearch data-export="false"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($providers as $p): $job = $activeJobs[$p['slug']] ?? null; $name = provider_name($p); ?>
                <tr>
                    <td class="stack-title" data-order="<?= e(strtolower($name)) ?>" data-export="<?= e($name) ?>"><a class="table-row-link" href="<?= e(url('/providers/' . rawurlencode($p['slug']))) ?>"><?= e($name) ?></a>
                        <div class="small text-muted"><code><?= e($p['slug']) ?></code><?= $p['source'] === 'manual' ? ' · added manually' : '' ?></div></td>
                    <td class="text-end" data-label="Exams" data-order="<?= $p['refresh_at'] ? (int) $p['exam_count'] : -1 ?>"><?= $p['refresh_at'] ? number_format((int) $p['exam_count']) : '<span class="text-muted">n/a</span>' ?></td>
                    <td class="text-end" data-label="Listed" data-order="<?= (int) ($p['listed_exam_count'] ?? -1) ?>"><?= $p['listed_exam_count'] !== null ? number_format((int) $p['listed_exam_count']) : '<span class="text-muted">n/a</span>' ?></td>
                    <td class="text-end" data-label="In library" data-order="<?= (int) $p['cached_count'] ?>"><?= (int) $p['cached_count'] > 0 ? chip(number_format((int) $p['cached_count']), 'primary') : '<span class="text-muted">0</span>' ?></td>
                    <td class="cell-wrap" data-label="Last refresh" data-order="<?= e($job ? '9999' : (string) ($p['refresh_at'] ?? '')) ?>"><div>
                        <?php if ($job): ?><?= status_badge($job['status']) ?> <span class="small text-muted">refresh in progress</span>
                        <?php elseif ($p['refresh_at']): ?><?= status_badge($p['refresh_status']) ?> <span class="small text-muted"><?= e(fmt_time($p['refresh_at'])) ?></span>
                            <?php if ($p['refresh_error']): ?><details class="error-details"><summary class="small text-danger"><?= e(error_summary((string) $p['refresh_error'])) ?></summary><pre class="log-box mt-1"><?= e($p['refresh_error']) ?></pre></details><?php endif; ?>
                        <?php else: ?><span class="text-muted small">never</span><?php endif; ?>
                    </div></td>
                    <td class="text-end stack-actions">
                        <form method="post" action="<?= e(url('/providers/' . rawurlencode($p['slug']) . '/refresh')) ?>" data-once><?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-secondary" type="submit" <?= $job ? 'disabled' : '' ?>><?= icon('arrow-clockwise') ?>Refresh inventory</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

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
