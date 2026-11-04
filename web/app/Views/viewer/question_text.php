<?php
/**
 * A question's text with its pictures where the dataset places them, for pages other than the
 * reader (the mock exam and its result). The choices are each page's own.
 *
 * @var array $item @var array<string,string> $localImages
 */
use App\Services\Documents\QuestionView;

$images = QuestionView::questionImages($item);
foreach (QuestionView::questionSegments($item) as $seg): ?>
    <?php if (isset($seg['text'])): ?><div class="q-text"><?php foreach (QuestionView::paragraphs($seg['text']) as $para): ?><p><?= $para ?></p><?php endforeach; ?></div>
    <?php else: $u = $images[$seg['image']]; $src = $localImages[$u] ?? QuestionView::safeImageUrl($u); ?>
        <?php if ($src !== null): ?><img class="q-image" src="<?= e($src) ?>" alt="Question image <?= $seg['image'] + 1 ?>" loading="lazy" referrerpolicy="no-referrer">
        <?php else: ?><div class="small text-muted">[Question image <?= $seg['image'] + 1 ?> is not available]</div><?php endif; ?>
    <?php endif; ?>
<?php endforeach;
