<?php
/** @var array $institution @var array $membership @var string $tab @var array $bank @var array $question @var list<array> $versions @var bool $canWrite @var bool $publisher @var array|null $shown */
use App\Services\QuestionBanks;
$bankPath = '/institutions/' . $institution['uuid'] . '/banks/' . $bank['uuid'];
$path = $bankPath . '/questions/' . $question['uuid'];
$q = $question;
$v = $shown ?? $q;
$status = (string) $q['status'];
$tone = ['draft' => 'neutral', 'review' => 'warning', 'approved' => 'info', 'published' => 'success', 'archived' => 'neutral'];
$choices = (array) json_decode((string) $v['choices'], true);
$right = array_map(static fn(array $c): string => $v['type'] === 'true_false' ? (string) $c['text'] : (string) $c['key'], array_filter($choices, static fn(array $c): bool => !empty($c['correct'])));
$writer = $canWrite && $bank['status'] === 'active';
$actions = array_filter([
    'submit' => [$writer && $status === 'draft', 'Send for review', 'send', 'btn-primary'],
    'publish' => [$publisher && in_array($status, ['review', 'approved'], true) && $bank['status'] === 'active', $status === 'review' ? 'Approve and publish' : 'Publish', 'patch-check', 'btn-success'],
    'approve' => [$publisher && $status === 'review' && $bank['status'] === 'active', 'Approve', 'check2-circle', 'btn-outline-success'],
    'archive' => [($canWrite || $publisher) && $status !== 'archived', 'Archive', 'archive', 'btn-outline-secondary'],
    'restore' => [($canWrite || $publisher) && $status === 'archived', 'Restore', 'arrow-counterclockwise', 'btn-outline-secondary'],
], static fn(array $a): bool => $a[0]);
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Question banks' => '/institutions/' . $institution['uuid'] . '/banks', $bank['name'] => $bankPath, 'Question' => null]]) ?>
<?php if ($shown !== null && (int) $shown['version'] !== (int) $q['latest_version']): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status"><?= icon('clock-history') ?><div class="alert-body flex-grow-1">An earlier version: <?= (int) $shown['version'] ?> of <?= (int) $q['latest_version'] ?><?= (int) $shown['version'] === (int) $q['published_version'] ? ', the one assessments use' : '' ?>. It cannot be changed.</div>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($path)) ?>">See the latest</a></div>
<?php endif; ?>
<?php if ($q['review_note'] !== null && $status === 'draft'): ?>
    <div class="alert alert-warning" role="status"><?= icon('arrow-return-left') ?><div class="alert-body"><strong>Sent back for changes:</strong> <?= e($q['review_note']) ?></div></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('eye') ?>As a candidate sees it</span>
                <?php if ($writer && $shown === null && $status !== 'archived'): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url($path . '/edit')) ?>"><?= icon('pencil') ?>Edit</a><?php endif; ?></div>
            <div class="card-body"><?= \App\Core\View::current()->partial('institutions/_question_body', ['v' => $v, 'key' => false]) ?></div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('key') ?>Answer and marks</span><span class="small text-muted">Never shown to candidates while they answer</span></div>
            <div class="card-body">
                <p class="mb-2"><strong>Right answer:</strong> <?= e(implode(', ', $right)) ?></p>
                <p class="mb-2 small text-muted"><?= e(rtrim(rtrim((string) $v['marks'], '0'), '.')) ?> marks<?= $v['type'] === 'multiple' ? ($v['partial_credit'] ? ', part marks for a partly right answer' : ', all or nothing') : '' ?>
                    · <?= e(QuestionBanks::TYPES[$v['type']] ?? $v['type']) ?> · <?= e(QuestionBanks::DIFFICULTIES[$v['difficulty']] ?? $v['difficulty']) ?><?= $v['topic_name'] !== null ? ' · ' . e($v['topic_name']) : '' ?></p>
                <?php if ($v['explanation'] !== null): ?><p class="mb-1 small"><strong>Explanation.</strong> <?= nl2br(e((string) $v['explanation'])) ?></p><?php endif; ?>
                <?php if ($v['reference'] !== null): ?><p class="mb-0 small"><strong>Reference.</strong> <?= e((string) $v['reference']) ?></p><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('signpost-split') ?>Status</span><?= chip(QuestionBanks::STATUSES[$status] ?? $status, $tone[$status] ?? 'neutral') ?></div>
            <div class="card-body">
                <p class="small mb-3"><?= $q['published_version'] !== null ? 'Assessments use version ' . (int) $q['published_version'] . '.' : 'Not published yet, so no assessment can use it.' ?>
                    <?= $status === 'review' ? 'Waiting for someone who approves questions.' : '' ?></p>
                <?php if ($actions !== []): ?>
                <form method="post" action="<?= e(url($path . '/status')) ?>" class="d-flex flex-wrap gap-2" data-once><?= csrf_field() ?>
                    <?php foreach ($actions as $key => [, $label, $iconName, $class]): ?>
                        <button class="btn btn-sm <?= e($class) ?>" type="submit" name="action" value="<?= e($key) ?>"><?= icon($iconName) ?><?= e($label) ?></button>
                    <?php endforeach; ?>
                </form>
                <?php endif; ?>
                <?php if ($publisher && in_array($status, ['review', 'approved'], true)): ?>
                <form method="post" action="<?= e(url($path . '/status')) ?>" class="mt-3" data-once><?= csrf_field() ?>
                    <label class="form-label small" for="note">Send back for changes</label>
                    <textarea class="form-control form-control-sm mb-2" id="note" name="note" rows="2" maxlength="1000" required placeholder="What needs changing"></textarea>
                    <button class="btn btn-sm btn-outline-warning" type="submit" name="action" value="return"><?= icon('arrow-return-left') ?>Send back</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('clock-history') ?>Versions</span></div>
            <ul class="list-rows">
                <?php foreach ($versions as $ver): $n = (int) $ver['version']; ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title fw-normal"><a href="<?= e(url($n === (int) $q['latest_version'] ? $path : $path . '/versions/' . $n)) ?>">Version <?= $n ?></a>
                            <?= $n === (int) $q['published_version'] ? chip('In use', 'success') : '' ?><?= $n === (int) $q['latest_version'] && $n !== (int) $q['published_version'] ? chip('Latest', 'neutral') : '' ?></div>
                        <div class="row-meta"><?= e(fmt_time((string) $ver['created_at'])) ?><?= $ver['author'] !== null ? ' · ' . e($ver['author']) : '' ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
