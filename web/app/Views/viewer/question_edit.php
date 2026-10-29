<?php
/**
 * The reader's question editor (study mode, for people who may correct answers): the wording,
 * each choice, and the question's and answer's pictures, which can be removed or added to.
 *
 * @var array $item a question with its corrections applied @var string $base @var array $version
 * @var int $page @var string $q @var bool $review @var array<string,string> $localImages
 */
use App\Controllers\QuestionController;
use App\Services\Documents\QuestionView;
use App\Services\MediaStore;

$n = (int) $item['number'];
$edited = $item['question_edit'] ?? null;
$key = QuestionView::key($item);
$hidden = static function () use ($key, $version, $page, $q, $review): string {
    return csrf_field()
        . '<input type="hidden" name="key" value="' . e($key) . '">'
        . '<input type="hidden" name="version" value="' . e((string) $version['version']) . '">'
        . '<input type="hidden" name="page" value="' . (int) $page . '">'
        . '<input type="hidden" name="q" value="' . e($q) . '">'
        . ($review ? '<input type="hidden" name="review" value="1">' : '');
};
$accept = implode(',', array_keys(MediaStore::TYPES));
$pictures = ['question' => ['Question pictures', (array) ($item['question_images'] ?? [])], 'answer' => ['Answer pictures', (array) ($item['answer_images'] ?? [])]];
?>
<details class="answer-edit question-edit">
    <summary><?= icon('pencil') ?><?= $edited !== null ? 'Change your edit of this question' : 'Edit this question' ?></summary>
    <div class="answer-edit-body">
        <form method="post" action="<?= e(url($base . '/questions')) ?>" enctype="multipart/form-data" data-once data-busy="Saving…">
            <?= $hidden() ?>
            <label class="form-label" for="qtext-<?= $n ?>">Question</label>
            <textarea class="form-control" id="qtext-<?= $n ?>" name="text" rows="6" maxlength="20000"><?= e(QuestionView::editorText($item)) ?></textarea>
            <div class="form-text">A line that says <span class="mono"><?= e(QuestionView::PICTURE_LINE) ?></span> marks where a picture sits; pictures without one follow the text.</div>

            <?php $choices = array_filter((array) ($item['choices'] ?? []), static fn($c) => is_array($c) && (string) ($c['letter'] ?? '') !== ''); ?>
            <?php if ($choices !== []): ?>
                <fieldset class="mt-3">
                    <legend class="form-label">Choices</legend>
                    <?php foreach ($choices as $c): $letter = (string) $c['letter']; ?>
                        <div class="edit-choice">
                            <label class="edit-choice-letter" for="qchoice-<?= $n ?>-<?= e($letter) ?>" aria-label="Choice <?= e($letter) ?>"><?= e($letter) ?>.</label>
                            <textarea class="form-control" id="qchoice-<?= $n ?>-<?= e($letter) ?>" name="choice_<?= e($letter) ?>" rows="1" maxlength="4000"><?= e((string) ($c['text'] ?? '')) ?></textarea>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>

            <?php foreach ($pictures as $prefix => [$legend, $urls]): ?>
                <fieldset class="mt-3">
                    <legend class="form-label"><?= e($legend) ?></legend>
                    <?php if ($urls !== []): ?>
                        <div class="edit-images">
                            <?php foreach ($urls as $i => $u): $u = (string) $u; $src = $localImages[$u] ?? QuestionView::safeImageUrl($u); ?>
                                <label class="edit-image">
                                    <?php if ($src !== null): ?><img src="<?= e($src) ?>" alt="<?= e($legend . ' ' . ($i + 1)) ?>" loading="lazy" referrerpolicy="no-referrer">
                                    <?php else: ?><span class="edit-image-missing"><?= icon('image') ?>Not on this server</span><?php endif; ?>
                                    <span class="edit-image-pick"><input class="form-check-input" type="checkbox" name="remove_<?= $prefix ?>_<?= e(QuestionController::imageId($u)) ?>" value="1"> Remove <?= $i + 1 ?><?= MediaStore::isUpload($u) ? ' (added)' : '' ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <label class="form-label small text-muted mb-1" for="qimg-<?= $prefix ?>-<?= $n ?>">Add a picture <span class="fw-normal">(PNG, JPEG, GIF or WebP)</span></label>
                    <input class="form-control form-control-sm" type="file" id="qimg-<?= $prefix ?>-<?= $n ?>" name="<?= $prefix ?>_image" accept="<?= e($accept) ?>">
                </fieldset>
            <?php endforeach; ?>
            <div class="form-text">Edits show in every version of this exam and in documents made after them. Collecting the exam again keeps them.</div>
            <div class="answer-edit-actions"><button class="btn btn-sm btn-primary" type="submit"><?= icon('check2') ?>Save</button></div>
        </form>
        <?php if ($edited !== null): ?>
            <form method="post" action="<?= e(url($base . '/questions')) ?>" class="answer-edit-remove" data-once data-busy="Putting back…">
                <?= $hidden() ?>
                <input type="hidden" name="action" value="reset">
                <button class="btn btn-sm btn-link btn-link-danger" type="submit"><?= icon('arrow-counterclockwise') ?>Put back as collected</button>
            </form>
        <?php endif; ?>
    </div>
</details>
