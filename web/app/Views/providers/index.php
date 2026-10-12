<?php /** @var list<array> $providers @var array<string,array> $activeJobs */ ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">Providers &amp; Inventory</h1>
        <div class="text-muted small">Providers come from ExamTopics itself; inventories list the exams that have discussions, with question counts.</div>
    </div>
    <form method="post" action="<?= e(url('/providers/discover')) ?>" data-once><?= csrf_field() ?>
        <button class="btn btn-outline-primary" type="submit" <?= isset($activeJobs['*']) ? 'disabled' : '' ?>>
            <?= isset($activeJobs['*']) ? 'Discovering…' : 'Discover providers' ?></button>
    </form>
</div>

<div class="card shadow-sm mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Provider</th><th class="text-end">Exams</th><th class="text-end">In library</th><th>Last inventory refresh</th><th></th></tr></thead>
            <tbody>
            <?php if ($providers === []): ?>
                <tr><td colspan="5" class="text-muted p-4">No providers yet. Use <strong>Discover providers</strong>, or add one by its identifier below (for example <code>huawei</code>).</td></tr>
            <?php endif; ?>
            <?php foreach ($providers as $p): $job = $activeJobs[$p['slug']] ?? null; ?>
                <tr>
                    <td><a class="fw-semibold" href="<?= e(url('/providers/' . rawurlencode($p['slug']))) ?>"><?= e($p['name'] ?: $p['slug']) ?></a>
                        <div class="small text-muted"><code><?= e($p['slug']) ?></code><?= $p['source'] === 'manual' ? ' · added manually' : '' ?></div></td>
                    <td class="text-end"><?= (int) $p['exam_count'] ?></td>
                    <td class="text-end"><?= (int) $p['cached_count'] ?></td>
                    <td>
                        <?php if ($job): ?><?= status_badge($job['status']) ?> <span class="small">refresh in progress</span>
                        <?php elseif ($p['refresh_at']): ?><?= status_badge($p['refresh_status']) ?> <span class="small text-muted"><?= e(fmt_time($p['refresh_at'])) ?></span>
                            <?php if ($p['refresh_error']): ?><div class="small text-danger text-truncate-2"><?= e($p['refresh_error']) ?></div><?php endif; ?>
                        <?php else: ?><span class="text-muted small">never</span><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <form method="post" action="<?= e(url('/providers/' . rawurlencode($p['slug']) . '/refresh')) ?>" data-once><?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-secondary" type="submit" <?= $job ? 'disabled' : '' ?>>Refresh inventory</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h6">Add a provider by identifier</h2>
        <p class="small text-muted mb-2">Use the slug from ExamTopics URLs, e.g. <code>huawei</code> in <code>examtopics.com/exams/huawei/</code>. Its first inventory refresh confirms it exists.</p>
        <form method="post" action="<?= e(url('/providers/add')) ?>" class="row g-2" data-once><?= csrf_field() ?>
            <div class="col-sm-6 col-md-4"><input class="form-control" name="slug" placeholder="provider-slug" pattern="[a-z0-9](?:[a-z0-9\-]{0,62}[a-z0-9])?" required></div>
            <div class="col-auto"><button class="btn btn-primary" type="submit">Add &amp; refresh</button></div>
        </form>
    </div>
</div>
