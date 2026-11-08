<?php /** @var array $institution @var array $membership @var string $tab @var array $assessment @var array $sitting @var list<array> $questions @var array<int,array> $responses */
use App\Controllers\InstitutionAssessmentController as C;
use App\Services\Access;
use App\Services\QuestionBanks;
use App\Services\Sittings;
$a = $assessment;
$s = $sitting;
$base = '/institutions/' . $institution['uuid'] . '/assessments/' . $a['uuid'];
$live = $s['status'] === 'in_progress';
// A choice key as the letter the candidate saw it under.
$letters = static function (string $order, string $keys): string {
    $out = [];
    foreach (str_split($keys) as $k) {
        $p = strpos($order, $k);
        $out[] = $p === false ? $k : chr(65 + $p);
    }
    sort($out);
    return implode(', ', $out);
};
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Assessments' => '/institutions/' . $institution['uuid'] . '/assessments', $a['title'] => $base, 'Results' => $base . '/results', Access::displayName($s) => null]]) ?>
<div class="card mb-4"><div class="card-body d-flex flex-wrap gap-4">
    <div><div class="small text-muted">Candidate</div><div class="fw-semibold"><?= e(Access::displayName($s)) ?><?= $s['student_number'] !== null ? ' · ' . e($s['student_number']) : '' ?></div></div>
    <div><div class="small text-muted">Attempt</div><div class="fw-semibold"><?= (int) $s['attempt'] ?></div></div>
    <div><div class="small text-muted">Status</div><div><?= chip(Sittings::STATUSES[$s['status']] ?? $s['status'], $live ? 'warning' : 'success') ?></div></div>
    <div><div class="small text-muted">Started</div><div><?= e(fmt_time((string) $s['started_at'])) ?></div></div>
    <?php if (!$live): ?>
    <div><div class="small text-muted">Handed in</div><div><?= e(fmt_time((string) $s['submitted_at'])) ?></div></div>
    <div><div class="small text-muted">Score</div><div class="fw-semibold"><?= e(C::marks($s['score'])) ?> / <?= e(C::marks($s['max_score'])) ?> (<?= e(C::marks($s['percent'])) ?>%)</div></div>
    <?php else: ?>
    <div><div class="small text-muted">Ends</div><div><?= e(fmt_time((string) $s['ends_at'])) ?></div></div>
    <?php endif; ?>
</div></div>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('list-check') ?>Answers</span><span class="small text-muted">Letters as this candidate saw them</span></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead><tr><th>#</th><th>Question</th><th>Answered</th><th>Right answer</th><th data-type="num">Marks</th></tr></thead>
            <tbody>
            <?php foreach ($questions as $q): $pos = (int) $q['position']; $r = $responses[$pos] ?? null;
                $right = implode('', array_map(static fn(array $c): string => (string) $c['key'], array_filter((array) json_decode((string) $q['choices'], true), static fn(array $c): bool => !empty($c['correct'])))); ?>
                <tr>
                    <td class="small text-muted" data-label="#"><?= $pos ?></td>
                    <td class="stack-title"><?= e(QuestionBanks::excerpt((string) $q['text'], 120)) ?><?= (int) ($r['flagged'] ?? 0) ? ' ' . chip('Marked', 'warning') : '' ?></td>
                    <td class="small" data-label="Answered"><?= ($r['answer'] ?? '') !== '' ? e($q['type'] === 'true_false' ? ($r['answer'] === 'T' ? 'True' : 'False') : $letters((string) $q['choice_order'], (string) $r['answer'])) : '<span class="text-muted">none</span>' ?></td>
                    <td class="small" data-label="Right answer"><?= e($q['type'] === 'true_false' ? ($right === 'T' ? 'True' : 'False') : $letters((string) $q['choice_order'], $right)) ?></td>
                    <td class="small" data-label="Marks"><?= $live ? '' : ((int) $q['correct'] ? icon('check-lg', 'text-success') : '') . ' ' . e(C::marks($q['awarded'])) . ' / ' . e(C::marks($q['marks'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
