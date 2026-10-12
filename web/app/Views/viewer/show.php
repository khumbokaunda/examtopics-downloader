<?php
/** @var array $provider @var array $exam @var array $version @var list<array> $questions @var string $mode
 *  @var string $q @var int $page @var int $pages @var int $total @var int $all */
use App\Services\Documents\QuestionView;

$base = '/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']);
$params = ['version' => $version['version'], 'mode' => $mode === 'practice' ? 'practice' : '', 'q' => $q];
$practice = $mode === 'practice';
$img = static function (string $url, string $alt): string {
    $safe = QuestionView::safeImageUrl($url);
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
        <p class="page-subtitle"><?= $total ?> of <?= $all ?> questions · version <?= e(fmt_time($version['collected_at'])) ?></p>
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
    <div class="search-field"><?= icon('search') ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search question and answer-choice text" aria-label="Search questions"></div>
    <button class="btn btn-outline-secondary" type="submit">Search</button>
    <?php if ($q === ''): ?>
        <input class="form-control jump-input" type="number" min="1" max="<?= (int) $all ?>" name="jump" placeholder="Go to #" aria-label="Go to question number">
    <?php else: ?>
        <a class="btn btn-link" href="<?= e(url($base . '/view', ['version' => $version['version'], 'mode' => $practice ? 'practice' : ''])) ?>">Clear search</a>
    <?php endif; ?>
</form>

<?php if ($questions === []): ?>
    <div class="card"><div class="card-body"><?= empty_state('search', 'No questions match', 'Search looks in question and answer-choice text. Try fewer words.', null, true) ?></div></div>
<?php endif; ?>

<?php foreach ($questions as $item): $n = (int) $item['number'];
    $answerLetters = (!$practice && preg_match('/^[A-Z]+$/', trim((string) ($item['answer'] ?? ''))) === 1) ? str_split(trim((string) $item['answer'])) : []; ?>
    <article class="card question-card" id="question-<?= $n ?>">
        <div class="card-body">
            <div class="q-head">
                <h2 class="q-number"><span class="num">Question <?= $n ?></span></h2>
                <span class="q-meta"><?= e(QuestionView::meta($item)) ?></span>
            </div>
            <div class="q-text"><?= nl2br(e(trim((string) ($item['text'] ?? ''))), false) ?></div>
            <?php foreach ((array) ($item['question_images'] ?? []) as $i => $u): ?><?= $img((string) $u, 'Question image ' . ($i + 1)) ?><?php endforeach; ?>
            <?php if (($item['choices'] ?? []) !== []): ?>
                <ol class="choices">
                    <?php foreach ((array) $item['choices'] as $c): $letter = (string) ($c['letter'] ?? ''); ?>
                        <li class="choice<?= $letter !== '' && in_array($letter, $answerLetters, true) ? ' is-answer' : '' ?>"><span class="choice-letter"><?= e($letter) ?></span><span><?= nl2br(e((string) ($c['text'] ?? '')), false) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if ($practice): ?>
                <!-- Practice mode: nothing answer-related is in this page. It is fetched on demand. -->
                <button class="btn btn-sm btn-outline-success" type="button" aria-expanded="false"
                        data-reveal-url="<?= e(url('/api/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']) . '/' . rawurlencode($version['version']) . '/questions/' . $n)) ?>"><?= icon('eye') ?><span class="label">Reveal answer</span></button>
                <div class="reveal-target" hidden></div>
            <?php else: ?>
                <?php $answer = QuestionView::answerLine($item); $votes = QuestionView::votesLine($item); ?>
                <div class="answer-box">
                    <?= icon('check-circle-fill') ?>
                    <div>
                        <div class="answer-text"><strong>Answer:</strong> <?= $answer !== null ? e($answer) : '<span class="text-muted">not available</span>' ?></div>
                        <?php if ($votes !== null): ?><div class="answer-votes">Community votes: <?= e($votes) ?></div><?php endif; ?>
                    </div>
                </div>
                <?php foreach ((array) ($item['answer_images'] ?? []) as $i => $u): ?><?= $img((string) $u, 'Answer image ' . ($i + 1)) ?><?php endforeach; ?>
                <?php if (trim((string) ($item['explanation'] ?? '')) !== ''): ?>
                    <div class="explanation"><div class="section-heading">Explanation</div><?= nl2br(e(trim((string) $item['explanation'])), false) ?></div>
                <?php endif; ?>
                <?php $comments = QuestionView::comments($item); if ($comments !== []): ?>
                    <details class="discussion">
                        <summary><?= icon('chevron-right') ?><?= icon('chat-left-text') ?>Discussion (<?= count($comments) ?>)</summary>
                        <div class="discussion-body">
                            <?php foreach ($comments as $c): ?>
                                <div class="comment">
                                    <?php if ($c['author'] !== '' || $c['timestamp'] !== '' || $c['upvotes'] !== null): ?>
                                        <div class="comment-head"><?= $c['author'] !== '' ? '<strong>' . e($c['author']) . '</strong>' : '' ?><?= $c['timestamp'] !== '' ? '<span>' . e($c['timestamp']) . '</span>' : '' ?><?= $c['upvotes'] !== null ? '<span>' . (int) $c['upvotes'] . ' upvotes</span>' : '' ?></div>
                                    <?php endif; ?>
                                    <div><?= nl2br(e($c['content']), false) ?></div>
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
