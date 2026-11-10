<?php /** Marking one sitting's written answers. @var array $institution @var array $membership @var string $tab @var array $crumb @var array $assessment @var array $session
 *  @var list<array> $questions the written-answer questions @var array<int,array> $responses @var string|null $next another sitting waiting */
use App\Controllers\InstitutionAssessmentController as C;
use App\Services\Access;
$base = '/institutions/' . $institution['uuid'] . '/assessments/' . $assessment['uuid'] . '/results/' . $session['uuid'] . '/marking';
$locked = $assessment['results_published_at'] !== null;
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab, 'crumb' => $crumb]) ?>
<div class="card mb-4"><div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="icon-bubble" data-tone="primary"><?= icon('pencil-square') ?></span>
    <div class="flex-grow-1"><h2 class="h5 mb-1"><?= e(Access::displayName($session)) ?>, attempt <?= (int) $session['attempt'] ?></h2>
        <div class="small text-muted">Handed in <?= e(fmt_time((string) $session['submitted_at'])) ?> · <?= $session['marking'] === 'awaiting' ? 'written answers to mark' : 'marked: ' . e(C::marks($session['percent'])) . '%' ?></div></div>
</div></div>
<?php if ($locked): ?><div class="alert alert-info" role="status"><?= icon('lock') ?><div class="alert-body">The results are published, so the marks stay as they are.</div></div><?php endif; ?>
<form method="post" action="<?= e(url($base)) ?>" data-once><?= csrf_field() ?>
    <?php foreach ($questions as $q): $pos = (int) $q['position']; $answer = (string) ($responses[$pos]['answer'] ?? ''); ?>
        <div class="card mb-3">
            <div class="card-header"><span class="card-title">Question <?= $pos ?></span><span class="small text-muted">out of <?= e(C::marks($q['marks'])) ?></span></div>
            <div class="card-body">
                <div class="bank-question-text mb-3"><?= nl2br(e((string) $q['text'])) ?></div>
                <?php if ($q['explanation'] !== null): ?><div class="bank-explanation small mb-3"><strong>Marking guide.</strong> <?= nl2br(e((string) $q['explanation'])) ?></div><?php endif; ?>
                <div class="written-answer text-pre mb-3"><?= $answer !== '' ? e($answer) : '<span class="text-muted">Not answered: it earns nothing.</span>' ?></div>
                <?php if ($answer !== ''): ?>
                <div class="row g-3">
                    <div class="col-sm-3"><label class="form-label" for="m<?= $pos ?>">Marks</label><input class="form-control" type="number" id="m<?= $pos ?>" name="marks[<?= $pos ?>]" min="0" max="<?= e(C::marks($q['marks'])) ?>" step="0.01"
                        value="<?= $q['awarded'] !== null ? e(C::marks($q['awarded'])) : '' ?>"<?= $locked ? ' disabled' : '' ?>></div>
                    <div class="col-sm-9"><label class="form-label" for="f<?= $pos ?>">Feedback</label><textarea class="form-control" id="f<?= $pos ?>" name="feedback[<?= $pos ?>]" rows="2" maxlength="5000"<?= $locked ? ' disabled' : '' ?>><?= e((string) ($q['marker_feedback'] ?? '')) ?></textarea></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$locked): ?>
    <?php if ($next !== null): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
    <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save the marks</button>
        <?php if ($next !== null): ?><button class="btn btn-outline-primary" type="submit" name="go" value="next"><?= icon('arrow-right') ?>Save and mark the next</button><?php endif; ?>
    </div>
    <?php endif; ?>
</form>
