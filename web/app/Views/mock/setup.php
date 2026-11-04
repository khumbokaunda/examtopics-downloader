<?php
/**
 * Set up a mock exam of one exam, continue one in progress, and see past results.
 *
 * @var array $provider @var array $exam @var array $version
 * @var array<string,int> $available gradable questions per source (MockController::SOURCES)
 * @var array|null $open the attempt in progress @var list<array> $history finished attempts, newest first
 */
use App\Controllers\MockController;
use App\Services\Editions;
use App\Services\MockExams;

$base = '/library/' . rawurlencode($provider['slug']) . '/' . rawurlencode($exam['code']);
$duration = static function (array $m): string {
    $s = max(0, (int) strtotime($m['finished_at'] . ' UTC') - (int) strtotime($m['started_at'] . ' UTC'));
    return $s >= 3600 ? intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min' : max(1, intdiv($s + 59, 60)) . ' min';
};
$sizes = array_values(array_filter(MockExams::SIZES, static fn(int $n) => $n < $available['all']));
$default = min(20, $available['all']);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/library')) ?>">Library</a></li>
    <li class="breadcrumb-item"><a href="<?= e(url($base, ['version' => $version['version']])) ?>" title="<?= e(strtoupper((string) $exam['code'])) ?>"><?= e(Editions::shortCode((string) $exam['code'])) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page">Mock exam</li>
</ol></nav>

<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Mock exam</h1>
        <p class="page-subtitle"><?= e(Editions::title($exam)) ?>: a random set of questions, against the clock if you like. No answers are shown until you hand it in.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= e(url($base . '/view', ['version' => $version['version'], 'mode' => 'practice'])) ?>"><?= icon('patch-question') ?>Practice instead</a>
    </div>
</header>

<?php if ($open !== null): $left = MockExams::secondsLeft($open); ?>
<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <span class="icon-bubble" data-tone="info"><?= icon('hourglass-split') ?></span>
        <div class="flex-grow-1">
            <div class="fw-semibold">A mock exam is in progress</div>
            <div class="small text-muted"><?= count($open['picks']) ?> of <?= (int) $open['total'] ?> answered<?= $left !== null ? ' · ' . max(0, intdiv($left + 59, 60)) . ' min left' : ' · no time limit' ?></div>
        </div>
        <a class="btn btn-primary" href="<?= e(url('/mock/' . $open['uuid'])) ?>"><?= icon('play-fill') ?>Continue</a>
        <form method="post" action="<?= e(url('/mock/' . $open['uuid'] . '/discard')) ?>" data-confirm="Nothing you answered in it is counted." data-confirm-title="Give up this mock exam?" data-confirm-button="Give up" data-confirm-tone="danger">
            <?= csrf_field() ?><button class="btn btn-link btn-link-danger" type="submit">Give up</button>
        </form>
    </div>
</div>
<?php elseif ($available['all'] === 0): ?>
<div class="card mb-4"><div class="card-body"><?= empty_state('patch-question', 'No questions can be graded', 'A mock exam asks questions whose answer is a set of letters. This exam has none.', null, true) ?></div></div>
<?php else: ?>
<div class="card mb-4">
    <div class="card-header"><span class="card-title"><?= icon('stopwatch') ?>New mock exam</span></div>
    <div class="card-body">
        <form method="post" action="<?= e(url($base . '/mock')) ?>" class="mock-setup" data-once data-busy="Starting…">
            <?= csrf_field() ?>
            <input type="hidden" name="version" value="<?= e($version['version']) ?>">
            <fieldset class="mb-3">
                <legend class="form-label">Questions from</legend>
                <?php foreach (MockController::SOURCES as $key => $label): $n = $available[$key]; ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="source" id="source-<?= e($key) ?>" value="<?= e($key) ?>"<?= $key === 'all' ? ' checked' : '' ?><?= $n === 0 ? ' disabled' : '' ?>>
                        <label class="form-check-label" for="source-<?= e($key) ?>"><?= e($label) ?> <span class="text-muted">(<?= number_format($n) ?>)</span></label>
                    </div>
                <?php endforeach; ?>
            </fieldset>
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label" for="mock-count">How many questions</label>
                    <select class="form-select" id="mock-count" name="count" data-mock-count>
                        <?php foreach ($sizes as $n): ?><option value="<?= $n ?>"<?= $n === $default ? ' selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
                        <option value="<?= (int) $available['all'] ?>"<?= $default === $available['all'] ? ' selected' : '' ?>>All (<?= number_format($available['all']) ?>)</option>
                    </select>
                    <div class="form-text">Fewer are asked when the questions you chose run out.</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="mock-minutes">Time limit, in minutes</label>
                    <input class="form-control" type="number" id="mock-minutes" name="minutes" min="1" max="<?= MockExams::MAX_MINUTES ?>" value="<?= (int) ceil($default * 1.5) ?>" data-mock-minutes>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="timed" id="mock-timed" value="1" checked>
                        <label class="form-check-label" for="mock-timed">Time it</label>
                    </div>
                </div>
            </div>
            <div class="form-text mb-3">Only questions whose answer is a set of letters are asked, since only those can be graded. Your answers count towards your progress, like answers checked in practice mode.</div>
            <button class="btn btn-primary" type="submit"><?= icon('play-fill') ?>Start</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('clock-history') ?>Your results</span></div>
    <?php if ($history === []): ?>
        <div class="card-note"><span class="card-note-text text-muted">No mock exams of this exam yet.</span></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack" data-table="mock-history" data-order='[[0,"desc"]]'>
            <thead><tr><th>Taken</th><th data-type="num">Score</th><th data-type="num">Questions</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($history as $m): $pct = (int) $m['total'] > 0 ? (int) round((int) $m['right_count'] * 100 / (int) $m['total']) : 0; ?>
                <tr>
                    <td class="stack-title" data-order="<?= e((string) $m['finished_at']) ?>"><a class="table-row-link" href="<?= e(url('/mock/' . $m['uuid'])) ?>"><?= e(fmt_time((string) $m['finished_at'])) ?></a></td>
                    <td data-label="Score" data-order="<?= $pct ?>"><span class="fw-semibold"><?= $pct ?>%</span> <span class="text-muted"><?= (int) $m['right_count'] ?> right</span></td>
                    <td data-label="Questions" data-order="<?= (int) $m['total'] ?>"><?= (int) $m['total'] ?></td>
                    <td data-label="Time"><?= e($duration($m)) ?><?= (int) $m['minutes'] > 0 ? ' <span class="text-muted">of ' . (int) $m['minutes'] . ' min</span>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
