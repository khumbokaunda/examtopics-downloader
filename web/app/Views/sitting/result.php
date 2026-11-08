<?php /** @var array $session @var list<array> $questions @var array<int,array> $responses @var list<array> $topics */
use App\Controllers\InstitutionAssessmentController as C;
$s = $session;
$policy = (string) $s['review_policy'];
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/my/assessments')) ?>">My assessments</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($s['title']) ?></li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e($s['title']) ?></h1>
        <p class="page-subtitle"><?= e($s['institution_name']) ?> · attempt <?= (int) $s['attempt'] ?> · handed in <?= e(fmt_time((string) $s['submitted_at'])) ?></p>
    </div>
    <?php if ($policy !== 'none'): ?><div class="page-actions"><a class="btn btn-outline-secondary" href="<?= e(url('/sit/' . $s['uuid'] . '/result.pdf')) ?>"><?= icon('file-earmark-pdf') ?>Download as PDF</a></div><?php endif; ?>
</header>
<?php if ($policy === 'none'): ?>
    <div class="card"><div class="card-body"><?= empty_state('lock', 'Your result is recorded', 'The institution keeps the results of this assessment to itself. Ask them if you need it.', null, true) ?></div></div>
<?php else: ?>
<div class="kpi-strip">
    <?php foreach ([
        ['Score', C::marks($s['score']) . ' of ' . C::marks($s['max_score']), 'trophy', 'primary'],
        ['Percentage', C::marks($s['percent']) . '%', 'percent', 'info'],
        ...($s['passed'] !== null ? [[(int) $s['passed'] ? 'Passed' : 'Not passed', 'Pass mark ' . C::marks($s['pass_percent']) . '%', (int) $s['passed'] ? 'patch-check' : 'x-octagon', (int) $s['passed'] ? 'success' : 'danger']] : []),
    ] as [$label, $value, $iconName, $kt]): ?>
        <div class="kpi" data-tone="<?= e($kt) ?>"><span class="kpi-icon"><?= icon($iconName) ?></span>
            <span class="kpi-text"><span class="kpi-value d-block"><?= e($value) ?></span><span class="kpi-label d-block"><?= e($label) ?></span></span></div>
    <?php endforeach; ?>
</div>
<?= \App\Core\View::current()->partial('sitting/_topics', ['topics' => $topics]) ?>
<?php if ($policy === 'answers'): ?>
    <h2 class="section-heading mt-4">Your answers</h2>
    <?php foreach ($questions as $q): $pos = (int) $q['position']; $mine = str_split((string) ($responses[$pos]['answer'] ?? ''));
        $choices = [];
        foreach ((array) json_decode((string) $q['choices'], true) as $c) { $choices[(string) $c['key']] = $c; }
        $ordered = array_values(array_filter(array_map(static fn(string $k) => $choices[$k] ?? null, str_split((string) $q['choice_order'])))); ?>
        <div class="card mb-3">
            <div class="card-header"><span class="card-title">Question <?= $pos ?></span><span class="small"><?= (int) $q['correct'] ? chip('Right', 'success') : chip(($responses[$pos]['answer'] ?? '') === '' ? 'Not answered' : 'Wrong', ($responses[$pos]['answer'] ?? '') === '' ? 'neutral' : 'danger') ?> <?= e(C::marks($q['awarded'])) ?> of <?= e(C::marks($q['marks'])) ?></span></div>
            <div class="card-body">
                <div class="bank-question-text"><?= nl2br(e((string) $q['text'])) ?></div>
                <ul class="bank-choices">
                    <?php foreach ($ordered as $n => $c): $right = !empty($c['correct']); $picked = in_array((string) $c['key'], $mine, true); ?>
                        <li class="<?= $right ? 'is-right' : ($picked ? 'is-picked-wrong' : '') ?>"><span class="bank-choice-key"><?= $q['type'] === 'true_false' ? '' : chr(65 + $n) ?></span><span><?= nl2br(e((string) $c['text'])) ?></span>
                            <span class="ms-auto small"><?= $picked ? 'Your answer' : '' ?><?= $right ? ' ' . icon('check-circle-fill') : '' ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($q['explanation'] !== null): ?><div class="bank-explanation small"><strong>Explanation.</strong> <?= nl2br(e((string) $q['explanation'])) ?></div><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
