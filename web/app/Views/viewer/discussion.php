<?php
/**
 * Members' own discussion of a question (study mode): their comments, the answer each suggests
 * (their vote), and a form to add one.
 *
 * @var array $item a question with its corrections applied @var list<array> $comments
 * @var string $base @var array $version @var int $page @var string $q @var bool $review @var bool $reported
 */
use App\Services\Access;
use App\Services\Documents\QuestionView;

$n = (int) $item['number'];
$me = (int) (\App\Core\View::current()->user()['id'] ?? 0);
$moderator = can('answers.edit');
$letters = QuestionView::choiceLetters($item);
$hidden = static function () use ($version, $page, $q, $review, $reported, $n): string {
    return csrf_field()
        . '<input type="hidden" name="version" value="' . e((string) $version['version']) . '">'
        . '<input type="hidden" name="page" value="' . (int) $page . '">'
        . '<input type="hidden" name="q" value="' . e($q) . '">'
        . '<input type="hidden" name="number" value="' . $n . '">'
        . ($review ? '<input type="hidden" name="review" value="1">' : '')
        . ($reported ? '<input type="hidden" name="reported" value="1">' : '');
};
?>
<details class="discussion member-discussion" id="discussion-<?= $n ?>"<?= $comments !== [] ? ' data-has-comments' : '' ?>>
    <summary><?= icon('chevron-right') ?><?= icon('people') ?>Members' discussion (<?= count($comments) ?>)<?php $votes = QuestionView::memberVotesLine($item); if ($votes !== null): ?><span class="summary-note">votes: <?= e($votes) ?></span><?php endif; ?></summary>
    <div class="discussion-body">
        <?php if ($comments === []): ?>
            <p class="member-discussion-empty">Nobody here has discussed this question yet. Say what you think, and which answer you would choose.</p>
        <?php endif; ?>
        <?php foreach ($comments as $c): $author = $c['user_id'] !== null ? Access::displayName($c) : 'A former member'; ?>
            <div class="comment">
                <span class="comment-avatar" aria-hidden="true"><?= e(initials($author)) ?></span>
                <div class="comment-main">
                    <div class="comment-head">
                        <span class="comment-author"><?= e($author) ?></span>
                        <?php if ((int) $c['user_id'] === $me): ?><?= chip('you', 'neutral') ?><?php endif; ?>
                        <span class="comment-time" title="<?= e(fmt_time((string) $c['created_at'])) ?>"><?= e(fmt_ago((string) $c['created_at'])) ?></span>
                    </div>
                    <?php if (trim((string) $c['body']) !== ''): ?>
                        <div class="comment-body"><?php foreach (QuestionView::paragraphs((string) $c['body']) as $para): ?><p><?= $para ?></p><?php endforeach; ?></div>
                    <?php endif; ?>
                    <?php if ($c['answer'] !== null || (int) $c['user_id'] === $me || $moderator): ?>
                        <div class="comment-foot">
                            <?php if ($c['answer'] !== null): ?><?= chip('suggests ' . $c['answer'], 'primary', 'check2') ?><?php endif; ?>
                            <?php if ((int) $c['user_id'] === $me || $moderator): ?>
                                <form method="post" action="<?= e(url($base . '/discussion/' . (int) $c['id'] . '/delete')) ?>" class="comment-delete"
                                      data-confirm="<?= $c['answer'] !== null ? 'Its vote is taken away too.' : 'It cannot be brought back.' ?>" data-confirm-title="Delete this comment?" data-confirm-button="Delete" data-confirm-tone="danger">
                                    <?= $hidden() ?>
                                    <button class="btn btn-sm btn-link btn-link-danger" type="submit"><?= icon('trash3') ?>Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <form method="post" action="<?= e(url($base . '/discussion')) ?>" class="member-discussion-form" data-once data-busy="Posting…">
            <?= $hidden() ?>
            <input type="hidden" name="key" value="<?= e(QuestionView::key($item)) ?>">
            <label class="form-label" for="comment-<?= $n ?>">Add to the discussion</label>
            <textarea class="form-control" id="comment-<?= $n ?>" name="body" rows="3" maxlength="5000" placeholder="What do you think, and why?"></textarea>
            <?php if ($letters !== []): ?>
                <fieldset class="mt-2">
                    <legend class="form-label small mb-1">The answer you would choose <span class="text-muted fw-normal">(optional; it counts as your vote, and your latest one counts)</span></legend>
                    <div class="letter-picks">
                        <?php foreach ($letters as $l): ?>
                            <label class="letter-pick"><input type="checkbox" name="letter_<?= e($l) ?>" value="1"><span><?= e($l) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endif; ?>
            <div class="post-actions"><button class="btn btn-sm btn-primary" type="submit"><?= icon('send') ?>Post</button></div>
        </form>
    </div>
</details>
