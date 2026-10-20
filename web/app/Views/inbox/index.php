<?php
/** @var list<array> $items @var string $kind @var int $page @var int $pages @var int $total @var int $unread */
use App\Services\Notifier;
$icons = ['exam_request' => 'inbox', 'request_update' => 'journal-check', 'account' => 'person-plus', 'security' => 'shield-lock'];
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Inbox</h1>
        <p class="page-subtitle"><?= $unread > 0 ? $unread . ' unread' : 'Nothing unread' ?><?= $total > 0 ? ' · ' . $total . ' in all' : '' ?></p>
    </div>
    <?php if ($unread > 0): ?>
    <div class="page-actions">
        <form method="post" action="<?= e(url('/inbox/read')) ?>" data-once><?= csrf_field() ?>
            <button class="btn btn-outline-secondary" type="submit"><?= icon('check2-all') ?>Mark all as read</button>
        </form>
    </div>
    <?php endif; ?>
</header>

<form class="toolbar mb-3" method="get" action="<?= e(url('/inbox')) ?>">
    <label class="visually-hidden" for="kind">Show</label>
    <select class="form-select flex-grow-0 w-auto" id="kind" name="kind" data-autosubmit>
        <option value="">Everything</option>
        <?php foreach (Notifier::KINDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-outline-secondary" type="submit">Show</button>
</form>

<div class="card">
    <?php if ($items === []): ?>
        <div class="card-body"><?= empty_state('bell', 'Nothing here', 'Exam requests, answers to your requests and new accounts show here.', null, true) ?></div>
    <?php else: ?>
    <ul class="list-rows">
        <?php foreach ($items as $n): $isUnread = $n['read_at'] === null; ?>
            <li class="<?= $isUnread ? 'is-unread' : '' ?>">
                <div class="row-main">
                    <span class="icon-bubble" data-tone="<?= $isUnread ? 'primary' : 'neutral' ?>"><?= icon($icons[$n['kind']] ?? 'bell') ?></span>
                    <div class="row-text">
                        <a class="row-title" href="<?= e(url('/inbox/' . (int) $n['id'])) ?>"><?= e($n['title']) ?><?= (int) $n['count'] > 1 ? ' <span class="chip chip--primary">' . (int) $n['count'] . '</span>' : '' ?></a>
                        <?php if ($n['body'] !== null && $n['body'] !== ''): ?><div class="row-meta text-clamp-2"><?= e($n['body']) ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="row-side"><?= $isUnread ? chip('new', 'primary') : '' ?><span class="row-time"><?= e(fmt_ago($n['updated_at'])) ?></span></div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-3" aria-label="Pages"><ul class="pagination">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
        <li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e(url('/inbox', ['kind' => $kind, 'page' => (string) $p])) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= $p ?></a></li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>
