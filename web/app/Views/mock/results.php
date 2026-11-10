<?php
/**
 * A finished mock exam: the score, then each question with what was picked and the answer it
 * was graded against (kept with the attempt, so a later correction does not change it).
 *
 * @var array $provider @var array $exam @var array $version @var array $mock
 * @var list<array> $questions the questions shown (all, wrong or unanswered) @var string $show
 * @var int $missing questions no longer in the version @var array<string,string> $localImages
 * @var array $readiness from Readiness::assess() @var array|null $certificate @var list<array> $attempts
 */
use App\Services\Documents\QuestionView;
use App\Services\Editions;

$base = '/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']);
$total = (int) $mock['total'];
$right = (int) $mock['right_count'];
$unanswered = count(array_filter($mock['results'], static fn($r) => is_array($r) && $r['picked'] === ''));
$wrong = $total - $right - $unanswered;
$pct = $total > 0 ? (int) round($right * 100 / $total) : 0;
$seconds = max(0, (int) strtotime($mock['finished_at'] . ' UTC') - (int) strtotime($mock['started_at'] . ' UTC'));
$taken = $seconds >= 3600 ? intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min' : max(1, intdiv($seconds + 59, 60)) . ' min';
$order = array_flip($mock['keys']);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/library')) ?>">Library</a></li>
    <li class="breadcrumb-item"><a href="<?= e(url($base, ['version' => $version['version']])) ?>" title="<?= e(strtoupper((string) $exam['code'])) ?>"><?= e(Editions::shortCode((string) $exam['code'])) ?></a></li>
    <li class="breadcrumb-item"><a href="<?= e(url($base . '/mock')) ?>">Mock exam</a></li>
    <li class="breadcrumb-item active" aria-current="page">Result</li>
</ol></nav>

<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= $pct ?>%: <?= $right ?> of <?= $total ?> right</h1>
        <p class="page-subtitle"><?= e(Editions::title($exam)) ?> · <?= e(fmt_time((string) $mock['finished_at'])) ?> · <?= e($taken) ?><?= (int) $mock['minutes'] > 0 ? ' of ' . (int) $mock['minutes'] . ' min' : '' ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= e(url($base . '/view', ['mode' => 'practice', 'mistakes' => '1'])) ?>"><?= icon('arrow-repeat') ?>Go over my mistakes</a>
        <a class="btn btn-primary" href="<?= e(url($base . '/mock')) ?>"><?= icon('stopwatch') ?>Another mock exam</a>
    </div>
</header>

<div class="kpi-strip">
    <?php foreach ([['Right', $right, 'check-circle', 'success'], ['Wrong', $wrong, 'x-circle', $wrong > 0 ? 'danger' : 'neutral'],
        ['Not answered', $unanswered, 'dash-circle', $unanswered > 0 ? 'warning' : 'neutral']] as [$label, $value, $iconName, $tone]): ?>
        <div class="kpi" data-tone="<?= e($tone) ?>">
            <span class="kpi-icon"><?= icon($iconName) ?></span>
            <span class="kpi-text"><span class="kpi-value d-block"><?= number_format($value) ?></span><span class="kpi-label d-block"><?= e($label) ?></span></span>
        </div>
    <?php endforeach; ?>
</div>

<?php $rTone = ['ready' => 'success', 'nearly' => 'info', 'developing' => 'warning', 'not_yet' => 'danger'][$readiness['level']]; ?>
<div class="card mb-4 readiness-card">
    <div class="card-header"><span class="card-title"><?= icon('speedometer2') ?>Readiness</span><?= chip($readiness['label'], $rTone) ?></div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-7">
                <p class="small text-muted mb-2">Based on this attempt. Not a prediction of the real exam.</p>
                <ul class="readiness-conditions">
                    <?php foreach ($readiness['conditions'] as $c): ?><li class="<?= $c['ok'] ? 'is-met' : '' ?>"><?= icon($c['ok'] ? 'check-circle-fill' : 'circle') ?><span><?= e($c['text']) ?></span></li><?php endforeach; ?>
                </ul>
                <?php if ($certificate !== null): ?>
                    <a class="btn btn-success" href="<?= e(url('/certificates/' . $certificate['uuid'])) ?>"><?= icon('award') ?>Your certificate <?= e($certificate['number']) ?></a>
                    <?php if (!$readiness['eligible']): ?><p class="small text-muted mt-2 mb-0">Issued under the conditions in force at the time.</p><?php endif; ?>
                <?php elseif ($readiness['eligible']): ?>
                    <form method="post" action="<?= e(url('/mock/' . $mock['uuid'] . '/certificate')) ?>" data-once><?= csrf_field() ?>
                        <button class="btn btn-success" type="submit"><?= icon('award') ?>Get your certificate of demonstrated readiness</button></form>
                <?php else: ?>
                    <p class="small mb-0">Meet every condition in one attempt to earn a certificate of demonstrated readiness.</p>
                <?php endif; ?>
            </div>
            <div class="col-lg-5">
                <h2 class="section-heading">How you worked</h2>
                <dl class="row small mb-3">
                    <dt class="col-7">Answered</dt><dd class="col-5"><?= (int) $readiness['stats']['answered'] ?> of <?= $total ?></dd>
                    <dt class="col-7">Marked to look at again</dt><dd class="col-5"><?= (int) $readiness['stats']['flagged'] ?></dd>
                    <dt class="col-7">Time per question, on average</dt><dd class="col-5"><?= (int) $readiness['stats']['per_question'] ?> s</dd>
                </dl>
                <?php if (count($attempts) > 1): ?>
                <h2 class="section-heading">Your recent attempts</h2>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($attempts as $at): $p = (int) $at['total'] > 0 ? (int) round((int) $at['right_count'] * 100 / (int) $at['total']) : 0; ?>
                        <li><?= (int) $at['id'] === (int) $mock['id'] ? '<strong>' : '' ?><?= e(fmt_time((string) $at['finished_at'])) ?>: <?= $p ?>% of <?= (int) $at['total'] ?><?= (int) $at['minutes'] > 0 ? ', timed' : '' ?><?= (int) $at['id'] === (int) $mock['id'] ? ' (this one)</strong>' : '' ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<nav class="filter-chips" aria-label="Show">
    <?php foreach (['' => ['All', $total], 'wrong' => ['Wrong or not answered', $wrong + $unanswered], 'unanswered' => ['Not answered', $unanswered]] as $key => [$label, $n]): $on = $show === $key; ?>
        <a class="filter-chip<?= $on ? ' is-active' : '' ?>" href="<?= e(url('/mock/' . $mock['uuid'], ['show' => $key])) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="filter-chip-count"><?= number_format($n) ?></span></a>
    <?php endforeach; ?>
</nav>
<?php if ($missing > 0): ?><div class="alert alert-info small"><?= icon('info-circle') ?><div class="alert-body"><?= $missing ?> of the questions are no longer in this version, so they are not shown here. They were graded when you handed it in.</div></div><?php endif; ?>

<?php if ($questions === []): ?>
    <div class="card"><div class="card-body"><?= empty_state('check2-all', 'Nothing here', $show === 'unanswered' ? 'You answered every question.' : 'You got every question right.', null, true) ?></div></div>
<?php endif; ?>

<?php foreach ($questions as $item): $key = QuestionView::key($item); $r = $mock['results'][$key] ?? ['picked' => '', 'answer' => '', 'right' => false];
    $picked = str_split((string) $r['picked']); $answer = str_split((string) $r['answer']); $n = (int) $item['number']; ?>
    <article class="card question-card mock-result">
        <div class="card-body">
            <div class="q-head">
                <h2 class="q-number"><span class="num">Question <?= ($order[$key] ?? 0) + 1 ?> of <?= $total ?></span>
                    <?= $r['right'] ? chip('right', 'success', 'check2') : ($r['picked'] === '' ? chip('not answered', 'warning', 'dash') : chip('wrong', 'danger', 'x')) ?></h2>
                <span class="q-meta"><?= e(QuestionView::meta($item)) ?></span>
            </div>
            <?= \App\Core\View::current()->partial('viewer/question_text', ['item' => $item, 'localImages' => $localImages]) ?>
            <ul class="list-unstyled choices">
                <?php foreach ((array) ($item['choices'] ?? []) as $c): $letter = (string) ($c['letter'] ?? ''); if ($letter === '') { continue; }
                    $isAnswer = in_array($letter, $answer, true); $isPicked = in_array($letter, $picked, true); ?>
                    <li class="choice<?= $isAnswer ? ' is-answer' : ($isPicked ? ' is-wrong' : '') ?>"><span class="choice-letter"><?= e($letter) ?>.</span> <span class="choice-text"><?= nl2br(e((string) ($c['text'] ?? '')), false) ?></span>
                        <?php if ($isPicked || $isAnswer): ?><span class="choice-tag"><?= $isPicked && $isAnswer ? 'your pick, right' : ($isPicked ? 'your pick' : 'the answer') ?></span><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="answer-box">
                <?= icon('check-circle-fill') ?>
                <div>
                    <div class="answer-text"><strong>Answer:</strong> <?= e((string) $r['answer']) ?> · <strong>You picked:</strong> <?= $r['picked'] !== '' ? e((string) $r['picked']) : '<span class="text-muted">nothing</span>' ?></div>
                    <?php if (($item['edit']['note'] ?? '') !== ''): ?><div class="answer-note"><span class="answer-note-label">Note</span><?php foreach (QuestionView::paragraphs((string) $item['edit']['note']) as $para): ?><p><?= $para ?></p><?php endforeach; ?></div><?php endif; ?>
                </div>
            </div>
            <?php if (trim((string) ($item['explanation'] ?? '')) !== ''): ?>
                <div class="explanation"><div class="section-heading">Explanation</div><?php foreach (QuestionView::paragraphs((string) $item['explanation']) as $para): ?><p><?= $para ?></p><?php endforeach; ?></div>
            <?php endif; ?>
            <div class="q-foot"><a class="small" href="<?= e(url($base . '/view', ['version' => $version['version'], 'jump' => (string) $n]) . '#question-' . $n) ?>"><?= icon('book') ?> Open in the reader, with its discussion</a></div>
        </div>
    </article>
<?php endforeach; ?>
