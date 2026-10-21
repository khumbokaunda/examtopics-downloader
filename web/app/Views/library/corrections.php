<?php
/** @var array $provider @var array $exam @var array $version
 *  @var list<array{key:string,number:?int,text:string,kind:string,collected:?string,answer:string,note:string,updated_at:string}> $rows */
use App\Services\Editions;

$base = '/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']);
$counts = array_count_values(array_column($rows, 'kind'));
$summary = array_filter([
    ($counts['corrected'] ?? 0) > 0 ? $counts['corrected'] . ' corrected' : null,
    ($counts['checked'] ?? 0) > 0 ? $counts['checked'] . ' checked' : null,
    ($counts['note'] ?? 0) > 0 ? $counts['note'] . ' with a note only' : null,
]);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/library')) ?>">Library</a></li>
    <li class="breadcrumb-item"><a href="<?= e(url($base, ['version' => $version['version']])) ?>" title="<?= e(strtoupper((string) $exam['code'])) ?>"><?= e(Editions::shortCode((string) $exam['code'])) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page">Corrections</li>
</ol></nav>

<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Corrections</h1>
        <p class="page-subtitle"><?= $rows === [] ? 'No answers of ' . e(strtoupper($exam['code'])) . ' have been corrected or checked yet.'
            : e(implode(', ', $summary)) . '. They apply to every version of ' . e(strtoupper($exam['code'])) . ' and to documents made after them; the collected data is unchanged.' ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-warning" href="<?= e(url($base . '/view', ['version' => $version['version'], 'review' => '1'])) ?>"><?= icon('exclamation-triangle') ?>Answers to check</a>
        <a class="btn btn-outline-secondary" href="<?= e(url($base, ['version' => $version['version']])) ?>"><?= icon('arrow-left') ?>Back to the exam</a>
    </div>
</header>

<div class="card">
    <?php if ($rows === []): ?>
        <div class="card-body"><?= empty_state('pencil-square', 'Nothing corrected yet', 'Answers you correct or check in the reader are listed here, so you can review them in one place.') ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" id="corrections-table" data-table="corrections" data-order='[[0,"asc"]]'>
            <thead><tr>
                <th data-type="num">Question</th><th>Text</th><th>Collected</th><th>Saved</th><th>Note</th><th>Saved at</th>
                <th class="text-end" data-nosort data-novis data-nosearch data-export="false"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $n = $r['number']; ?>
                <tr>
                    <td class="stack-title" data-order="<?= $n ?? PHP_INT_MAX ?>" data-export="<?= $n ?? e($r['key']) ?>">
                        <?php if ($n !== null): ?>
                            <a class="table-row-link" href="<?= e(url($base . '/view', ['version' => $version['version'], 'jump' => (string) $n]) . '#question-' . $n) ?>">Question <?= $n ?></a>
                        <?php else: ?>
                            <span class="text-muted" title="This question is not in the version shown">Not in this version</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Text"><span class="truncate" title="<?= e($r['text']) ?>"><?= e(mb_substr(preg_replace('/\s+/', ' ', $r['text']) ?? '', 0, 140)) ?></span></td>
                    <td data-label="Collected" class="mono"><?= $r['collected'] !== null ? ($r['collected'] !== '' ? e($r['collected']) : '<span class="text-muted">none</span>') : '' ?></td>
                    <td data-label="Saved" data-order="<?= e($r['kind'] . $r['answer']) ?>">
                        <?php if ($r['kind'] === 'corrected'): ?><span class="mono fw-semibold"><?= e($r['answer']) ?></span> <?= chip('corrected', 'primary', 'pencil-square') ?>
                        <?php elseif ($r['kind'] === 'checked'): ?><span class="mono"><?= e($r['answer']) ?></span> <?= chip('checked', 'success', 'check2') ?>
                        <?php else: ?><?= chip('note only', 'neutral', 'sticky') ?><?php endif; ?>
                    </td>
                    <td data-label="Note"><span class="truncate" title="<?= e($r['note']) ?>"><?= e($r['note']) ?></span></td>
                    <td data-label="Saved at" data-order="<?= e($r['updated_at']) ?>"><?= e(fmt_time($r['updated_at'])) ?></td>
                    <td class="text-end stack-actions">
                        <form method="post" action="<?= e(url($base . '/answers')) ?>" data-confirm="The collected answer is shown again for this question." data-confirm-title="Remove this correction?" data-confirm-button="Remove" data-confirm-tone="danger">
                            <?= csrf_field() ?>
                            <input type="hidden" name="key" value="<?= e($r['key']) ?>">
                            <input type="hidden" name="version" value="<?= e($version['version']) ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="return" value="corrections">
                            <button class="btn btn-sm btn-link btn-link-danger" type="submit"><?= icon('arrow-counterclockwise') ?>Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
