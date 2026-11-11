<?php /** @var list<array> $plans @var array|null $editing @var bool $siteOn @var string $currency */
use App\Services\Plans;
$limit = static fn($v): string => $v === null ? 'No limit' : number_format((int) $v);
$f = $editing ?? ['name' => '', 'price' => '', 'amount_monthly' => null, 'amount_yearly' => null, 'summary' => '', 'features' => '', 'max_students' => null, 'max_staff' => null,
    'public' => 1, 'featured' => 0, 'includes_previous' => 0];
$plain = static fn($v): string => $v === null ? '' : rtrim(rtrim((string) $v, '0'), '.');
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Plans</h1>
        <p class="page-subtitle">What an institution gets. An institution with no plan has no limits.</p>
    </div>
    <?php if ($siteOn): ?><div class="page-actions"><a class="btn btn-outline-secondary" href="<?= e(url('/pricing')) ?>" target="_blank" rel="noopener"><?= icon('box-arrow-up-right') ?>Pricing page</a></div><?php endif; ?>
</header>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <?php if ($plans === []): ?>
                <div class="card-body"><?= empty_state('tags', 'No plans yet', 'Add one to limit institutions and to show prices.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead><tr><th>Plan</th><th>Price</th><th class="text-end">Students</th><th class="text-end">Staff</th><th class="text-end">Institutions</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($plans as $p): ?>
                        <tr class="<?= $p['archived_at'] !== null ? 'text-muted' : '' ?>">
                            <td class="stack-title"><?= e($p['name']) ?>
                                <?php if ((int) $p['featured']): ?><?= chip('Most popular', 'primary') ?><?php endif; ?>
                                <?php if ($p['archived_at'] !== null): ?><?= chip('Not offered', 'neutral') ?><?php elseif (!(int) $p['public']): ?><?= chip('Not on the pricing page', 'neutral') ?><?php endif; ?>
                                <?php if ($p['summary'] !== null): ?><div class="small text-muted"><?= e($p['summary']) ?></div><?php endif; ?></td>
                            <?php $d = Plans::display($p, $currency); ?>
                            <td data-label="Price"><?= $d['monthly'] !== null ? e($d['monthly']) . ' a month' . ($d['yearly'] !== null ? '<div class="small text-muted">' . e($d['yearly']) . ' a year</div>' : '') : e($d['text']) ?></td>
                            <td class="text-end" data-label="Students"><?= e($limit($p['max_students'])) ?></td>
                            <td class="text-end" data-label="Staff"><?= e($limit($p['max_staff'])) ?></td>
                            <td class="text-end" data-label="Institutions"><?= (int) $p['institution_count'] ?></td>
                            <td class="text-end stack-actions">
                                <a class="btn btn-sm btn-link" href="<?= e(url('/platform/plans', ['edit' => $p['uuid']])) ?>"><?= icon('pencil') ?>Edit</a>
                                <form class="d-inline" method="post" action="<?= e(url('/platform/plans/' . $p['uuid'] . '/archive')) ?>"><?= csrf_field() ?>
                                    <input type="hidden" name="archived" value="<?= $p['archived_at'] === null ? '1' : '0' ?>">
                                    <button class="btn btn-sm btn-link" type="submit"><?= $p['archived_at'] === null ? 'Stop offering' : 'Offer again' ?></button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <form class="card" method="post" action="<?= e(url($editing !== null ? '/platform/plans/' . $editing['uuid'] : '/platform/plans')) ?>"><?= csrf_field() ?>
            <div class="card-header"><span class="card-title"><?= icon($editing !== null ? 'pencil' : 'plus-lg') ?><?= $editing !== null ? 'Edit ' . e($editing['name']) : 'Add a plan' ?></span></div>
            <div class="card-body">
                <div class="mb-3"><label class="form-label" for="p-name">Name</label><input class="form-control" id="p-name" name="name" maxlength="80" required value="<?= e((string) $f['name']) ?>"></div>
                <div class="row g-3 mb-3">
                    <div class="col-6"><label class="form-label" for="p-monthly">A month (<?= e($currency) ?>)</label><input class="form-control" id="p-monthly" name="amount_monthly" inputmode="decimal" value="<?= e($plain($f['amount_monthly'])) ?>" placeholder="20,000"></div>
                    <div class="col-6"><label class="form-label" for="p-yearly">A year (<?= e($currency) ?>)</label><input class="form-control" id="p-yearly" name="amount_yearly" inputmode="decimal" value="<?= e($plain($f['amount_yearly'])) ?>" placeholder="Optional"></div>
                </div>
                <div class="mb-3"><label class="form-label" for="p-price">Price text without an amount</label><input class="form-control" id="p-price" name="price" maxlength="80" value="<?= e((string) $f['price']) ?>" placeholder="Custom">
                    <div class="form-text">Shown instead of a price when there is no monthly amount. A yearly amount shows the saving on the pricing page.</div></div>
                <div class="mb-3"><label class="form-label" for="p-summary">Summary</label><input class="form-control" id="p-summary" name="summary" maxlength="255" value="<?= e((string) $f['summary']) ?>"></div>
                <div class="mb-3"><label class="form-label" for="p-features">Features</label><textarea class="form-control" id="p-features" name="features" rows="6" placeholder="One per line"><?= e((string) $f['features']) ?></textarea>
                    <div class="form-text">One per line, up to 20.</div></div>
                <div class="row g-3 mb-3">
                    <div class="col-6"><label class="form-label" for="p-students">Students</label><input class="form-control" id="p-students" name="max_students" inputmode="numeric" pattern="[0-9]*" value="<?= e((string) $f['max_students']) ?>" placeholder="No limit"></div>
                    <div class="col-6"><label class="form-label" for="p-staff">Staff</label><input class="form-control" id="p-staff" name="max_staff" inputmode="numeric" pattern="[0-9]*" value="<?= e((string) $f['max_staff']) ?>" placeholder="No limit"></div>
                </div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="p-public" name="public" value="1"<?= (int) $f['public'] ? ' checked' : '' ?>>
                    <label class="form-check-label" for="p-public">Show on the pricing page</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="p-featured" name="featured" value="1"<?= (int) $f['featured'] ? ' checked' : '' ?>>
                    <label class="form-check-label" for="p-featured">Mark as most popular</label></div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="p-previous" name="includes_previous" value="1"<?= (int) $f['includes_previous'] ? ' checked' : '' ?>>
                    <label class="form-check-label" for="p-previous">Its features add to the plan before it</label></div>
                <div class="d-flex gap-2"><button class="btn btn-primary" type="submit"><?= icon('check2') ?><?= $editing !== null ? 'Save' : 'Add' ?></button>
                    <?php if ($editing !== null): ?><a class="btn btn-outline-secondary" href="<?= e(url('/platform/plans')) ?>">Cancel</a><?php endif; ?></div>
            </div>
        </form>
    </div>
</div>
