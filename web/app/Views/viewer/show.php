<?php
/** @var array $provider @var array $exam @var array $version @var list<array> $questions @var string $mode
 *  @var string $q @var int $page @var int $pages @var int $total @var int $all @var array<string,string> $localImages
 *  @var bool $review @var int $toCheck @var bool $mistakes @var bool $reported @var bool $due @var int $dueCount
 *  @var array<string,int> $history recorded changes to each question's corrections (people who correct questions)
 *  @var bool $bookmarked @var int $bookmarks @var array<string,array{note:?string,bookmarked:bool}> $notes this person's, by question key
 *  @var array<string,list<array>> $reports open reports by question key (people who correct questions, study mode)
 *  @var array<string,list<array>> $discussion members' comments by question key (study mode)
 *  @var array{answered:int,correct:int,mistakes:int,last_at:?string} $progress this person's progress in the exam
 *  @var list<array> $objectives the exam's objectives @var array|null $objective the one shown, if any
 *  @var array<string,int> $objectiveOf objective id by question key @var array<string,true> $openReports questions with an open report */
use App\Services\Documents\QuestionView;
use App\Services\Editions;
use App\Services\Objectives;
use App\Services\StudyProgress;

$base = '/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']);
$editor = can('answers.edit');
// The filter in use: it carries over to the next page, the search, and every form on this one.
$filters = array_filter(['review' => $review, 'mistakes' => $mistakes, 'reported' => $reported, 'due' => $due, 'bookmarked' => $bookmarked]);
$filters = array_map(static fn() => '1', $filters);
$objectiveField = $objective !== null ? '<input type="hidden" name="objective" value="' . e((string) $objective['uuid']) . '">' : '';
$params = ['version' => $version['version'], 'mode' => $mode === 'practice' ? 'practice' : '', 'q' => $q] + $filters + ($objective !== null ? ['objective' => (string) $objective['uuid']] : []);
$filterFields = implode('', array_map(static fn(string $f) => '<input type="hidden" name="' . $f . '" value="1">', array_keys(array_intersect_key($filters, ['due' => 1, 'bookmarked' => 1])))) . $objectiveField;
$objectiveNames = array_column($objectives, 'name', 'id');
// Everything a form needs to come back to this page at its question.
$keep = static fn(array $item): string => csrf_field()
    . '<input type="hidden" name="key" value="' . e(QuestionView::key($item)) . '">'
    . '<input type="hidden" name="number" value="' . (int) $item['number'] . '">'
    . '<input type="hidden" name="version" value="' . e((string) $version['version']) . '">'
    . '<input type="hidden" name="page" value="' . (int) $page . '">'
    . '<input type="hidden" name="q" value="' . e($q) . '">'
    . ($mode === 'practice' ? '<input type="hidden" name="mode" value="practice">' : '')
    . implode('', array_map(static fn(string $f) => '<input type="hidden" name="' . $f . '" value="1">', array_keys($filters))) . $objectiveField;
$reportKinds = \App\Repositories\QuestionReportRepository::KINDS;
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
    <li class="breadcrumb-item"><a href="<?= e(url($base, ['version' => $version['version']])) ?>" title="<?= e(strtoupper((string) $exam['code'])) ?>"><?= e(Editions::shortCode((string) $exam['code'])) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= $practice ? 'Practice' : 'Study' ?></li>
</ol></nav>

<div class="reader">
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e(Editions::title($exam)) ?></h1>
        <p class="page-subtitle"><?= e($total . match (true) {
            $review => ' answers to check, of ',
            $reported => ' reported by members, of ',
            $mistakes => ' of your mistakes, of ',
            $due => ' due for review today, of ',
            $bookmarked => ' bookmarked, of ',
            $objective !== null => ' in ' . $objective['name'] . ', of ',
            default => ' of ',
        } . $all . ' questions') ?> · version <?= e(fmt_time($version['collected_at'])) ?></p>
        <?php if ($version['status'] === 'partial'): ?><div class="mt-2"><?= chip('Partial version: some questions could not be collected', 'warning', 'exclamation-triangle') ?></div><?php endif; ?>
    </div>
    <div class="page-actions">
        <nav class="segmented" aria-label="Mode">
            <a class="<?= $practice ? '' : 'is-active' ?>" href="<?= e(url($base . '/view', ['version' => $version['version'], 'q' => $q])) ?>"<?= $practice ? '' : ' aria-current="page"' ?>><?= icon('book') ?>Study</a>
            <a class="<?= $practice ? 'is-active' : '' ?>" href="<?= e(url($base . '/view', ['version' => $version['version'], 'mode' => 'practice', 'q' => $q])) ?>"<?= $practice ? ' aria-current="page"' : '' ?>><?= icon('patch-question') ?>Practice</a>
            <a href="<?= e(url($base . '/mock')) ?>"><?= icon('stopwatch') ?>Mock exam</a>
        </nav>
    </div>
</header>

<form class="toolbar reader-toolbar" method="get" action="<?= e(url($base . '/view')) ?>">
    <input type="hidden" name="version" value="<?= e($version['version']) ?>">
    <?php if ($practice): ?><input type="hidden" name="mode" value="practice"><?php endif; ?>
    <?php if ($review): ?><input type="hidden" name="review" value="1"><?php endif; ?>
    <?php if ($mistakes): ?><input type="hidden" name="mistakes" value="1"><?php endif; ?>
    <?php if ($reported): ?><input type="hidden" name="reported" value="1"><?php endif; ?>
    <?= $objectiveField !== '' ? str_replace($objectiveField, '', $filterFields) : $filterFields ?>
    <?php if ($objectives !== []): ?>
        <select class="form-select" name="objective" aria-label="Objective" data-autosubmit>
            <option value="">All objectives</option>
            <?php foreach ($objectives as $o): ?><option value="<?= e($o['uuid']) ?>"<?= $objective !== null && $objective['uuid'] === $o['uuid'] ? ' selected' : '' ?>><?= e(($o['code'] !== null ? $o['code'] . ' ' : '') . $o['name']) ?> (<?= (int) $o['question_count'] ?>)</option><?php endforeach; ?>
        </select>
    <?php endif; ?>
    <div class="search-field"><?= icon('search') ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search question and answer-choice text" aria-label="Search questions"></div>
    <button class="btn btn-outline-secondary" type="submit">Search</button>
    <?php if ($q === '' && $filters === [] && $objective === null): ?>
        <input class="form-control jump-input" type="number" min="1" max="<?= (int) $all ?>" name="jump" placeholder="Go to #" aria-label="Go to question number">
    <?php elseif ($q !== ''): ?>
        <a class="btn btn-link" href="<?= e(url($base . '/view', ['version' => $version['version'], 'mode' => $practice ? 'practice' : ''] + $filters + ($objective !== null ? ['objective' => $objective['uuid']] : []))) ?>">Clear search</a>
    <?php endif; ?>
    <?php if ($filters !== []): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url($base . '/view', ['version' => $version['version'], 'mode' => $practice ? 'practice' : ''])) ?>"><?= icon('list-ol') ?>All questions</a>
    <?php else: ?>
        <?php if ($practice && $dueCount > 0): ?>
            <a class="btn btn-outline-primary" href="<?= e(url($base . '/view', ['version' => $version['version'], 'mode' => 'practice', 'due' => '1'])) ?>" title="Questions you answered before that are due to be gone over again"><?= icon('calendar-check') ?>Due today (<?= $dueCount ?>)</a>
        <?php endif; ?>
        <?php if ($practice && $progress['mistakes'] > 0): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url($base . '/view', ['version' => $version['version'], 'mode' => 'practice', 'mistakes' => '1'])) ?>" title="The questions you last answered wrong"><?= icon('arrow-repeat') ?>My mistakes (<?= (int) $progress['mistakes'] ?>)</a>
        <?php endif; ?>
        <?php if ($bookmarks > 0): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url($base . '/view', ['version' => $version['version'], 'mode' => $practice ? 'practice' : '', 'bookmarked' => '1'])) ?>" title="The questions you bookmarked"><?= icon('bookmark') ?>Bookmarked (<?= $bookmarks ?>)</a>
        <?php endif; ?>
        <?php if ($toCheck > 0 && $editor && !$practice): ?>
            <a class="btn btn-outline-warning" href="<?= e(url($base . '/view', ['version' => $version['version'], 'review' => '1'])) ?>" title="Answers that look doubtful and that you have not corrected or checked yet"><?= icon('exclamation-triangle') ?>To check (<?= $toCheck ?>)</a>
        <?php endif; ?>
        <?php if ($reports !== []): ?>
            <a class="btn btn-outline-danger" href="<?= e(url($base . '/view', ['version' => $version['version'], 'reported' => '1'])) ?>" title="Questions members reported a problem with"><?= icon('flag') ?>Reported (<?= count($reports) ?>)</a>
        <?php endif; ?>
    <?php endif; ?>
</form>

<?php if ($questions === []): ?>
    <div class="card"><div class="card-body"><?= $review && $q === ''
        ? empty_state('check2-all', 'Nothing left to check', 'All doubtful answers are done.', null, true)
        : ($reported && $q === '' ? empty_state('check2-all', 'No reports waiting', 'Every problem members reported has been dealt with.', null, true)
        : ($due && $q === '' ? empty_state('calendar-check', 'Nothing due today', 'Come back tomorrow.', ['Practise all questions', url($base . '/view', ['mode' => 'practice'])], true)
        : ($bookmarked && $q === '' ? empty_state('bookmark', 'No bookmarked questions', 'Bookmark a question with the bookmark beside its number.', null, true)
        : ($mistakes && $q === ''
            ? empty_state('check2-all', 'No mistakes to go over', 'Every question you checked, you last answered right.', ['Practise all questions', url($base . '/view', ['mode' => 'practice'])], true)
            : empty_state('search', 'No questions match', 'Try fewer words.', null, true))))) ?></div></div>
<?php endif; ?>

<?php if ($practice && $questions !== []): ?>
    <div class="practice-bar" data-practice="<?= e($provider['slug'] . '/' . $exam['code'] . '/' . $version['version']) ?>"
         data-answers-url="<?= e(url('/api/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']) . '/' . rawurlencode($version['version']) . '/answers')) ?>">
        <span class="practice-score"><?= icon('ui-checks') ?><span data-field="score">Pick an answer, then reveal it to check yourself.</span></span>
        <span class="practice-progress" data-field="progress" data-all="<?= (int) $all ?>"><?= e(StudyProgress::line($progress, (int) $all)) ?></span>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-action="practice-clear-all" hidden><?= icon('arrow-counterclockwise') ?>Clear all my choices</button>
    </div>
<?php endif; ?>

<?php foreach ($questions as $item): $n = (int) $item['number'];
    $answerLetters = (!$practice && preg_match('/^[A-Z]+$/', trim((string) ($item['answer'] ?? ''))) === 1) ? str_split(trim((string) $item['answer'])) : []; ?>
    <article class="card question-card" id="question-<?= $n ?>"<?= $practice ? ' data-q="' . $n . '" data-picks="' . (int) ($item['picks'] ?? 1) . '" data-attempt-url="' . e(url('/api/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']) . '/' . rawurlencode($version['version']) . '/questions/' . $n . '/attempt')) . '"' : '' ?>>
        <div class="card-body">
            <div class="q-head">
                <h2 class="q-number"><span class="num">Question <?= $n ?></span><?php $kind = QuestionView::kind($item); if ($kind !== null): ?><?= chip($kind === 'drag-drop' ? 'Drag and drop' : 'Hot area', 'info', $kind === 'drag-drop' ? 'arrows-move' : 'cursor') ?><?php endif; ?><?php if (!$practice && isset($item['question_edit'])): ?><?= chip('edited', 'primary', 'pencil') ?><?php endif; ?></h2>
                <span class="q-tools">
                    <span class="q-meta"><?= e(QuestionView::meta($item)) ?></span>
                    <?php $qKey = QuestionView::key($item); $trust = Objectives::trust($item, isset($openReports[$qKey]));
                    if (isset($objectiveOf[$qKey], $objectiveNames[$objectiveOf[$qKey]])): ?><span class="q-objective small text-muted" title="Objective"><?= icon('bullseye') ?><?= e((string) $objectiveNames[$objectiveOf[$qKey]]) ?></span><?php endif; ?>
                    <?php if ($trust !== 'unreviewed'): [$tLabel, $tTone, $tIcon] = Objectives::TRUST[$trust]; ?><?= chip($tLabel, $tTone, $tIcon) ?><?php endif; ?>
                    <?php $mine = $notes[QuestionView::key($item)] ?? ['note' => null, 'bookmarked' => false]; ?>
                    <form method="post" action="<?= e(url($base . '/notes/bookmark')) ?>" data-save-toggle class="q-bookmark"><?= $keep($item) ?>
                        <button class="icon-btn<?= $mine['bookmarked'] ? ' is-saved' : '' ?>" type="submit" aria-pressed="<?= $mine['bookmarked'] ? 'true' : 'false' ?>"
                                title="<?= $mine['bookmarked'] ? 'Bookmarked. Take the bookmark off' : 'Bookmark this question' ?>" aria-label="Bookmark question <?= $n ?>"
                                data-on-title="Bookmarked. Take the bookmark off" data-off-title="Bookmark this question"><?= icon($mine['bookmarked'] ? 'bookmark-fill' : 'bookmark') ?></button>
                    </form>
                </span>
            </div>
            <?php $qImages = QuestionView::questionImages($item); foreach (QuestionView::questionSegments($item) as $seg): ?>
                <?php if (isset($seg['text'])): ?><div class="q-text"><?php foreach (QuestionView::paragraphs($seg['text']) as $para): ?><p><?= $para ?></p><?php endforeach; ?></div>
                <?php else: ?><?= $img($qImages[$seg['image']], 'Question image ' . ($seg['image'] + 1)) ?><?php endif; ?>
            <?php endforeach; ?>
            <?php if (($item['choices'] ?? []) !== []): ?>
                <ul class="list-unstyled choices"<?= $practice ? ' data-pickable aria-label="Your answer"' : '' ?>>
                    <?php foreach ((array) $item['choices'] as $c): $letter = (string) ($c['letter'] ?? ''); ?>
                        <li class="choice<?= $letter !== '' && in_array($letter, $answerLetters, true) ? ' is-answer' : '' ?>" data-letter="<?= e($letter) ?>"><span class="choice-letter"><?= e($letter) ?>.</span> <span class="choice-text"><?= nl2br(e((string) ($c['text'] ?? '')), false) ?><?php foreach ((array) ($c['images'] ?? []) as $u): ?><?= $img((string) $u, 'Choice ' . $letter) ?><?php endforeach; ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($mine['note'] !== null): ?>
                <div class="my-note"><span class="answer-note-label"><?= icon('lock') ?>Your note</span><?php foreach (QuestionView::paragraphs($mine['note']) as $para): ?><p><?= $para ?></p><?php endforeach; ?></div>
            <?php endif; ?>

            <?php if ($practice): ?>
                <!-- Practice mode: nothing answer-related is in this page. The page fetches the answers in the background. -->
                <div class="practice-actions">
                    <button class="btn btn-sm btn-outline-success" type="button" aria-expanded="false"
                            data-reveal-url="<?= e(url('/api/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']) . '/' . rawurlencode($version['version']) . '/questions/' . $n)) ?>"><?= icon('eye') ?><span class="label">Reveal answer</span></button>
                    <button class="btn btn-sm btn-link" type="button" data-action="practice-clear" hidden><?= icon('x-circle') ?>Clear my choice</button>
                    <?php if ((int) ($item['picks'] ?? 1) !== 1): ?><span class="small text-muted"><?= (int) $item['picks'] > 1 ? 'Pick ' . (int) $item['picks'] : 'Pick every answer that applies' ?></span><?php endif; ?>
                </div>
                <div class="reveal-target" aria-hidden="true"><div class="reveal-inner"></div></div>
            <?php else: ?>
                <?php $answer = QuestionView::answerLine($item); $votes = QuestionView::votesLine($item);
                $edit = $item['edit'] ?? null; $doubts = QuestionView::doubts($item); $letters = QuestionView::choiceLetters($item); ?>
                <div class="answer-box<?= ($edit['kind'] ?? '') === 'corrected' ? ' is-corrected' : '' ?>">
                    <?= icon(($edit['kind'] ?? '') === 'corrected' ? 'pencil-square' : 'check-circle-fill') ?>
                    <div>
                        <?php $note = QuestionView::pictureAnswerNote($item); ?>
                        <div class="answer-text"><strong>Answer:</strong> <?= $answer !== null ? e($answer) : '<span class="text-muted">' . e($note ?? 'not available') . '</span>' ?></div>
                        <?php if ($votes !== null): ?><div class="answer-votes">Community votes: <?= e($votes) ?></div><?php endif; ?>
                        <?php $memberVotes = QuestionView::memberVotesLine($item); if ($memberVotes !== null): ?><div class="answer-votes">Members' votes here: <?= e($memberVotes) ?></div><?php endif; ?>
                        <?php if (($edit['note'] ?? '') !== ''): ?><div class="answer-note"><span class="answer-note-label">Note</span><?php foreach (QuestionView::paragraphs($edit['note']) as $para): ?><p><?= $para ?></p><?php endforeach; ?></div><?php endif; ?>
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
                <?php $open = $reports[QuestionView::key($item)] ?? []; if ($open !== []): ?>
                    <div class="question-reports" role="note">
                        <?= icon('flag-fill') ?>
                        <div>
                            <div class="answer-check-title"><?= count($open) === 1 ? 'Reported by a member' : 'Reported by ' . count($open) . ' members' ?></div>
                            <ul class="question-report-list">
                                <?php foreach ($open as $r): ?>
                                    <li><span class="fw-medium"><?= e($reportKinds[$r['kind']] ?? 'Something else') ?>:</span> <?= e((string) $r['body']) ?>
                                        <span class="question-report-by"><?= e($r['user_id'] !== null ? \App\Services\Access::displayName($r) : 'A former member') ?>, <?= e(fmt_ago((string) $r['created_at'])) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                            <form method="post" action="<?= e(url($base . '/reports/resolve')) ?>" class="mt-2" data-once data-busy="Saving…">
                                <?= csrf_field() ?>
                                <input type="hidden" name="key" value="<?= e(QuestionView::key($item)) ?>">
                                <input type="hidden" name="version" value="<?= e($version['version']) ?>">
                                <input type="hidden" name="number" value="<?= $n ?>">
                                <input type="hidden" name="page" value="<?= (int) $page ?>">
                                <input type="hidden" name="q" value="<?= e($q) ?>">
                                <?php if ($reported): ?><input type="hidden" name="reported" value="1"><?php endif; ?>
                                <?= $filterFields ?>
                                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= icon('check2') ?>Mark dealt with</button>
                                <span class="small text-muted ms-2">Fix it with the editors below first. <?= count($open) === 1 ? 'Whoever reported it is' : 'Those who reported it are' ?> told.</span>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($editor): ?>
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
                            <?php if ($reported): ?><input type="hidden" name="reported" value="1"><?php endif; ?>
                            <?= $filterFields ?>
                            <?php if ($letters !== []): ?>
                                <fieldset class="mb-3">
                                    <legend class="form-label">Correct answer</legend>
                                    <div class="letter-picks">
                                        <?php foreach ($letters as $l): ?>
                                            <label class="letter-pick"><input type="checkbox" name="letter_<?= e($l) ?>" value="1"<?= in_array($l, $answerLetters, true) ? ' checked' : '' ?>><span><?= e($l) ?></span></label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="form-text">Tick every correct letter.</div>
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
                                <?php if ($reported): ?><input type="hidden" name="reported" value="1"><?php endif; ?>
                                <?= $filterFields ?>
                            <?php if ($reported): ?><input type="hidden" name="reported" value="1"><?php endif; ?>
                            <?= $filterFields ?>
                                <input type="hidden" name="action" value="remove">
                                <button class="btn btn-sm btn-link btn-link-danger" type="submit"><?= icon('arrow-counterclockwise') ?>Remove my correction<?= ($edit['collected'] ?? '') !== '' ? ' (back to ' . e($edit['collected']) . ')' : '' ?></button>
                            </form>
                        <?php endif; ?>
                        <?php $changes = $history[QuestionView::key($item)] ?? 0; if ($changes > 0): ?>
                            <a class="small d-inline-block mt-2" href="<?= e(url($base . '/corrections/history', ['version' => $version['version'], 'key' => QuestionView::key($item)])) ?>"><?= icon('clock-history') ?> History of this question's corrections (<?= $changes ?>)</a>
                        <?php endif; ?>
                    </div>
                </details>
                <?= \App\Core\View::current()->partial('viewer/question_edit', ['item' => $item, 'base' => $base, 'version' => $version,
                    'page' => $page, 'q' => $q, 'review' => $review, 'reported' => $reported, 'localImages' => $localImages, 'filterFields' => $filterFields]) ?>
                <?php endif; ?>
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
                <?= \App\Core\View::current()->partial('viewer/discussion', ['item' => $item, 'comments' => $discussion[QuestionView::key($item)] ?? [],
                    'base' => $base, 'version' => $version, 'page' => $page, 'q' => $q, 'review' => $review, 'reported' => $reported, 'filterFields' => $filterFields]) ?>
            <?php endif; ?>
            <div class="q-foot">
                <details class="report-problem my-note-edit">
                    <summary><?= icon('journal-text') ?><?= $mine['note'] !== null ? 'Change your note' : 'Add a note' ?></summary>
                    <form method="post" action="<?= e(url($base . '/notes')) ?>" class="report-problem-body" data-once data-busy="Saving…"><?= $keep($item) ?>
                        <label class="form-label" for="my-note-<?= $n ?>">Your note <span class="text-muted fw-normal">(only you can see it)</span></label>
                        <textarea class="form-control" id="my-note-<?= $n ?>" name="note" rows="3" maxlength="5000"><?= e((string) $mine['note']) ?></textarea>
                        <div class="form-text">Leave it empty and save to remove it.</div>
                        <div class="post-actions"><button class="btn btn-sm btn-primary" type="submit"><?= icon('check2') ?>Save</button></div>
                    </form>
                </details>
                <?= \App\Core\View::current()->partial('viewer/report', ['item' => $item, 'base' => $base, 'version' => $version, 'page' => $page,
                    'q' => $q, 'mode' => $mode, 'review' => $review, 'mistakes' => $mistakes, 'filterFields' => $filterFields]) ?>
                <?php if (preg_match('#^https://#', (string) ($item['source_url'] ?? '')) === 1): ?>
                    <a class="small" href="<?= e($item['source_url']) ?>" rel="noopener noreferrer" target="_blank">Source discussion <?= icon('box-arrow-up-right') ?></a>
                <?php endif; ?>
            </div>
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
