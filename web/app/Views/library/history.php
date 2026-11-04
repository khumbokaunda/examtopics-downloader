<?php
/**
 * Every change to one question's corrections, newest first: who changed what, from what to
 * what, and a way to put back how it was before any of them.
 *
 * @var array $provider @var array $exam @var array $version @var string $key
 * @var array|null $question the question in this version, corrections applied @var list<array> $entries
 */
use App\Services\Access;
use App\Services\Editions;

$base = '/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']);
$n = $question !== null ? (int) $question['number'] : null;
// What a state was, in words.
$describe = static function (string $kind, ?array $s): string {
    if ($s === null) {
        return $kind === 'answer' ? 'No correction' : 'As collected';
    }
    if ($kind === 'answer') {
        $note = trim((string) ($s['note'] ?? ''));
        return ($s['answer'] !== '' ? 'Answer ' . $s['answer'] : 'No answer') . ($note !== '' ? ', note: "' . mb_strimwidth($note, 0, 80, '…') . '"' : '');
    }
    $parts = [];
    if ($s['text'] !== null) {
        $parts[] = 'wording: "' . mb_strimwidth((string) preg_replace('/\s+/', ' ', str_replace('//IMG//', ' [picture] ', $s['text'])), 0, 90, '…') . '"';
    }
    if ($s['choices'] !== null) {
        $parts[] = 'choices ' . implode(', ', array_keys($s['choices']));
    }
    if ($s['question_images'] !== null) {
        $parts[] = count($s['question_images']) . ' question ' . (count($s['question_images']) === 1 ? 'picture' : 'pictures');
    }
    if ($s['answer_images'] !== null) {
        $parts[] = count($s['answer_images']) . ' answer ' . (count($s['answer_images']) === 1 ? 'picture' : 'pictures');
    }
    return 'Edited: ' . implode('; ', $parts);
};
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/library')) ?>">Library</a></li>
    <li class="breadcrumb-item"><a href="<?= e(url($base, ['version' => $version['version']])) ?>"><?= e(Editions::shortCode((string) $exam['code'])) ?></a></li>
    <li class="breadcrumb-item"><a href="<?= e(url($base . '/corrections', ['version' => $version['version']])) ?>">Corrections</a></li>
    <li class="breadcrumb-item active" aria-current="page">History</li>
</ol></nav>

<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= $n !== null ? 'Question ' . $n . ': history' : 'History' ?></h1>
        <p class="page-subtitle"><?= $question !== null ? e(mb_strimwidth((string) preg_replace('/\s+/', ' ', (string) $question['text']), 0, 160, '…')) : 'This question is not in the version shown.' ?></p>
    </div>
    <?php if ($n !== null): ?>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= e(url($base . '/view', ['version' => $version['version'], 'jump' => (string) $n]) . '#question-' . $n) ?>"><?= icon('book') ?>Open in the reader</a>
    </div>
    <?php endif; ?>
</header>

<div class="card">
    <?php if ($entries === []): ?>
        <div class="card-body"><?= empty_state('clock-history', 'No changes yet', 'Corrections to this question, and who made them, are listed here.', null, true) ?></div>
    <?php else: ?>
    <ul class="list-rows history-list">
        <?php foreach ($entries as $i => $h): $who = $h['user_id'] !== null ? Access::displayName($h) : 'Someone no longer here'; ?>
            <li>
                <div class="row-main">
                    <span class="icon-bubble" data-tone="<?= $h['kind'] === 'answer' ? 'primary' : 'info' ?>"><?= icon($h['kind'] === 'answer' ? 'check2-square' : 'pencil') ?></span>
                    <div class="row-text">
                        <div class="row-title"><?= $h['kind'] === 'answer' ? 'Answer' : 'Question' ?> · <?= e($who) ?> <span class="text-muted fw-normal" title="<?= e(fmt_time((string) $h['created_at'])) ?>"><?= e(fmt_ago((string) $h['created_at'])) ?></span></div>
                        <div class="row-meta history-change"><span class="history-from"><?= e($describe($h['kind'], $h['before_state'])) ?></span> <?= icon('arrow-right') ?> <span class="history-to"><?= e($describe($h['kind'], $h['after_state'])) ?></span></div>
                    </div>
                </div>
                <form method="post" action="<?= e(url($base . '/corrections/history')) ?>" data-confirm="<?= e('It becomes: ' . $describe($h['kind'], $h['before_state']) . '. This is recorded too, so it can be undone.') ?>" data-confirm-title="<?= $i === 0 ? 'Step back one edit?' : 'Put back how it was before this?' ?>" data-confirm-button="Put back">
                    <?= csrf_field() ?>
                    <input type="hidden" name="version" value="<?= e($version['version']) ?>">
                    <input type="hidden" name="entry" value="<?= (int) $h['id'] ?>">
                    <button class="btn btn-sm <?= $i === 0 ? 'btn-outline-primary' : 'btn-link' ?>" type="submit"><?= icon('arrow-counterclockwise') ?><?= $i === 0 ? 'Step back one edit' : 'Put back how it was before' ?></button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
