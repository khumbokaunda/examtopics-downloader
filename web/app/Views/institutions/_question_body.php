<?php
/** A question as a candidate sees it, and (with $key) its answer. Choices are lettered in the order shown. @var array $v a question version (type, text, choices, images, marks, explanation, reference) @var bool $key */
use App\Controllers\InstitutionBankController;
$choices = is_array($v['choices']) ? $v['choices'] : (array) json_decode((string) $v['choices'], true);
$images = is_array($v['images'] ?? null) ? $v['images'] : (array) (json_decode((string) ($v['images'] ?? ''), true) ?: []);
?>
<div class="bank-question">
    <div class="bank-question-text"><?= nl2br(e((string) $v['text'])) ?></div>
    <?php foreach ($images as $img): ?><img class="bank-question-image" src="<?= e(InstitutionBankController::imageSrc((string) $img)) ?>" alt="Picture for the question" loading="lazy"><?php endforeach; ?>
    <p class="small text-muted mb-2"><?= $v['type'] === 'multiple' ? 'Choose every right answer.' : 'Choose one answer.' ?></p>
    <ul class="bank-choices">
        <?php foreach (array_values($choices) as $pos => $c): $right = $key && !empty($c['correct']); ?>
            <li class="<?= $right ? 'is-right' : '' ?>"><span class="bank-choice-key"><?= e($v['type'] === 'true_false' ? '' : chr(65 + $pos)) ?></span><span><?= nl2br(e((string) $c['text'])) ?></span><?= $right ? icon('check-circle-fill', 'ms-auto') : '' ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($key && (($v['explanation'] ?? null) !== null || ($v['reference'] ?? null) !== null)): ?>
        <div class="bank-explanation small">
            <?php if ($v['explanation'] !== null): ?><p class="mb-1"><strong>Explanation.</strong> <?= nl2br(e((string) $v['explanation'])) ?></p><?php endif; ?>
            <?php if ($v['reference'] !== null): ?><p class="mb-0"><strong>Reference.</strong> <?= e((string) $v['reference']) ?></p><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
