<?php
/** @var array $institution @var array $membership @var string $tab @var array $bank @var list<array> $topics @var list<array> $questions @var array $filters
 *  @var bool $canWrite @var bool $manage @var list<array> $editors @var list<array> $writers @var list<array> $programmes */
use App\Services\Access;
use App\Services\QuestionBanks;
$base = '/institutions/' . $institution['uuid'] . '/banks/' . $bank['uuid'];
$tone = ['draft' => 'neutral', 'review' => 'warning', 'approved' => 'info', 'published' => 'success', 'archived' => 'neutral'];
$editing = array_flip(array_map(static fn(array $p): string => (string) $p['uuid'], $editors));
$filtered = $filters['status'] !== '' || $filters['difficulty'] !== '' || $filters['type'] !== '' || $filters['q'] !== '' || $filters['topic_uuid'] !== '';
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Question banks' => '/institutions/' . $institution['uuid'] . '/banks', $bank['name'] => null]]) ?>
<?php if ($bank['status'] === 'archived'): ?>
    <div class="alert alert-info" role="status"><?= icon('archive') ?><div class="alert-body">Archived. Its questions are kept; nothing new is written in it.</div></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-xl-8">
        <form class="toolbar mb-3" method="get" action="<?= e(url($base)) ?>">
            <div class="search-field"><?= icon('search') ?><label class="visually-hidden" for="bq">Search the questions</label>
                <input class="form-control" id="bq" name="q" value="<?= e($filters['q']) ?>" placeholder="Search the questions"></div>
            <select class="form-select" name="status" aria-label="Status" data-autosubmit><option value="">Not archived</option>
                <?php foreach (QuestionBanks::STATUSES as $k => $v): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
            <select class="form-select" name="topic" aria-label="Topic" data-autosubmit><option value="">Every topic</option>
                <?php foreach ($topics as $t): ?><option value="<?= e($t['uuid']) ?>" <?= $filters['topic_uuid'] === $t['uuid'] ? 'selected' : '' ?>><?= $t['depth'] ? '&nbsp;&nbsp;' : '' ?><?= e($t['name']) ?></option><?php endforeach; ?></select>
            <select class="form-select" name="difficulty" aria-label="Difficulty" data-autosubmit><option value="">Any difficulty</option>
                <?php foreach (QuestionBanks::DIFFICULTIES as $k => $v): ?><option value="<?= e($k) ?>" <?= $filters['difficulty'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
            <select class="form-select" name="type" aria-label="Kind" data-autosubmit><option value="">Any kind</option>
                <?php foreach (QuestionBanks::TYPES as $k => $v): ?><option value="<?= e($k) ?>" <?= $filters['type'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
            <button class="btn btn-outline-secondary" type="submit">Show</button>
            <?php if ($filtered): ?><a class="btn btn-link" href="<?= e(url($base)) ?>">Clear</a><?php endif; ?>
        </form>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('list-check') ?><?= count($questions) ?> <?= count($questions) === 1 ? 'question' : 'questions' ?></span>
                <?php if ($canWrite && $bank['status'] === 'active'): ?><a class="btn btn-sm btn-primary" href="<?= e(url($base . '/questions/new')) ?>"><?= icon('plus') ?>New question</a><?php endif; ?></div>
            <?php if ($questions === []): ?>
                <div class="card-body"><?= empty_state('list-check', $filtered ? 'No questions match' : 'No questions yet', $filtered ? 'Change or clear the filters.' : ($canWrite ? 'Write the first one.' : ''), null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($questions as $n => $q): ?>
                    <li class="request-row">
                        <div class="row-main"><div class="row-text">
                            <div class="row-title fw-normal"><a href="<?= e(url($base . '/questions/' . $q['uuid'])) ?>"><?= e(QuestionBanks::excerpt((string) $q['text'], 140)) ?></a></div>
                            <div class="row-meta"><span class="chip-group"><?= chip(QuestionBanks::STATUSES[$q['status']] ?? $q['status'], $tone[$q['status']] ?? 'neutral') ?>
                                <?php if ($q['published_version'] !== null && (int) $q['published_version'] !== (int) $q['latest_version'] && $q['status'] !== 'archived'): ?><?= chip('v' . (int) $q['published_version'] . ' in use', 'success') ?><?php endif; ?></span>
                                <?= e(QuestionBanks::TYPES[$q['type']] ?? $q['type']) ?> · <?= e(QuestionBanks::DIFFICULTIES[$q['difficulty']] ?? $q['difficulty']) ?> · <?= e(rtrim(rtrim((string) $q['marks'], '0'), '.')) ?> marks<?= $q['topic_name'] !== null ? ' · ' . e($q['topic_name']) : '' ?> · v<?= (int) $q['latest_version'] ?></div>
                        </div></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('tags') ?>Topics</span></div>
            <?php if ($topics !== []): ?>
            <ul class="list-rows">
                <?php foreach ($topics as $t): ?>
                    <li class="request-row">
                        <div class="row-main"><div class="row-text<?= $t['depth'] ? ' is-subtopic' : '' ?>">
                            <div class="row-title fw-normal"><a href="<?= e(url($base, ['topic' => $t['uuid']])) ?>"><?= e($t['name']) ?></a> <span class="text-muted small">· <?= (int) $t['question_count'] ?></span></div>
                        </div></div>
                        <?php if ($manage): ?>
                        <div class="request-actions">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle-panel="topic-<?= e($t['uuid']) ?>" aria-label="Rename <?= e($t['name']) ?>"><?= icon('pencil') ?></button>
                            <form method="post" action="<?= e(url($base . '/topics/' . $t['uuid'] . '/delete')) ?>" data-confirm="Remove the topic <?= e($t['name']) ?>?" data-confirm-button="Remove"><?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Remove <?= e($t['name']) ?>"><?= icon('trash3') ?></button></form>
                        </div>
                        <form method="post" action="<?= e(url($base . '/topics/' . $t['uuid'])) ?>" class="request-answer-body" id="topic-<?= e($t['uuid']) ?>" hidden data-once><?= csrf_field() ?>
                            <div class="toolbar"><label class="visually-hidden" for="tname-<?= e($t['uuid']) ?>">Name</label><input class="form-control form-control-sm" id="tname-<?= e($t['uuid']) ?>" name="name" value="<?= e($t['name']) ?>" maxlength="120" required>
                                <button class="btn btn-sm btn-primary" type="submit"><?= icon('check2') ?>Save</button></div>
                        </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($manage && $bank['status'] === 'active'): ?>
            <div class="card-body">
                <form method="post" action="<?= e(url($base . '/topics')) ?>" data-once><?= csrf_field() ?>
                    <label class="form-label small" for="topic-new">New topic</label>
                    <input class="form-control form-control-sm mb-2" id="topic-new" name="name" maxlength="120" required placeholder="Routing and switching">
                    <div class="toolbar"><label class="visually-hidden" for="topic-parent">Under</label>
                        <select class="form-select form-select-sm" id="topic-parent" name="parent"><option value="">A topic of its own</option>
                            <?php foreach ($topics as $t): if ($t['depth'] === 0): ?><option value="<?= e($t['uuid']) ?>">A subtopic of <?= e($t['name']) ?></option><?php endif; endforeach; ?></select>
                        <button class="btn btn-sm btn-outline-primary" type="submit"><?= icon('plus') ?>Add</button></div>
                </form>
            </div>
            <?php elseif ($topics === []): ?>
                <div class="card-body text-muted small">No topics yet.</div>
            <?php endif; ?>
        </div>
        <?php if ($manage): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('people') ?>Who may write here</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base . '/editors')) ?>" data-once><?= csrf_field() ?>
                    <p class="small text-muted">Besides its creator, and anyone who can edit every bank.</p>
                    <?php if ($writers === []): ?><p class="small text-muted mb-0">Nobody else in the institution writes questions yet.</p><?php endif; ?>
                    <?php foreach ($writers as $p): ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="ed-<?= e($p['uuid']) ?>" name="editors[]" value="<?= e($p['uuid']) ?>" <?= isset($editing[$p['uuid']]) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ed-<?= e($p['uuid']) ?>"><?= e(Access::displayName($p)) ?> <span class="text-muted">· <?= e(\App\Services\InstitutionAccess::roleName((string) $p['role'])) ?></span></label></div>
                    <?php endforeach; ?>
                    <?php if ($writers !== []): ?><button class="btn btn-sm btn-primary mt-3" type="submit"><?= icon('check2') ?>Save</button><?php endif; ?>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('gear') ?>The bank</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base)) ?>" data-once><?= csrf_field() ?>
                    <?= \App\Core\View::current()->partial('institutions/_bank_fields', ['b' => $bank, 'programmes' => $programmes]) ?>
                    <button class="btn btn-sm btn-primary" type="submit"><?= icon('check2') ?>Save</button>
                </form>
                <hr>
                <form method="post" action="<?= e(url($base . '/archive')) ?>" data-once><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= icon($bank['status'] === 'active' ? 'archive' : 'arrow-counterclockwise') ?><?= $bank['status'] === 'active' ? 'Archive the bank' : 'Restore the bank' ?></button>
                </form>
            </div>
        </div>
        <?php elseif ($editors !== []): ?>
        <div class="card"><div class="card-header"><span class="card-title"><?= icon('people') ?>Who may write here</span></div>
            <div class="card-body small"><?= e(implode(', ', array_map(static fn(array $p): string => Access::displayName($p), $editors))) ?>, and its creator.</div></div>
        <?php endif; ?>
    </div>
</div>
