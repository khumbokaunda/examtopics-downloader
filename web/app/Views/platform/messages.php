<?php /** @var list<array> $messages @var string $status @var bool $siteOn */ ?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Messages</h1>
        <p class="page-subtitle">Sent from the public contact page.<?= $siteOn ? '' : ' The public site is off, so no new ones arrive.' ?></p>
    </div>
    <div class="page-actions">
        <nav class="segmented" aria-label="Status">
            <a class="<?= $status === 'new' ? 'is-active' : '' ?>" href="<?= e(url('/platform/messages')) ?>">New</a>
            <a class="<?= $status === 'answered' ? 'is-active' : '' ?>" href="<?= e(url('/platform/messages', ['status' => 'answered'])) ?>">Answered</a>
        </nav>
    </div>
</header>
<?php if ($messages === []): ?>
    <div class="card"><div class="card-body"><?= empty_state('chat-left-text', $status === 'new' ? 'No new messages' : 'None answered yet', 'Messages from the contact page show here.', null, true) ?></div></div>
<?php endif; ?>
<?php foreach ($messages as $m): ?>
    <div class="card mb-3">
        <div class="card-header"><span class="card-title"><?= e($m['name']) ?><?= $m['organisation'] !== null ? ', ' . e($m['organisation']) : '' ?></span>
            <span class="small text-muted"><?= e(fmt_time((string) $m['created_at'])) ?></span></div>
        <div class="card-body">
            <p class="small mb-2"><a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a><?= $m['phone'] !== null ? ' · ' . e($m['phone']) : '' ?>
                <?php if ($m['plan_name'] !== null): ?> · <?= chip('Asks about ' . $m['plan_name'], 'primary', 'tags') ?><?php endif; ?></p>
            <div class="text-pre"><?= e($m['body']) ?></div>
            <?php if ($m['status'] === 'new'): ?>
                <form class="mt-3" method="post" action="<?= e(url('/platform/messages/' . $m['uuid'] . '/answered')) ?>"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon('check2') ?>Mark as answered</button></form>
            <?php else: ?>
                <p class="small text-muted mt-3 mb-0">Answered by <?= e((string) ($m['handler_name'] ?? $m['handler_username'] ?? 'someone')) ?>, <?= e(fmt_time((string) $m['handled_at'])) ?>.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
