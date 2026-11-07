<?php /** @var array $institution @var array $membership @var string $tab @var array $assessment @var list<array> $form @var int $seed @var float $total */
use App\Controllers\InstitutionAssessmentController as C;
$a = $assessment;
$base = '/institutions/' . $institution['uuid'] . '/assessments/' . $a['uuid'];
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Assessments' => '/institutions/' . $institution['uuid'] . '/assessments', $a['title'] => $base, 'Preview' => null]]) ?>
<div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status"><?= icon('eye') ?>
    <div class="alert-body flex-grow-1">One candidate's form: <?= count($form) ?> <?= count($form) === 1 ? 'question' : 'questions' ?>, <?= e(C::marks($total)) ?> marks, <?= (int) $a['duration_minutes'] ?> minutes. Right answers are marked for you; candidates never see them while they answer.</div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/preview')) ?>"><?= icon('shuffle') ?>Draw another</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base)) ?>">Back</a></div>
<?php if (trim((string) ($a['instructions'] ?? '')) !== ''): ?>
    <div class="card mb-4"><div class="card-header"><span class="card-title"><?= icon('info-circle') ?>Instructions</span></div><div class="card-body"><?= nl2br(e((string) $a['instructions'])) ?></div></div>
<?php endif; ?>
<?php if ($form === []): ?>
    <div class="card"><div class="card-body"><?= empty_state('list-check', 'Nothing to show yet', 'Add questions first.', null, true) ?></div></div>
<?php endif; ?>
<?php foreach ($form as $n => $f): $v = $f['version'];
    $choices = [];
    foreach ((array) json_decode((string) $v['choices'], true) as $c) { $choices[(string) $c['key']] = $c; }
    $ordered = array_map(static fn(string $k): array => $choices[$k], array_values(array_filter($f['choice_order'], static fn(string $k): bool => isset($choices[$k])))); ?>
    <div class="card mb-3">
        <div class="card-header"><span class="card-title">Question <?= $n + 1 ?></span><span class="small text-muted"><?= e(C::marks($v['marks'])) ?> marks</span></div>
        <div class="card-body"><?= \App\Core\View::current()->partial('institutions/_question_body', ['v' => ['choices' => $ordered] + $v, 'key' => true]) ?></div>
    </div>
<?php endforeach; ?>
<p class="small text-muted">Form number <?= (int) $seed ?>: <a href="<?= e(url($base . '/preview', ['seed' => (string) $seed])) ?>">this exact form again</a>.</p>
