<?php
/** @var string $period @var array<string,mixed> $report @var int $limit */
use App\Controllers\SignInsController;
use App\Services\Access;

$hidden = csrf_field() . '<input type="hidden" name="period" value="' . e($period) . '">';
$open = array_values(array_filter($report['flagged'], static fn(array $f): bool => $f['cleared_at'] === null));
$private = array_values(array_filter($report['networks'], static fn(array $n): bool => $n['kind'] === 'private'));
$networkLabel = static fn(array $n): string => match ($n['kind']) {
    'cloudflare' => chip('Cloudflare, not a person', 'neutral', 'cloud'),
    'private' => chip('Private address, never blocked', 'neutral', 'house'),
    'shared' => chip('Shared, never blocked', 'neutral', 'people'),
    default => '',
};
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Sign-in attempts</h1>
        <p class="page-subtitle">Whether any attempt got in, which accounts and names were tried, and where from. An account locks after <?= (int) $limit ?> wrong passwords in a row; a network that fails too often is refused for a while.</p>
    </div>
    <div class="page-actions">
        <div class="btn-group btn-group-sm" role="group" aria-label="Period">
            <?php foreach (SignInsController::PERIODS as $key => [, $label]): ?>
                <a class="btn btn-outline-secondary <?= $period === $key ? 'active' : '' ?>" href="<?= e(url('/security/sign-ins', ['period' => $key])) ?>" <?= $period === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</header>

<div class="kpi-strip">
    <?php foreach ([
        ['Failed sign-ins', $report['failed'], 'shield-exclamation', $report['failed'] > 0 ? 'danger' : 'neutral'],
        ['Signed in', $report['succeeded'], 'box-arrow-in-right', 'success'],
        ['Names that do not exist', $report['unknown_names'], 'question-circle', 'neutral'],
        ['Accounts locked now', $report['locked'], 'lock', $report['locked'] > 0 ? 'warning' : 'neutral'],
        ['Networks blocked now', $report['blocked'], 'slash-circle', $report['blocked'] > 0 ? 'warning' : 'neutral'],
    ] as [$label, $value, $iconName, $tone]): ?>
        <div class="kpi" data-tone="<?= e($tone) ?>">
            <span class="kpi-icon"><?= icon($iconName) ?></span>
            <span class="kpi-text"><span class="kpi-value d-block"><?= number_format((int) $value) ?></span><span class="kpi-label d-block"><?= e($label) ?></span></span>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mb-4">
    <div class="card-header">
        <span class="card-title"><?= icon('shield-check') ?>Did any attempt get in?</span>
        <?php if ($open !== []): ?>
            <form method="post" action="<?= e(url('/security/sign-ins/flags/clear-all')) ?>" data-confirm="Every flagged sign-in in the last 30 days is marked as the account owner's. Each can be flagged again." data-confirm-title="Clear all flags?" data-confirm-button="Clear all"><?= $hidden ?>
                <button class="btn btn-sm btn-outline-secondary" type="submit">Clear all</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($report['flagged'] === []): ?>
        <div class="card-body"><?= empty_state('shield-check', 'Nothing looks suspicious', 'No sign-in came from a network that had been guessing at that account or at made-up names, and no account was signed into straight after being locked.', null, true) ?></div>
    <?php else: ?>
    <ul class="list-rows">
        <?php foreach ($report['flagged'] as $f): $cleared = $f['cleared_at'] !== null; ?>
            <li class="flex-wrap">
                <div class="row-main">
                    <span class="icon-bubble" data-tone="<?= $cleared ? 'neutral' : 'danger' ?>"><?= icon($cleared ? 'check2' : 'exclamation-triangle') ?></span>
                    <div class="row-text">
                        <div class="row-title"><?= e(Access::displayName(['display_name' => $f['display_name'], 'username' => $f['username']])) ?> signed in from <span class="mono"><?= e($f['ip']) ?></span></div>
                        <div class="row-meta"><?= e(fmt_time((string) $f['attempted_at'])) ?> · <?= e(ucfirst(implode('; ', $f['reasons']))) ?>.</div>
                        <?php if ($cleared): ?><div class="row-meta">Cleared <?= e(fmt_ago((string) $f['cleared_at'])) ?>.</div><?php endif; ?>
                    </div>
                </div>
                <form method="post" action="<?= e(url('/security/sign-ins/flags/' . (int) $f['id'] . ($cleared ? '/restore' : '/clear'))) ?>"><?= $hidden ?>
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $cleared ? 'Flag again' : 'It was them' ?></button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('person-lock') ?>Accounts tried</span></div>
            <?php if ($report['accounts'] === []): ?>
                <div class="card-body"><?= empty_state('person-check', 'No wrong passwords', 'No account had a wrong password typed for it in this period.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack" data-table="sign-in-accounts" data-order='[[1,"desc"]]'>
                    <thead><tr><th>Account</th><th data-type="num">Wrong passwords</th><th data-type="num" data-optional>Networks</th><th>Last</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['accounts'] as $a): $locked = $a['locked_until'] !== null && strtotime($a['locked_until'] . ' UTC') > time(); ?>
                        <tr>
                            <td class="stack-title"><?= e(Access::displayName($a)) ?> <span class="text-muted small"><?= e((string) $a['username']) ?></span></td>
                            <td data-label="Wrong passwords"><?= number_format((int) $a['failures']) ?></td>
                            <td data-label="Networks"><?= number_format((int) $a['networks']) ?></td>
                            <td class="small text-muted" data-label="Last" data-order="<?= e((string) ($a['last_at'] ?? '')) ?>"><?= $a['last_at'] !== null ? e(fmt_ago((string) $a['last_at'])) : '' ?></td>
                            <td data-label="Status">
                                <?php if ($locked): ?>
                                    <div class="d-flex flex-wrap align-items-center gap-2"><?= chip('Locked until ' . fmt_time((string) $a['locked_until']), 'warning', 'lock') ?>
                                        <form method="post" action="<?= e(url('/security/sign-ins/unlock')) ?>"><?= $hidden ?><input type="hidden" name="username" value="<?= e((string) $a['username']) ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Unlock</button>
                                        </form></div>
                                <?php elseif ((int) $a['failed_logins'] > 0): ?>
                                    <span class="small text-muted"><?= (int) $a['failed_logins'] ?> of <?= (int) $limit ?> before it locks</span>
                                <?php else: ?>
                                    <span class="small text-muted">Signed in since</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header"><span class="card-title"><?= icon('question-circle') ?>Names that do not exist</span></div>
            <?php if ($report['names'] === []): ?>
                <div class="card-body"><?= empty_state('check2-circle', 'None tried', 'Every failed attempt in this period named a real account.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack" data-table="sign-in-names" data-order='[[1,"desc"]]'>
                    <thead><tr><th>Name typed</th><th data-type="num">Attempts</th><th data-type="num" data-optional>Networks</th><th>Last</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['names'] as $n): ?>
                        <tr>
                            <td class="stack-title mono"><?= e((string) $n['username']) ?></td>
                            <td data-label="Attempts"><?= number_format((int) $n['attempts']) ?></td>
                            <td data-label="Networks"><?= number_format((int) $n['networks']) ?></td>
                            <td class="small text-muted" data-label="Last" data-order="<?= e((string) $n['last_at']) ?>"><?= e(fmt_ago((string) $n['last_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <div class="card-footer small text-muted">Anything typed as a name that may be a password is kept as its length only.</div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('globe') ?>Networks</span></div>
            <?php if ($report['networks'] === []): ?>
                <div class="card-body"><?= empty_state('globe', 'No sign-in attempts', 'Nothing was tried in this period.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack" data-table="sign-in-networks" data-order='[[1,"desc"]]'>
                    <thead><tr><th>Address</th><th data-type="num">Failed</th><th data-type="num">Signed in</th><th data-type="num" data-optional>Names tried</th><th>Last</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['networks'] as $n): $blocked = $n['blocked_until'] !== null; ?>
                        <tr>
                            <td class="stack-title mono"><?= e((string) $n['ip']) ?></td>
                            <td data-label="Failed"><?= number_format((int) $n['failures']) ?></td>
                            <td data-label="Signed in"><?= number_format((int) $n['successes']) ?></td>
                            <td data-label="Names tried"><?= number_format((int) $n['names']) ?></td>
                            <td class="small text-muted" data-label="Last" data-order="<?= e((string) ($n['last_at'] ?? '')) ?>"><?= $n['last_at'] !== null ? e(fmt_ago((string) $n['last_at'])) : '' ?></td>
                            <td data-label="Status">
                                <?php if ($blocked): ?>
                                    <div class="d-flex flex-wrap align-items-center gap-2"><?= chip('Blocked until ' . fmt_time((string) $n['blocked_until']), 'warning', 'slash-circle') ?>
                                        <form method="post" action="<?= e(url('/security/sign-ins/unblock')) ?>"><?= $hidden ?><input type="hidden" name="ip" value="<?= e((string) $n['ip']) ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Unblock</button>
                                        </form></div>
                                <?php else: ?>
                                    <?= $networkLabel($n) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if ($private !== [] && count($private) === count($report['networks'])): ?>
                <div class="card-footer small text-muted"><?= icon('info-circle') ?> Every attempt came from a private address. If the site sits behind a proxy, list it in <code>security.trusted_proxies</code> on the server, so each visitor's own address is recorded (docs/INSTALL.md).</div>
            <?php endif; ?>
        </div>
    </div>
</div>
