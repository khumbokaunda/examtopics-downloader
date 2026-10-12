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
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= e(url('/library')) ?>">Library</a></li>
    <li class="breadcrumb-item"><a href="<?= e(url($base, ['version' => $version['version']])) ?>"><?= e(strtoupper($exam['code'])) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= $practice ? 'Practice' : 'Read' ?></li>
</ol></nav>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0"><?= e(strtoupper($exam['code'])) ?> <span class="text-muted fw-normal fs-6">· <?= $total ?> of <?= $all ?> questions · version <?= e(fmt_time($version['collected_at'])) ?></span></h1>
        <?php if ($version['status'] === 'partial'): ?><div class="small text-warning-emphasis">Partial version: some questions could not be collected.</div><?php endif; ?>
    </div>
    <div class="btn-group" role="group" aria-label="Mode">
        <a class="btn btn-sm <?= $practice ? 'btn-outline-primary' : 'btn-primary' ?>" href="<?= e(url($base . '/view', ['version' => $version['version'], 'q' => $q])) ?>">Study</a>
        <a class="btn btn-sm <?= $practice ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url($base . '/view', ['version' => $version['version'], 'mode' => 'practice', 'q' => $q])) ?>">Practice</a>
    </div>
</div>

<form class="row g-2 mb-3" method="get" action="<?= e(url($base . '/view')) ?>">
    <input type="hidden" name="version" value="<?= e($version['version']) ?>">
    <?php if ($practice): ?><input type="hidden" name="mode" value="practice"><?php endif; ?>
    <div class="col"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search question and answer-choice text" aria-label="Search questions"></div>
    <div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Search</button></div>
    <?php if ($q === ''): ?>
    <div class="col-auto"><input class="form-control jump-input" type="number" min="1" max="<?= (int) $all ?>" name="jump" placeholder="Go to #" aria-label="Go to question number"></div>
    <?php endif; ?>
</form>

<?php if ($questions === []): ?>
    <div class="card shadow-sm"><div class="card-body text-muted">No questions match.</div></div>
<?php endif; ?>

<?php foreach ($questions as $item): $n = (int) $item['number']; ?>
    <article class="card shadow-sm mb-3 question-card" id="question-<?= $n ?>">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-baseline mb-2">
                <h2 class="h6 mb-0">Question <?= $n ?></h2>
                <span class="small text-muted"><?= e(QuestionView::meta($item)) ?></span>
            </div>
            <div class="q-text mb-2"><?= nl2br(e(trim((string) ($item['text'] ?? ''))), false) ?></div>
            <?php foreach ((array) ($item['question_images'] ?? []) as $i => $u): ?><?= $img((string) $u, 'Question image ' . ($i + 1)) ?><?php endforeach; ?>
            <?php if (($item['choices'] ?? []) !== []): ?>
                <ol class="choices list-unstyled mb-2">
                    <?php foreach ((array) $item['choices'] as $c): ?>
                        <li class="choice"><span class="choice-letter"><?= e($c['letter'] ?? '') ?></span><span><?= nl2br(e((string) ($c['text'] ?? '')), false) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if ($practice): ?>
                <!-- Practice mode: nothing answer-related is in this page. It is fetched on demand. -->
                <button class="btn btn-sm btn-outline-success" type="button"
                        data-reveal-url="<?= e(url('/api/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']) . '/' . rawurlencode($version['version']) . '/questions/' . $n)) ?>">Reveal answer</button>
                <div class="reveal-target mt-2" hidden></div>
            <?php else: ?>
                <?php $answer = QuestionView::answerLine($item); $votes = QuestionView::votesLine($item); ?>
                <div class="answer-box">
                    <strong>Answer:</strong> <?= $answer !== null ? e($answer) : '<span class="text-muted">not available</span>' ?>
                    <?php if ($votes !== null): ?><div class="small text-muted">Community votes: <?= e($votes) ?></div><?php endif; ?>
                </div>
                <?php foreach ((array) ($item['answer_images'] ?? []) as $i => $u): ?><?= $img((string) $u, 'Answer image ' . ($i + 1)) ?><?php endforeach; ?>
                <?php if (trim((string) ($item['explanation'] ?? '')) !== ''): ?>
                    <div class="explanation mt-2"><div class="small text-uppercase fw-semibold text-muted">Explanation</div><?= nl2br(e(trim((string) $item['explanation'])), false) ?></div>
                <?php endif; ?>
                <?php $comments = QuestionView::comments($item); if ($comments !== []): ?>
                    <details class="mt-2">
                        <summary class="small fw-semibold">Discussion (<?= count($comments) ?>)</summary>
                        <?php foreach ($comments as $c): ?>
                            <div class="comment">
                                <?php if ($c['author'] !== '' || $c['timestamp'] !== '' || $c['upvotes'] !== null): ?>
                                    <div class="small text-muted"><?= $c['author'] !== '' ? '<strong>' . e($c['author']) . '</strong>' : '' ?><?= $c['timestamp'] !== '' ? ' · ' . e($c['timestamp']) : '' ?><?= $c['upvotes'] !== null ? ' · ' . (int) $c['upvotes'] . ' upvotes' : '' ?></div>
                                <?php endif; ?>
                                <div><?= nl2br(e($c['content']), false) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (preg_match('#^https://#', (string) ($item['source_url'] ?? '')) === 1): ?>
                <div class="mt-2"><a class="small" href="<?= e($item['source_url']) ?>" rel="noopener noreferrer" target="_blank">Source discussion ↗</a></div>
            <?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<nav aria-label="Question pages" class="mt-3">
    <ul class="pagination flex-wrap">
        <?php $window = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], static fn($p) => $p >= 1 && $p <= $pages)); sort($window); $prev = 0; ?>
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url($base . '/view', $params + ['page' => max(1, $page - 1)])) ?>">Previous</a></li>
        <?php foreach ($window as $p): ?>
            <?php if ($prev && $p > $prev + 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(url($base . '/view', $params + ['page' => $p])) ?>"><?= $p ?></a></li>
            <?php $prev = $p; endforeach; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url($base . '/view', $params + ['page' => min($pages, $page + 1)])) ?>">Next</a></li>
    </ul>
</nav>
<?php endif; ?>
