<?php /** @var array $institution @var array $membership @var string $tab @var array $assessment @var array $sitting @var list<array> $questions @var array<int,array> $responses @var list<array> $topics @var bool $canExport
 *  @var array|null $integrity @var bool $canReview @var list<array> $observations */
use App\Controllers\InstitutionAssessmentController as C;
use App\Services\Integrity;
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
    <?php if ($canExport && !$live): ?><div class="ms-auto"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/results/' . $s['uuid'] . '/report.pdf')) ?>"><?= icon('file-earmark-pdf') ?>Report as PDF</a></div><?php endif; ?>
</div></div>
<?php if ($topics !== []): ?><div class="mb-4"><?= \App\Core\View::current()->partial('sitting/_topics', ['topics' => $topics]) ?></div><?php endif; ?>
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
<?php if ($integrity !== null): ?>
<div class="card mt-4" id="integrity">
    <div class="card-header"><span class="card-title"><?= icon('shield-check') ?>Integrity</span><span class="small text-muted">Monitoring: <?= e((Integrity::POLICIES[$s['monitoring']] ?? Integrity::POLICIES['basic'])['name']) ?></span></div>
    <div class="card-body">
        <p class="small text-muted">Observations, then indicators worked out from them by fixed rules, then people's conclusions. An observation has many innocent causes; nothing here is a finding by itself.</p>
        <h2 class="section-heading">Indicators</h2>
        <?php if ($integrity['indicators'] === []): ?><p class="small">Nothing to note.</p><?php else: ?>
        <ul class="list-unstyled small">
            <?php foreach ($integrity['indicators'] as $ind): ?><li class="mb-1"><?= match ($ind['level']) { 'review' => chip('Worth a look', 'warning'), 'technical' => chip('Technical', 'info'), default => chip('Noted', 'neutral') } ?> <?= e($ind['text']) ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <h2 class="section-heading">Invigilators' observations</h2>
        <?php if ($observations === []): ?><p class="small text-muted">None recorded.</p><?php endif; ?>
        <?php foreach ($observations as $ob): ?>
            <p class="small mb-2"><?= chip(\App\Services\Invigilation::CATEGORIES[$ob['category']] ?? $ob['category'], $ob['category'] === 'incident' ? 'warning' : 'neutral') ?> <?= e($ob['note']) ?> <span class="text-muted">· <?= e((string) $ob['author']) ?>, <?= e(fmt_time((string) $ob['created_at'])) ?></span></p>
        <?php endforeach; ?>
        <h2 class="section-heading">Conclusions</h2>
        <?php if ($integrity['reviews'] === []): ?><p class="small text-muted">None recorded.</p><?php endif; ?>
        <?php foreach ($integrity['reviews'] as $rv): ?>
            <p class="small mb-2"><?= chip(Integrity::OUTCOMES[$rv['outcome']] ?? $rv['outcome'], $rv['outcome'] === 'no_concern' ? 'success' : 'warning') ?> <?= e($rv['note']) ?> <span class="text-muted">· <?= e((string) $rv['author']) ?>, <?= e(fmt_time((string) $rv['created_at'])) ?></span></p>
        <?php endforeach; ?>
        <?php if ($canReview && $s['status'] !== 'in_progress'): ?>
        <form method="post" action="<?= e(url($base . '/results/' . $s['uuid'] . '/integrity')) ?>" class="row g-2 align-items-end mb-3" data-once><?= csrf_field() ?>
            <div class="col-sm-4"><label class="form-label small" for="outcome">Conclusion</label><select class="form-select form-select-sm" id="outcome" name="outcome">
                <?php foreach (Integrity::OUTCOMES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-sm-6"><label class="form-label small" for="note">What it rests on</label><input class="form-control form-control-sm" id="note" name="note" maxlength="2000" required></div>
            <div class="col-sm-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit">Record</button></div>
        </form>
        <?php endif; ?>
        <h2 class="section-heading">What was observed</h2>
        <?php if ($integrity['events'] === []): ?><p class="small text-muted mb-0">Nothing was recorded<?= $s['monitoring'] === 'basic' ? ': this sitting\'s monitoring was basic' : '' ?>.</p><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Received (server time)</th><th>Observation</th><th>From</th><th>Lasted</th></tr></thead>
            <tbody>
            <?php foreach ($integrity['events'] as $ev): ?>
                <tr><td class="small text-muted"><?= e(fmt_time((string) $ev['received_at'])) ?></td><td class="small"><?= e(Integrity::LABELS[$ev['type']] ?? $ev['type']) ?></td>
                    <td class="small text-muted"><?= $ev['source'] === 'server' ? 'Server' : 'Browser' ?></td><td class="small"><?= $ev['duration_ms'] !== null && in_array($ev['type'], ['visible', 'online'], true) ? round((int) $ev['duration_ms'] / 1000) . ' s' : '' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
