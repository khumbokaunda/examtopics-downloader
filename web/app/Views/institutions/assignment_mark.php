<?php /** Marking one learner's work. @var array $institution @var array $membership @var string $tab @var array $crumb @var array $course @var array $assignment @var array $learner
 *  @var array|null $submission @var array|null $mark @var list<array> $criteria @var string|null $next */
use App\Services\Access;
use App\Services\Gradebook;
$a = $assignment;
$base = '/institutions/' . $institution['uuid'] . '/courses/' . $course['uuid'] . '/assignments/' . $a['uuid'];
$f = static fn(?float $n): string => $n === null ? '' : Gradebook::fmt($n);
$final = $course['grades_final_at'] !== null;
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab, 'crumb' => $crumb]) ?>
<div class="row g-4">
    <div class="col-xl-6">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('inbox') ?><?= e(Access::displayName($learner)) ?>'s work</span></div>
            <div class="card-body">
                <?php if ($submission === null): ?>
                    <p class="mb-0 text-muted">Nothing handed in. A mark can still be given (for example, for work handed in on paper).</p>
                <?php else: ?>
                    <p>Handed in <?= e(fmt_time((string) $submission['submitted_at'])) ?><?= (int) $submission['late'] === 1 ? ' ' . chip('Late', 'warning') : '' ?>.</p>
                    <ul><?php foreach ($submission['files'] as $file): ?><li><a href="<?= e(url($base . '/files/' . $file['uuid'])) ?>"><?= e($file['filename']) ?></a> <span class="small text-muted">(<?= e(number_format((int) $file['size'] / 1024, 0)) ?> KB)</span></li><?php endforeach; ?></ul>
                    <?php if ($submission['note'] !== null): ?><p class="small text-muted mb-0 text-pre"><?= e((string) $submission['note']) ?></p><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('check2-square') ?>Mark</span><?= $a['released_at'] !== null ? chip('Learners see marks', 'primary') : chip('Draft until released', 'neutral') ?></div>
            <div class="card-body">
                <?php if ($final): ?><div class="alert alert-info small" role="status"><?= icon('lock') ?><div class="alert-body">The course grades are final. Make them provisional again to change marks.</div></div><?php endif; ?>
                <form method="post" action="<?= e(url($base . '/submissions/' . $learner['uuid'])) ?>" data-once><?= csrf_field() ?>
                    <?php if ($criteria !== []): ?>
                        <?php foreach ($criteria as $cr): $cid = (string) $cr['id']; ?>
                            <div class="mb-3"><label class="form-label" for="cr-<?= e($cid) ?>"><?= e($cr['title']) ?> (out of <?= e($f((float) $cr['max_marks'])) ?>)</label>
                                <input class="form-control" type="number" id="cr-<?= e($cid) ?>" name="criteria[<?= e($cid) ?>]" min="0" max="<?= e($f((float) $cr['max_marks'])) ?>" step="0.01"
                                    value="<?= isset($mark['criteria'][$cid]) ? e($f((float) $mark['criteria'][$cid])) : '' ?>"<?= $final ? ' disabled' : '' ?>>
                                <?php if ($cr['description'] !== null): ?><div class="form-text"><?= e((string) $cr['description']) ?></div><?php endif; ?></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="mb-3"><label class="form-label" for="marks">Mark (out of <?= e($f((float) $a['max_marks'])) ?>)</label>
                            <input class="form-control" type="number" id="marks" name="marks" min="0" max="<?= e($f((float) $a['max_marks'])) ?>" step="0.01" value="<?= $mark !== null && $mark['marks'] !== null ? e($f((float) $mark['marks'])) : '' ?>"<?= $final ? ' disabled' : '' ?>></div>
                    <?php endif; ?>
                    <div class="mb-3"><label class="form-label" for="feedback">Feedback</label><textarea class="form-control" id="feedback" name="feedback" rows="5" maxlength="5000"<?= $final ? ' disabled' : '' ?>><?= e((string) ($mark['feedback'] ?? '')) ?></textarea></div>
                    <?php if ($next !== null): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
                    <?php if (!$final): ?>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save</button>
                        <?php if ($next !== null): ?><button class="btn btn-outline-primary" type="submit" name="go" value="next"><?= icon('arrow-right') ?>Save and mark the next</button><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </form>
                <?php if ($mark !== null): ?><p class="small text-muted mt-3 mb-0">Last saved <?= e(fmt_time((string) $mark['entered_at'])) ?>. Earlier marks are kept.</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>
