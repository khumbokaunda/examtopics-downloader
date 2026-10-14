<?php
/** @var bool $manager @var string $status @var list<array> $requests @var list<array> $providers @var array{provider:string,exam:string} $prefill */
use App\Services\ExamRequests;
$tone = ['open' => 'warning', 'collected' => 'success', 'declined' => 'danger', 'cancelled' => 'neutral'];
$canCollect = can('exams.collect');
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= $manager ? 'Exam requests' : 'My requests' ?></h1>
        <p class="page-subtitle"><?= $manager
            ? 'Exams people have asked for. Collecting an exam closes its requests and tells everyone who asked.'
            : 'Exams you have asked for. You are told in your inbox, and by email if you chose it, when one is collected.' ?></p>
    </div>
</header>

<div class="row g-4">
    <div class="col-xl-8">
        <form class="toolbar mb-3" method="get" action="<?= e(url('/requests')) ?>">
            <label class="visually-hidden" for="status">Status</label>
            <select class="form-select flex-grow-0 w-auto" id="status" name="status" data-autosubmit>
                <option value="">Every status</option>
                <?php foreach (ExamRequests::STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-outline-secondary" type="submit">Show</button>
        </form>
        <div class="card">
            <?php if ($requests === []): ?>
                <div class="card-body"><?= empty_state('inbox', $status === 'open' ? 'No open requests' : 'No requests', $manager ? 'Requests appear here when someone asks for an exam.' : 'Ask for an exam from a provider\'s page, or by its code here.', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($requests as $r): $label = strtoupper($r['exam_code']); $provider = provider_name(['name' => $r['provider_name'], 'slug' => $r['provider_slug']]); ?>
                    <li class="flex-wrap">
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="<?= e($tone[$r['status']] ?? 'neutral') ?>"><?= icon('journal-plus') ?></span>
                            <div class="row-text">
                                <div class="row-title"><?= e($label) ?> <span class="text-muted fw-normal">· <a href="<?= e(url('/providers/' . rawurlencode($r['provider_slug']))) ?>"><?= e($provider) ?></a></span></div>
                                <div class="row-meta">
                                    <?php if ($manager): ?><?= e($r['display_name'] ?: $r['username']) ?> · <?php endif; ?><?= e(fmt_time($r['created_at'])) ?>
                                    <?php if ($r['status'] === 'open' && (int) $r['open_for_exam'] > 1): ?> · <?= (int) $r['open_for_exam'] ?> people want this<?php endif; ?>
                                </div>
                                <?php if ($r['note'] !== null): ?><div class="row-meta">“<?= e($r['note']) ?>”</div><?php endif; ?>
                                <?php if ($r['response'] !== null): ?><div class="row-meta"><strong><?= $r['status'] === 'declined' ? 'Reason' : 'Note' ?>:</strong> <?= e($r['response']) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="row-side flex-wrap gap-2">
                            <?= chip(ExamRequests::STATUSES[$r['status']] ?? $r['status'], $tone[$r['status']] ?? 'neutral') ?>
                            <?php if ($r['status'] === 'collected'): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/library/' . rawurlencode($r['provider_slug']) . '/' . rawurlencode($r['exam_code']))) ?>"><?= icon('book') ?>Open</a>
                            <?php elseif ($r['status'] === 'open' && $manager): ?>
                                <?php if ($canCollect): ?><a class="btn btn-sm btn-primary" href="<?= e(url('/collect', ['provider' => $r['provider_slug'], 'exam' => $r['exam_code']])) ?>"><?= icon('cloud-arrow-down') ?>Collect</a><?php endif; ?>
                                <details class="request-answer">
                                    <summary class="btn btn-sm btn-outline-secondary">Answer</summary>
                                    <form method="post" action="<?= e(url('/requests/' . $r['uuid'] . '/answer')) ?>" class="request-answer-body" data-once><?= csrf_field() ?>
                                        <label class="form-label small" for="resp-<?= (int) $r['id'] ?>">Message to <?= e($r['display_name'] ?: $r['username']) ?> (optional)</label>
                                        <textarea class="form-control form-control-sm mb-2" id="resp-<?= (int) $r['id'] ?>" name="response" rows="2" maxlength="500"></textarea>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-sm btn-success" type="submit" name="status" value="collected"><?= icon('check2') ?>It is in the library</button>
                                            <button class="btn btn-sm btn-outline-danger" type="submit" name="status" value="declined"><?= icon('x') ?>Decline</button>
                                        </div>
                                    </form>
                                </details>
                            <?php endif; ?>
                            <?php if ($r['status'] === 'open' && (int) $r['user_id'] === (int) \App\Core\View::current()->user()['id']): ?>
                                <form method="post" action="<?= e(url('/requests/' . $r['uuid'] . '/cancel')) ?>" data-confirm="You will not be told when it is collected." data-confirm-title="Cancel this request?" data-confirm-button="Cancel request"><?= csrf_field() ?>
                                    <button class="btn btn-sm btn-link btn-link-danger" type="submit">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card" id="new">
            <div class="card-header"><span class="card-title"><?= icon('plus-circle') ?>Request an exam</span></div>
            <div class="card-body">
                <p class="small text-muted">For an exam that is not in the library. The quickest way is the <strong>Request</strong> button on a provider's page; use this when the exam is not listed there.</p>
                <form method="post" action="<?= e(url('/requests')) ?>" data-once><?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="provider">Provider</label>
                        <select class="form-select" id="provider" name="provider" required>
                            <option value="">Choose…</option>
                            <?php foreach ($providers as $p): ?><option value="<?= e($p['slug']) ?>" <?= $prefill['provider'] === $p['slug'] ? 'selected' : '' ?>><?= e(provider_name($p)) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label" for="exam">Exam code</label><input class="form-control" id="exam" name="exam" value="<?= e($prefill['exam']) ?>" placeholder="h12-811" pattern="[A-Za-z0-9_\-]{1,128}" required><div class="form-text">As in the ExamTopics address, for example <code>h12-811</code>.</div></div>
                    <div class="mb-3"><label class="form-label" for="note">Note (optional)</label><textarea class="form-control" id="note" name="note" rows="2" maxlength="500" placeholder="Why you need it, or by when"></textarea></div>
                    <button class="btn btn-primary" type="submit"><?= icon('send') ?>Request</button>
                </form>
            </div>
        </div>
    </div>
</div>
