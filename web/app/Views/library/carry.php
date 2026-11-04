<?php
/**
 * Carry an exam's corrections to its other editions: each corrected question, where it is in
 * each other edition, and what carrying it would do. Nothing changes until it is chosen.
 *
 * @var array $provider @var array $exam @var array $version @var list<array> $others
 * @var list<array{exam:array,items:list<array>,error:?string}> $plan
 */
use App\Services\EditionCorrections;
use App\Services\Editions;

$base = '/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']);
$tones = ['apply' => 'success', 'conflict' => 'warning', 'same' => 'neutral', 'unmappable' => 'neutral', 'different' => 'neutral', 'none' => 'neutral'];
$carryable = 0;
foreach ($plan as $edition) {
    foreach ($edition['items'] as $item) {
        $carryable += in_array($item['status'], ['apply', 'conflict'], true) ? 1 : 0;
    }
}
$n = 0;
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/library')) ?>">Library</a></li>
    <li class="breadcrumb-item"><a href="<?= e(url($base, ['version' => $version['version']])) ?>"><?= e(Editions::shortCode((string) $exam['code'])) ?></a></li>
    <li class="breadcrumb-item"><a href="<?= e(url($base . '/corrections', ['version' => $version['version']])) ?>">Corrections</a></li>
    <li class="breadcrumb-item active" aria-current="page">Other editions</li>
</ol></nav>

<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Carry corrections to other editions</h1>
        <p class="page-subtitle">The same questions in <?= e(implode(', ', array_map(static fn($o) => Editions::shortCode((string) $o['code']), $others)) ?: 'no other edition') ?>.
            An answer is carried by the wording of its choices, so a different choice order cannot misplace it. Nothing changes until you carry what is ticked.</p>
    </div>
</header>

<?php if ($plan === []): ?>
    <div class="card"><div class="card-body"><?= $others === []
        ? empty_state('collection', 'No other editions', 'This exam has no other editions in the library. Editions share a card when their names match, or when an administrator groups them on the exam page.', null, true)
        : empty_state('pencil-square', 'Nothing to carry', 'This exam has no corrections yet.', null, true) ?></div></div>
<?php else: ?>
<form method="post" action="<?= e(url($base . '/corrections/editions')) ?>" data-once data-busy="Carrying…">
    <?= csrf_field() ?>
    <input type="hidden" name="version" value="<?= e($version['version']) ?>">
    <?php foreach ($plan as $edition): $o = $edition['exam']; $there = '/library/' . rawurlencode((string) $o['provider_slug']) . '/' . rawurlencode((string) $o['code']); ?>
        <section class="card mb-4">
            <div class="card-header">
                <span class="card-title"><?= icon('collection') ?><a href="<?= e(url($there)) ?>"><?= e(Editions::shortCode((string) $o['code'])) ?></a></span>
                <span class="small text-muted"><?= e(Editions::title($o)) ?></span>
            </div>
            <?php if ($edition['error'] !== null): ?>
                <div class="card-note"><span class="card-note-text text-danger"><?= e($edition['error']) ?></span></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack carry-table">
                    <thead><tr><th class="carry-pick"><span class="visually-hidden">Carry</span></th><th>Here</th><th>There</th><th>Correction</th><th>What happens</th></tr></thead>
                    <tbody>
                    <?php foreach ($edition['items'] as $item): $i = $n++; $can = in_array($item['status'], ['apply', 'conflict'], true); ?>
                        <tr>
                            <td class="carry-pick">
                                <?php if ($can): ?><input class="form-check-input" type="checkbox" name="carry_<?= $i ?>" value="<?= e($item['id']) ?>" id="carry-<?= $i ?>"<?= $item['status'] === 'apply' ? ' checked' : '' ?> aria-label="Carry this correction"><?php endif; ?>
                            </td>
                            <td class="stack-title" data-label="Here"><a href="<?= e(url($base . '/view', ['version' => $version['version'], 'jump' => (string) $item['source_number']]) . '#question-' . $item['source_number']) ?>">Question <?= (int) $item['source_number'] ?></a></td>
                            <td data-label="There"><?php if ($item['target_number'] !== null): ?><a href="<?= e(url($there . '/view', ['jump' => (string) $item['target_number']]) . '#question-' . $item['target_number']) ?>">Question <?= (int) $item['target_number'] ?></a><?php if (!$item['exact']): ?> <span class="text-muted small" title="Most of its words are the same">(nearly the same)</span><?php endif; ?><?php else: ?><span class="text-muted">none</span><?php endif; ?></td>
                            <td data-label="Correction"><?= e($item['what']) ?></td>
                            <td data-label="What happens"><?= chip(EditionCorrections::STATUSES[$item['status']], $tones[$item['status']]) ?><?php if ($item['theirs'] !== null): ?> <span class="small text-muted"><?= e($item['theirs']) ?><?= $item['status'] === 'conflict' ? '; tick to replace it' : '' ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <div class="d-flex flex-wrap align-items-center gap-3">
        <button class="btn btn-primary" type="submit"<?= $carryable === 0 ? ' disabled' : '' ?>><?= icon('collection') ?>Carry what is ticked</button>
        <a class="btn btn-link" href="<?= e(url($base . '/corrections', ['version' => $version['version']])) ?>">Back to corrections</a>
    </div>
</form>
<?php endif; ?>
