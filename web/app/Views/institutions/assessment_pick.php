<?php /** @var array $institution @var array $membership @var string $tab @var array $assessment @var list<array> $banks @var array|null $bank @var list<array> $questions @var list<array> $topics */
use App\Controllers\InstitutionAssessmentController as C;
use App\Services\QuestionBanks;
$a = $assessment;
$base = '/institutions/' . $institution['uuid'] . '/assessments/' . $a['uuid'];
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Assessments' => '/institutions/' . $institution['uuid'] . '/assessments', $a['title'] => $base, 'Add questions' => null]]) ?>
<?php if ($banks === []): ?>
    <div class="card"><div class="card-body"><?= empty_state('database', 'No question banks to choose from', 'Questions come from the institution\'s question banks. You see the banks you created, were added to, or can read.', null, true) ?></div></div>
<?php else: ?>
<form class="toolbar mb-3" method="get" action="<?= e(url($base . '/add')) ?>">
    <label class="form-label mb-0 me-1" for="pick-bank">Question bank</label>
    <select class="form-select" id="pick-bank" name="bank" data-autosubmit>
        <?php foreach ($banks as $b): ?><option value="<?= e($b['uuid']) ?>" <?= ($bank['uuid'] ?? '') === $b['uuid'] ? 'selected' : '' ?>><?= e($b['name']) ?> (<?= (int) $b['published_count'] ?> published)</option><?php endforeach; ?>
    </select>
    <button class="btn btn-outline-secondary" type="submit">Show</button>
</form>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('check2-square') ?>Choose questions one by one</span></div>
            <?php if ($questions === []): ?>
                <div class="card-body"><?= empty_state('list-check', 'No published questions to add', 'Only published questions can go in an assessment, and ones already in it are not listed.', null, true) ?></div>
            <?php else: ?>
            <form method="post" action="<?= e(url($base . '/questions')) ?>" data-once><?= csrf_field() ?>
                <input type="hidden" name="bank" value="<?= e($bank['uuid']) ?>">
                <ul class="list-rows">
                    <?php foreach ($questions as $q): ?>
                        <li class="request-row"><div class="row-main">
                            <input class="form-check-input mt-1" type="checkbox" id="pq-<?= e($q['uuid']) ?>" name="questions[]" value="<?= e($q['uuid']) ?>">
                            <label class="row-text" for="pq-<?= e($q['uuid']) ?>">
                                <span class="row-title fw-normal d-block"><?= e(QuestionBanks::excerpt((string) $q['text'], 160)) ?></span>
                                <span class="row-meta d-block"><?= e(QuestionBanks::TYPES[$q['type']] ?? $q['type']) ?> · <?= e(QuestionBanks::DIFFICULTIES[$q['difficulty']] ?? $q['difficulty']) ?> · <?= e(C::marks($q['marks'])) ?> marks<?= $q['topic_name'] !== null ? ' · ' . e($q['topic_name']) : '' ?>
                                    <?= (int) $q['published_version'] !== (int) $q['latest_version'] ? ' · version ' . (int) $q['published_version'] . ' is the published one' : '' ?></span>
                            </label>
                        </div></li>
                    <?php endforeach; ?>
                </ul>
                <div class="card-footer"><button class="btn btn-primary" type="submit"><?= icon('plus') ?>Add the ticked questions</button>
                    <a class="btn btn-outline-secondary" href="<?= e(url($base)) ?>">Back to the assessment</a></div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('shuffle') ?>Or draw them</span></div>
            <div class="card-body">
                <p class="small text-muted">Each candidate gets a different set of published questions from <?= e($bank['name'] ?? '') ?> matching these, never one already chosen above.</p>
                <form method="post" action="<?= e(url($base . '/blueprint')) ?>" data-once><?= csrf_field() ?>
                    <input type="hidden" name="bank" value="<?= e((string) ($bank['uuid'] ?? '')) ?>">
                    <div class="mb-3"><label class="form-label" for="bp-count">How many</label><input class="form-control" type="number" min="1" max="300" id="bp-count" name="count" value="5" required></div>
                    <div class="mb-3"><label class="form-label" for="bp-topic">Topic</label><select class="form-select" id="bp-topic" name="topic"><option value="">Any</option>
                        <?php foreach ($topics as $t): ?><option value="<?= e($t['uuid']) ?>"><?= $t['depth'] ? '&nbsp;&nbsp;' : '' ?><?= e($t['name']) ?></option><?php endforeach; ?></select>
                        <div class="form-text">A topic includes its subtopics.</div></div>
                    <div class="mb-3"><label class="form-label" for="bp-diff">Difficulty</label><select class="form-select" id="bp-diff" name="difficulty"><option value="">Any</option>
                        <?php foreach (QuestionBanks::DIFFICULTIES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
                    <button class="btn btn-outline-primary" type="submit"><?= icon('plus') ?>Add to the blueprint</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
