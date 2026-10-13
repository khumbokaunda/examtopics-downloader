<?php
/** @var array $provider @var array $exam @var array $version @var list<array> $questions @var string $mode
 *  @var string $q @var int $page @var int $pages @var int $total @var int $all @var array<string,string> $localImages
 *  @var bool $review @var int $toCheck */
use App\Services\Documents\QuestionView;

$base = '/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']);
$params = ['version' => $version['version'], 'mode' => $mode === 'practice' ? 'practice' : '', 'q' => $q, 'review' => $review ? '1' : ''];
$practice = $mode === 'practice';
$img = static function (string $url, string $alt) use ($localImages): string {
    // The copy saved on this server when there is one, else the original address.
    $safe = $localImages[$url] ?? QuestionView::safeImageUrl($url);
    return $safe === null
        ? '<div class="small text-muted">[' . e($alt) . ': ' . e($url) . ']</div>'
        : '<img class="q-image" src="' . e($safe) . '" alt="' . e($alt) . '" loading="lazy" referrerpolicy="no-referrer">';
};
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/library')) ?>">Library</a></li>
    <li class="breadcrumb-item"><a href="<?= e(url($base, ['version' => $version['version']])) ?>"><?= e(strtoupper($exam['code'])) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= $practice ? 'Practice' : 'Study' ?></li>
</ol></nav>

<div class="reader">
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e(strtoupper($exam['code'])) ?></h1>
        <p class="page-subtitle"><?= $review ? $total . ' answers to check, of ' . $all . ' questions' : $total . ' of ' . $all . ' questions' ?> · version <?= e(fmt_time($version['collected_at'])) ?></p>
        <?php if ($version['status'] === 'partial'): ?><div class="mt-2"><?= chip('Partial version: some questions could not be collected', 'warning', 'exclamation-triangle') ?></div><?php endif; ?>
    </div>
    <div class="page-actions">
        <nav class="segmented" aria-label="Mode">
            <a class="<?= $practice ? '' : 'is-active' ?>" href="<?= e(url($base . '/view', ['version' => $version['version'], 'q' => $q])) ?>"<?= $practice ? '' : ' aria-current="page"' ?>><?= icon('book') ?>Study</a>
            <a class="<?= $practice ? 'is-active' : '' ?>" href="<?= e(url($base . '/view', ['version' => $version['version'], 'mode' => 'practice', 'q' => $q])) ?>"<?= $practice ? ' aria-current="page"' : '' ?>><?= icon('patch-question') ?>Practice</a>
        </nav>
    </div>
</header>

<form class="toolbar reader-toolbar" method="get" action="<?= e(url($base . '/view')) ?>">
    <input type="hidden" name="version" value="<?= e($version['version']) ?>">
    <?php if ($practice): ?><input type="hidden" name="mode" value="practice"><?php endif; ?>
    <?php if ($review): ?><input type="hidden" name="review" value="1"><?php endif; ?>
    <div class="search-field"><?= icon('search') ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search question and answer-choice text" aria-label="Search questions"></div>
    <button class="btn btn-outline-secondary" type="submit">Search</button>
    <?php if ($q === '' && !$review): ?>
        <input class="form-control jump-input" type="number" min="1" max="<?= (int) $all ?>" name="jump" placeholder="Go to #" aria-label="Go to question number">
    <?php elseif ($q !== ''): ?>
        <a class="btn btn-link" href="<?= e(url($base . '/view', ['version' => $version['version'], 'mode' => $practice ? 'practice' : '', 'review' => $review ? '1' : ''])) ?>">Clear search</a>
    <?php endif; ?>
    <?php if ($review): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url($base . '/view', ['version' => $version['version']])) ?>"><?= icon('list-ol') ?>All questions</a>
    <?php elseif ($toCheck > 0): ?>
        <a class="btn btn-outline-warning" href="<?= e(url($base . '/view', ['version' => $version['version'], 'review' => '1'])) ?>" title="Answers that look doubtful and that you have not corrected or checked yet"><?= icon('exclamation-triangle') ?>To check (<?= $toCheck ?>)</a>
    <?php endif; ?>
</form>

<?php if ($questions === []): ?>
    <div class="card"><div class="card-body"><?= $review && $q === ''
        ? empty_state('check2-all', 'Nothing left to check', 'Every answer that looked doubtful has been corrected or checked.', null, true)
        : empty_state('search', 'No questions match', 'Search looks in question and answer-choice text. Try fewer words.', null, true) ?></div></div>
<?php endif; ?>

<?php foreach ($questions as $item): $n = (int) $item['number'];
    $answerLetters = (!$practice && preg_match('/^[A-Z]+$/', trim((string) ($item['answer'] ?? ''))) === 1) ? str_split(trim((string) $item['answer'])) : []; ?>
    <article class="card question-card" id="question-<?= $n ?>">
        <div class="card-body">
            <div class="q-head">
                <h2 class="q-number"><span class="num">Question <?= $n ?></span><?php $kind = QuestionView::kind($item); if ($kind !== null): ?><?= chip($kind === 'drag-drop' ? 'Drag and drop' : 'Hot area', 'info', $kind === 'drag-drop' ? 'arrows-move' : 'cursor') ?><?php endif; ?></h2>
                <span class="q-meta"><?= e(QuestionView::meta($item)) ?></span>
            </div>
            <div class="q-text"><?php foreach (QuestionView::paragraphs((string) ($item['text'] ?? '')) as $para): ?><p><?= $para ?></p><?php endforeach; ?></div>
            <?php foreach ((array) ($item['question_images'] ?? []) as $i => $u): ?><?= $img((string) $u, 'Question image ' . ($i + 1)) ?><?php endforeach; ?>
            <?php if (($item['choices'] ?? []) !== []): ?>
                <ul class="list-unstyled choices">
                    <?php foreach ((array) $item['choices'] as $c): $letter = (string) ($c['letter'] ?? ''); ?>
                        <li class="choice<?= $letter !== '' && in_array($letter, $answerLetters, true) ? ' is-answer' : '' ?>" data-letter="<?= e($letter) ?>"><span class="choice-letter"><?= e($letter) ?>.</span> <span class="choice-text"><?= nl2br(e((string) ($c['text'] ?? '')), false) ?><?php foreach ((array) ($c['images'] ?? []) as $u): ?><?= $img((string) $u, 'Choice ' . $letter) ?><?php endforeach; ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($practice): ?>
                <!-- Practice mode: nothing answer-related is in this page. It is fetched on demand. -->
                <button class="btn btn-sm btn-outline-success" type="button" aria-expanded="false"
                        data-reveal-url="<?= e(url('/api/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']) . '/' . rawurlencode($version['version']) . '/questions/' . $n)) ?>"><?= icon('eye') ?><span class="label">Reveal answer</span></button>
                <div class="reveal-target" hidden></div>
            <?php else: ?>
                <?php $answer = QuestionView::answerLine($item); $votes = QuestionView::votesLine($item);
                $edit = $item['edit'] ?? null; $doubts = QuestionView::doubts($item); $letters = QuestionView::choiceLetters($item); ?>
                <div class="answer-box<?= ($edit['kind'] ?? '') === 'corrected' ? ' is-corrected' : '' ?>">
                    <?= icon(($edit['kind'] ?? '') === 'corrected' ? 'pencil-square' : 'check-circle-fill') ?>
                    <div>
                        <?php $note = QuestionView::pictureAnswerNote($item); ?>
                        <div class="answer-text"><strong>Answer:</strong> <?= $answer !== null ? e($answer) : '<span class="text-muted">' . e($note ?? 'not available') . '</span>' ?></div>
                        <?php if ($votes !== null): ?><div class="answer-votes">Community votes: <?= e($votes) ?></div><?php endif; ?>
                        <?php if (($edit['note'] ?? '') !== ''): ?><div class="answer-note"><span class="answer-note-label">Your note</span><?php foreach (QuestionView::paragraphs($edit['note']) as $para): ?><p><?= $para ?></p><?php endforeach; ?></div><?php endif; ?>
                    </div>
                </div>
                <?php if ($doubts !== []): $claims = QuestionView::claimsLine($item); ?>
                    <div class="answer-check" role="note">
                        <?= icon('exclamation-triangle-fill') ?>
                        <div>
                            <div class="answer-check-title">Check this answer</div>
                            <ul class="answer-check-list"><?php foreach ($doubts as $d): ?><li><?= e($d) ?></li><?php endforeach; ?></ul>
                            <?php if ($claims !== null): ?><div class="answer-check-claims">Answers named in the comments: <?= e($claims) ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <details class="answer-edit"<?= $doubts !== [] && $review ? ' open' : '' ?>>
                    <summary><?= icon('pencil-square') ?><?= $edit !== null ? 'Change your correction' : ($letters !== [] ? 'Correct this answer' : 'Add a note') ?></summary>
                    <div class="answer-edit-body">
                        <form method="post" action="<?= e(url($base . '/answers')) ?>" data-once data-busy="Saving…">
                            <?= csrf_field() ?>
                            <input type="hidden" name="key" value="<?= e(QuestionView::key($item)) ?>">
                            <input type="hidden" name="version" value="<?= e($version['version']) ?>">
                            <input type="hidden" name="page" value="<?= (int) $page ?>">
                            <input type="hidden" name="q" value="<?= e($q) ?>">
                            <?php if ($review): ?><input type="hidden" name="review" value="1"><?php endif; ?>
                            <?php if ($letters !== []): ?>
                                <fieldset class="mb-3">
                                    <legend class="form-label">Correct answer</legend>
                                    <div class="letter-picks">
                                        <?php foreach ($letters as $l): ?>
                                            <label class="letter-pick"><input type="checkbox" name="letter_<?= e($l) ?>" value="1"<?= in_array($l, $answerLetters, true) ? ' checked' : '' ?>><span><?= e($l) ?></span></label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="form-text">Tick every correct letter. Saving the collected letters unchanged marks the answer as checked.</div>
                                </fieldset>
                            <?php endif; ?>
                            <label class="form-label" for="note-<?= $n ?>">Note <span class="text-muted fw-normal">(optional, shown with the answer)</span></label>
                            <textarea class="form-control" id="note-<?= $n ?>" name="note" rows="2" maxlength="2000" placeholder="Why this is the answer, e.g. LSA is not a packet type"><?= e((string) ($edit['note'] ?? '')) ?></textarea>
                            <div class="answer-edit-actions"><button class="btn btn-sm btn-primary" type="submit"><?= icon('check2') ?>Save</button></div>
                        </form>
                        <?php if ($edit !== null): ?>
                            <form method="post" action="<?= e(url($base . '/answers')) ?>" class="answer-edit-remove" data-once data-busy="Removing…">
                                <?= csrf_field() ?>
                                <input type="hidden" name="key" value="<?= e(QuestionView::key($item)) ?>">
                                <input type="hidden" name="version" value="<?= e($version['version']) ?>">
                                <input type="hidden" name="page" value="<?= (int) $page ?>">
                                <input type="hidden" name="q" value="<?= e($q) ?>">
                                <?php if ($review): ?><input type="hidden" name="review" value="1"><?php endif; ?>
                                <input type="hidden" name="action" value="remove">
                                <button class="btn btn-sm btn-link btn-link-danger" type="submit"><?= icon('arrow-counterclockwise') ?>Remove my correction<?= ($edit['collected'] ?? '') !== '' ? ' (back to ' . e($edit['collected']) . ')' : '' ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>
                <?php foreach ((array) ($item['answer_images'] ?? []) as $i => $u): ?><?= $img((string) $u, 'Answer image ' . ($i + 1)) ?><?php endforeach; ?>
                <?php if (trim((string) ($item['explanation'] ?? '')) !== ''): ?>
                    <div class="explanation"><div class="section-heading">Explanation</div><?php foreach (QuestionView::paragraphs((string) $item['explanation']) as $para): ?><p><?= $para ?></p><?php endforeach; ?></div>
                <?php endif; ?>
                <?php $comments = QuestionView::comments($item); if ($comments !== []): ?>
                    <details class="discussion">
                        <summary><?= icon('chevron-right') ?><?= icon('chat-left-text') ?>Discussion (<?= count($comments) ?>)</summary>
                        <div class="discussion-body">
                            <?php $top = max(array_map(static fn($c) => (int) $c['upvotes'], $comments)); ?>
                            <?php foreach ($comments as $c): $isTop = $top > 0 && (int) $c['upvotes'] === $top; ?>
                                <div class="comment<?= $c['reply'] ? ' is-reply' : '' ?><?= $isTop ? ' is-top' : '' ?>">
                                    <?php if ($c['author'] !== ''): ?><span class="comment-avatar" aria-hidden="true"><?= e(initials($c['author'])) ?></span><?php endif; ?>
                                    <div class="comment-main">
                                        <?php if ($c['author'] !== '' || $c['timestamp'] !== ''): ?>
                                            <div class="comment-head">
                                                <?php if ($c['author'] !== ''): ?><span class="comment-author"><?= e($c['author']) ?></span><?php endif; ?>
                                                <?php if ($c['badge'] === 'Highly Voted'): ?><?= chip('highly voted', 'warning', 'star-fill') ?><?php endif; ?>
                                                <?php if ($c['timestamp'] !== ''): ?><span class="comment-time"><?= e($c['timestamp']) ?></span><?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="comment-body"><?php foreach (QuestionView::paragraphs($c['content']) as $para): ?><p><?= $para ?></p><?php endforeach; ?></div>
                                        <?php if ($c['selected'] !== '' || $c['upvotes'] !== null): ?>
                                            <div class="comment-foot">
                                                <?php if ($c['selected'] !== ''): ?><?= chip('selected ' . $c['selected'], 'primary', 'check2') ?><?php endif; ?>
                                                <?php if ($c['upvotes'] !== null): ?><span class="comment-votes" title="Upvotes"><?= icon('hand-thumbs-up') ?><?= (int) $c['upvotes'] ?></span><?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (preg_match('#^https://#', (string) ($item['source_url'] ?? '')) === 1): ?>
                <div class="q-foot"><a class="small" href="<?= e($item['source_url']) ?>" rel="noopener noreferrer" target="_blank">Source discussion <?= icon('box-arrow-up-right') ?></a></div>
            <?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<nav aria-label="Question pages" class="mt-4">
    <ul class="pagination flex-wrap">
        <?php $window = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], static fn($p) => $p >= 1 && $p <= $pages)); sort($window); $prev = 0; ?>
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url($base . '/view', $params + ['page' => max(1, $page - 1)])) ?>"><?= icon('chevron-left') ?> Previous</a></li>
        <?php foreach ($window as $p): ?>
            <?php if ($prev && $p > $prev + 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(url($base . '/view', $params + ['page' => $p])) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= $p ?></a></li>
            <?php $prev = $p; endforeach; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url($base . '/view', $params + ['page' => min($pages, $page + 1)])) ?>">Next <?= icon('chevron-right') ?></a></li>
    </ul>
</nav>
<?php endif; ?>
</div>
