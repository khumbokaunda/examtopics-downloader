<?php
/**
 * One card on a library shelf: an exam with its other editions, the person's progress in it,
 * and a button to save it to their own shelf. On "Continue studying" the card leads with where
 * they were.
 *
 * @var list<array> $editions the exam first, then its other editions
 * @var array{name:string,slug:string} $shelf
 * @var array<int,array{answered:int,correct:int,mistakes:int,last_at:?string}> $progress by exam id
 * @var array<int,string> $saved exam id => when saved
 * @var array{mode:string,page:int,updated_at:string}|null $place set on "Continue studying"
 * @var string $back the page to return to after saving (a path in this site)
 */
use App\Services\Editions;
use App\Services\StudyProgress;

$place ??= null;
$r = $editions[0];
$b = $r['best'];
$path = '/library/' . rawurlencode((string) $r['provider_slug']) . '/' . rawurlencode((string) $r['code']);
$label = Editions::codeLabel($r);
$questions = (int) ($b['collected_count'] ?? 0);
$mine = $progress[(int) $r['exam_id']] ?? null;
$isSaved = isset($saved[(int) $r['exam_id']]);
$haystack = [$shelf['name'], $shelf['slug']];
foreach ($editions as $ed) {
    array_push($haystack, (string) $ed['code'], Editions::shortCode((string) $ed['code']), (string) ($ed['name'] ?? ''));
}
?>
<article class="card exam-card" data-shelf-card data-count="<?= count($editions) ?>" data-search="<?= e(mb_strtolower(implode(' ', $haystack))) ?>">
    <div class="exam-card-body">
        <div class="exam-card-head">
            <h3 class="exam-card-title text-clamp-2"><a href="<?= e(url($path)) ?>"><?= e(Editions::title($r)) ?></a></h3>
            <form class="save-toggle" method="post" action="<?= e(url($path . '/save')) ?>" data-save-toggle><?= csrf_field() ?>
                <input type="hidden" name="back" value="<?= e($back) ?>">
                <button class="icon-btn<?= $isSaved ? ' is-saved' : '' ?>" type="submit" aria-pressed="<?= $isSaved ? 'true' : 'false' ?>"
                        title="<?= $isSaved ? 'Saved. Remove from your shelf' : 'Save to your shelf' ?>" aria-label="Save to your shelf"><?= icon($isSaved ? 'bookmark-fill' : 'bookmark') ?></button>
            </form>
        </div>
        <div class="exam-card-meta">
            <?php if ($label !== null): ?><span class="code-tag" title="<?= e(strtoupper((string) $r['code'])) ?>"><?= e($label) ?></span><?php endif; ?>
            <?php if ($place !== null): ?>
                <span><?= $place['mode'] === 'practice' ? 'Practice' : 'Study' ?>, page <?= (int) $place['page'] ?></span>
                <span><?= e(fmt_ago($place['updated_at'])) ?></span>
            <?php else: ?>
                <span><?= number_format($questions) ?> questions</span>
                <span>Updated <?= e(fmt_ago($b['collected_at'] ?? null)) ?></span>
            <?php endif; ?>
            <?php if ($r['cache_status'] !== 'complete'): ?><?= chip('Partial', 'warning', 'exclamation-triangle') ?><?php endif; ?>
            <?php if ($r['active_job_uuid']): ?><?= chip('Refreshing', 'primary', 'arrow-repeat') ?><?php endif; ?>
        </div>
        <?php if ($mine !== null && $mine['answered'] > 0): ?>
            <div class="exam-card-progress">
                <progress class="study-meter" max="<?= max(1, $questions) ?>" value="<?= min($mine['answered'], max(1, $questions)) ?>" aria-label="Questions answered"></progress>
                <span><?= number_format($questions > 0 ? min($mine['answered'], $questions) : $mine['answered']) ?> of <?= number_format($questions) ?> answered, <?= (int) StudyProgress::percent($mine['correct'], $mine['answered']) ?>% right</span>
            </div>
        <?php endif; ?>
        <?php if (count($editions) > 1): ?>
            <div class="exam-card-editions"><span class="text-muted">Other editions:</span>
                <?php foreach (array_slice($editions, 1) as $ed): ?>
                    <a class="edition-link" href="<?= e(url('/library/' . rawurlencode((string) $ed['provider_slug']) . '/' . rawurlencode((string) $ed['code']))) ?>" title="<?= e(Editions::title($ed)) ?>"><?= e(Editions::shortCode((string) $ed['code'])) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="exam-card-foot">
        <?php if ($place !== null): ?>
            <a class="btn btn-sm btn-primary" href="<?= e(url($path . '/view', ['mode' => $place['mode'] === 'practice' ? 'practice' : '', 'page' => (string) $place['page']])) ?>"><?= icon('play-fill') ?>Continue</a>
            <?php if ($mine !== null && $mine['mistakes'] > 0): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($path . '/view', ['mode' => 'practice', 'mistakes' => '1'])) ?>" title="The questions you last answered wrong"><?= icon('arrow-repeat') ?>Mistakes (<?= (int) $mine['mistakes'] ?>)</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($path . '/view', ['mode' => $place['mode'] === 'practice' ? '' : 'practice'])) ?>"><?= icon($place['mode'] === 'practice' ? 'book' : 'patch-question') ?><?= $place['mode'] === 'practice' ? 'Read' : 'Practice' ?></a>
            <?php endif; ?>
        <?php else: ?>
            <a class="btn btn-sm btn-primary" href="<?= e(url($path . '/view')) ?>"><?= icon('book') ?>Read</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($path . '/view', ['mode' => 'practice'])) ?>"><?= icon('patch-question') ?>Practice</a>
        <?php endif; ?>
        <?php if ($place === null): ?><a class="btn btn-sm btn-link ms-auto" href="<?= e(url($path)) ?>" title="Documents, versions and details">Documents</a><?php endif; ?>
    </div>
</article>
