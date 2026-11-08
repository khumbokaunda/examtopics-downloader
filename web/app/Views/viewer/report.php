<?php
/**
 * Report a problem with a question to the people who correct questions (both modes).
 *
 * @var array $item @var string $base @var array $version @var int $page @var string $q
 * @var string $mode @var bool $review @var bool $mistakes
 */
use App\Repositories\QuestionReportRepository;
use App\Services\Documents\QuestionView;

$n = (int) $item['number'];
?>
<details class="report-problem">
    <summary><?= icon('flag') ?>Report a problem</summary>
    <form method="post" action="<?= e(url($base . '/reports')) ?>" class="report-problem-body" data-once data-busy="Sending…">
        <?= csrf_field() ?>
        <input type="hidden" name="key" value="<?= e(QuestionView::key($item)) ?>">
        <input type="hidden" name="version" value="<?= e((string) $version['version']) ?>">
        <input type="hidden" name="page" value="<?= (int) $page ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">
        <?php if ($mode === 'practice'): ?><input type="hidden" name="mode" value="practice"><?php endif; ?>
        <?php if ($review): ?><input type="hidden" name="review" value="1"><?php endif; ?>
        <?php if ($mistakes): ?><input type="hidden" name="mistakes" value="1"><?php endif; ?>
        <?= $filterFields ?? '' ?>
        <label class="form-label" for="report-kind-<?= $n ?>">What is wrong?</label>
        <select class="form-select form-select-sm" id="report-kind-<?= $n ?>" name="kind" required>
            <option value="">Choose…</option>
            <?php foreach (QuestionReportRepository::KINDS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label mt-2" for="report-body-<?= $n ?>">Details</label>
        <textarea class="form-control" id="report-body-<?= $n ?>" name="body" rows="2" maxlength="5000" required placeholder="For example: the answer should be C, because…"></textarea>
        <div class="form-text">You are told when it is dealt with.</div>
        <div class="post-actions"><button class="btn btn-sm btn-primary" type="submit"><?= icon('send') ?>Send</button></div>
    </form>
</details>
