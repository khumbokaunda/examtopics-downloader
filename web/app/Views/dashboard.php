<?php
/**
 * The dashboard: the person's own studying first, then (for those who run it) the system.
 *
 * @var string $greeting @var string $firstName @var string $today
 * @var list<array> $studying the exams studied most recently, with place and progress
 * @var list<array> $suggestions exams to start with, when nothing was studied yet
 * @var array<string,array{answered:int,correct:int,pages:int}> $days the last 14 days
 * @var array{answered:int,correct:int,mistakes:int} $week @var array{current:int,best:int,today:bool} $streak
 * @var list<array> $mistakes @var list<array> $saved @var list<array> $news
 * @var array{manager:bool,rows:list<array>,open:int}|null $requests
 * @var bool $system @var array{exams:int,questions:int}|null $totals @var int $providerCount @var list<array> $active
 * @var list<array> $recentCompleted @var list<array> $recentFailed @var list<array> $recentDocuments
 * @var array<string,int> $jobCounts @var bool $workerRunning
 * @var array{people:list<array>,count:int}|null $members @var array|null $activity
 */
use App\Services\Access;
use App\Services\Editions;
use App\Services\StudyProgress;

$collector = can('exams.collect');
$examPath = static fn(array $card): string => '/library/' . rawurlencode((string) $card['provider']['slug']) . '/' . rawurlencode((string) $card['exam']['code']);
$answeredLine = static function (array $t, int $questions): string {
    return number_format($questions > 0 ? min($t['answered'], $questions) : $t['answered']) . ' of ' . number_format($questions)
        . ' answered, ' . (int) StudyProgress::percent($t['correct'], $t['answered']) . '% right';
};
$weekPercent = StudyProgress::percent($week['correct'], $week['answered']);
$summary = match (true) {
    $week['answered'] > 0 => 'This week: ' . number_format($week['answered']) . ' answer' . ($week['answered'] === 1 ? '' : 's') . ' checked, ' . $weekPercent . '% right.',
    $studying !== [] => 'Nothing answered yet this week. Pick up where you left off below.',
    default => 'Find an exam and practise it: every answer you check counts here.',
};
// What a card says when it has nothing in it yet: one line, so an empty card stays small.
$note = static fn(string $iconName, string $title, string $body, ?array $action = null): string =>
    '<div class="card-note"><span class="icon-bubble" data-tone="neutral">' . icon($iconName) . '</span>'
    . '<div class="card-note-text"><div class="card-note-title">' . e($title) . '</div><div class="card-note-body">' . e($body) . '</div></div>'
    . ($action !== null ? '<a class="btn btn-sm btn-outline-secondary" href="' . e($action[1]) . '">' . e($action[0]) . '</a>' : '') . '</div>';
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e($greeting) ?><?= $firstName !== '' ? ', ' . e($firstName) : '' ?></h1>
        <p class="page-subtitle"><?= e($summary) ?></p>
    </div>
    <?php if ($collector): ?><div class="page-actions"><a class="btn btn-primary" href="<?= e(url('/collect')) ?>"><?= icon('cloud-arrow-down') ?>Collect an exam</a></div><?php endif; ?>
</header>

<div class="row g-4">
    <div class="col-xl-8 dash-col">
        <?php if ($studying !== []): $lead = $studying[0]; $t = $lead['progress']; $path = $examPath($lead); $practice = $lead['place']['mode'] === 'practice';
            $label = Editions::codeLabel($lead['exam']); ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?= icon('play-circle') ?>Continue studying</span>
                <a class="small" href="<?= e(url('/progress')) ?>">My progress</a>
            </div>
            <div class="card-body continue-lead">
                <div class="continue-meta"><?php if ($label !== null): ?><span class="code-tag" title="<?= e(strtoupper((string) $lead['exam']['code'])) ?>"><?= e($label) ?></span> <?php endif; ?><?= e(provider_name($lead['provider'])) ?> · <?= $practice ? 'Practice' : 'Study' ?>, page <?= (int) $lead['place']['page'] ?> · <?= e(fmt_ago($lead['place']['updated_at'])) ?></div>
                <h2 class="continue-title"><a href="<?= e(url($path)) ?>"><?= e(Editions::title($lead['exam'])) ?></a></h2>
                <?php if ($t['answered'] > 0): ?>
                    <progress class="study-meter study-meter--lg" max="<?= max(1, $lead['questions']) ?>" value="<?= min($t['answered'], max(1, $lead['questions'])) ?>" aria-label="Questions answered"></progress>
                    <p class="small text-muted mb-3"><?= e($answeredLine($t, $lead['questions'])) ?><?= $t['mistakes'] > 0 ? ' · ' . number_format($t['mistakes']) . ' to go over' : '' ?></p>
                <?php else: ?>
                    <p class="small text-muted mb-3"><?= number_format($lead['questions']) ?> questions. In practice mode, pick an answer and reveal it to count it.</p>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-primary" href="<?= e(url($path . '/view', ['mode' => $practice ? 'practice' : '', 'page' => (string) $lead['place']['page']])) ?>"><?= icon('play-fill') ?>Continue</a>
                    <?php if ($t['mistakes'] > 0): ?>
                        <a class="btn btn-outline-secondary" href="<?= e(url($path . '/view', ['mode' => 'practice', 'mistakes' => '1'])) ?>"><?= icon('arrow-repeat') ?>Go over <?= number_format($t['mistakes']) ?> mistake<?= $t['mistakes'] === 1 ? '' : 's' ?></a>
                    <?php elseif (!$practice): ?>
                        <a class="btn btn-outline-secondary" href="<?= e(url($path . '/view', ['mode' => 'practice'])) ?>"><?= icon('patch-question') ?>Practise</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (count($studying) > 1): ?>
            <div class="continue-more">
                <div class="section-heading">Also studying</div>
                <ul class="list-rows">
                    <?php foreach (array_slice($studying, 1) as $st): $p = $examPath($st); $mode = $st['place']['mode'] === 'practice'; ?>
                        <li>
                            <div class="row-main">
                                <span class="icon-bubble" data-tone="primary"><?= icon($mode ? 'patch-question' : 'book') ?></span>
                                <div class="row-text">
                                    <a class="row-title" href="<?= e(url($p)) ?>"><?= e(Editions::title($st['exam'])) ?></a>
                                    <div class="row-meta"><?= $st['progress']['answered'] > 0 ? e($answeredLine($st['progress'], $st['questions'])) : ($mode ? 'Practice' : 'Study') . ', page ' . (int) $st['place']['page'] ?></div>
                                </div>
                            </div>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($p . '/view', ['mode' => $mode ? 'practice' : '', 'page' => (string) $st['place']['page']])) ?>">Continue</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('compass') ?>Start studying</span></div>
            <div class="card-body">
                <p class="mb-3">Find an exam, open it in practice mode, pick an answer and reveal it. Every answer you check counts towards your week and your streak.</p>
                <form class="library-search mb-4" method="get" action="<?= e(url('/library')) ?>" role="search">
                    <span class="library-search-icon"><?= icon('search') ?></span>
                    <input class="form-control" type="search" name="q" placeholder="Search by name, code or provider" aria-label="Search the library">
                </form>
                <?php if ($suggestions !== []): ?>
                    <div class="section-heading">Try one of these</div>
                    <ul class="list-rows">
                        <?php foreach ($suggestions as $sg): $p = $examPath($sg); ?>
                            <li>
                                <div class="row-main">
                                    <span class="icon-bubble" data-tone="primary"><?= icon('journal-text') ?></span>
                                    <div class="row-text">
                                        <a class="row-title" href="<?= e(url($p)) ?>"><?= e(Editions::title($sg['exam'])) ?></a>
                                        <div class="row-meta"><?= e(provider_name($sg['provider'])) ?> · <?= number_format($sg['questions']) ?> questions</div>
                                    </div>
                                </div>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($p . '/view', ['mode' => 'practice'])) ?>"><?= icon('patch-question') ?>Practise</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($studying !== [] || $mistakes !== [] || $saved !== []): ?>
        <div class="row g-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('arrow-repeat') ?>Mistakes to go over</span></div>
            <?php if ($mistakes === []): ?>
                <?= $note('check2-all', 'Nothing to go over', 'Answers you get wrong in practice mode collect here until you get them right.') ?>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($mistakes as $m): $p = $examPath($m); ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="warning"><?= icon('arrow-repeat') ?></span>
                            <div class="row-text">
                                <a class="row-title" href="<?= e(url($p)) ?>"><?= e(Editions::title($m['exam'])) ?></a>
                                <div class="row-meta"><?= number_format($m['mistakes']) ?> to go over<?= $m['progress'] !== null ? ' · ' . (int) StudyProgress::percent($m['progress']['correct'], $m['progress']['answered']) . '% right so far' : '' ?></div>
                            </div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($p . '/view', ['mode' => 'practice', 'mistakes' => '1'])) ?>">Go over</a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('bookmark') ?>Saved</span><a class="small" href="<?= e(url('/library')) ?>">Library</a></div>
            <?php if ($saved === []): ?>
                <?= $note('bookmark', 'Nothing saved yet', 'Use the bookmark on an exam in the library to keep it here.') ?>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($saved as $sv): $p = $examPath($sv); ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="primary"><?= icon('bookmark-fill') ?></span>
                            <div class="row-text">
                                <a class="row-title" href="<?= e(url($p)) ?>"><?= e(Editions::title($sv['exam'])) ?></a>
                                <div class="row-meta"><?= e(provider_name($sv['provider'])) ?> · <?= $sv['progress'] !== null && $sv['progress']['answered'] > 0 ? e($answeredLine($sv['progress'], $sv['questions'])) : number_format($sv['questions']) . ' questions' ?></div>
                            </div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($p . '/view', ['mode' => 'practice'])) ?>"><?= icon('patch-question') ?>Practise</a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('stars') ?>New in the library</span><a class="small" href="<?= e(url('/library')) ?>">View library</a></div>
            <?php if ($news === []): ?>
                <?= $note('journal-plus', 'Nothing new in the last two weeks', 'Exams added or collected again show here, grouped by provider.', $collector ? ['Collect an exam', url('/collect')] : null) ?>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($news as $n): ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="<?= $n['mine'] ? 'primary' : 'neutral' ?>"><?= icon('collection') ?></span>
                            <div class="row-text">
                                <div class="row-title"><a href="<?= e(url('/library', ['provider' => $n['slug']])) ?>"><?= e($n['name']) ?></a><?php if ($n['mine']): ?> <?= chip('You study this', 'primary') ?><?php endif; ?></div>
                                <?php foreach (['added' => 'added', 'updated' => 'updated'] as $kind => $verb): $list = $n[$kind]; if ($list === []) { continue; } ?>
                                    <div class="row-meta news-line"><?= count($list) ?> exam<?= count($list) === 1 ? '' : 's' ?> <?= $verb ?>:
                                        <?php foreach (array_slice($list, 0, 3) as $i => $r): ?><?= $i > 0 ? ', ' : '' ?><a href="<?= e(url('/library/' . rawurlencode((string) $r['provider_slug']) . '/' . rawurlencode((string) $r['code']))) ?>"><?= e(Editions::title($r)) ?></a><?php endforeach; ?><?= count($list) > 3 ? ' and ' . (count($list) - 3) . ' more' : '' ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="row-side"><span class="row-time row-time--words"><?= e($n['when']) ?></span></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4 dash-col">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('fire') ?>Your streak</span></div>
            <div class="card-body">
                <div class="streak-hero<?= $streak['current'] > 0 ? ' is-on' : '' ?>">
                    <span class="streak-flame"><?= icon('fire') ?></span>
                    <span class="streak-value"><?= number_format($streak['current']) ?></span>
                    <span class="streak-unit">day<?= $streak['current'] === 1 ? '' : 's' ?> in a row</span>
                </div>
                <p class="streak-note"><?= e(match (true) {
                    $streak['current'] === 0 && $streak['best'] === 0 => 'Study today to start a streak: read a page or check an answer.',
                    $streak['current'] === 0 => 'Study today to start a new one. Your best is ' . $streak['best'] . ' day' . ($streak['best'] === 1 ? '' : 's') . '.',
                    !$streak['today'] => 'Study today to keep it going. Your best is ' . $streak['best'] . ' day' . ($streak['best'] === 1 ? '' : 's') . '.',
                    $streak['current'] >= $streak['best'] && $streak['current'] > 1 => 'Done for today, and your best yet.',
                    default => 'Done for today. Your best is ' . $streak['best'] . ' day' . ($streak['best'] === 1 ? '' : 's') . '.',
                }) ?></p>
                <dl class="week-stats">
                    <div><dt>This week</dt><dd><?= number_format($week['answered']) ?></dd><dd class="week-stat-note">answers</dd></div>
                    <div><dt>Right</dt><dd><?= $weekPercent === null ? 'n/a' : $weekPercent . '%' ?></dd><dd class="week-stat-note">this week</dd></div>
                    <div><dt>To go over</dt><dd><?= number_format($week['mistakes']) ?></dd><dd class="week-stat-note">mistakes</dd></div>
                </dl>
                <?= \App\Core\View::current()->partial('partials/study_chart', ['days' => $days]) ?>
            </div>
        </div>
        <?php if ($requests !== null): $statusChip = ['open' => ['Waiting', 'warning', 'hourglass-split'], 'collected' => ['In the library', 'success', 'check2'], 'declined' => ['Declined', 'neutral', null], 'cancelled' => ['Cancelled', 'neutral', null]]; ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?= icon('inbox') ?><?= $requests['manager'] ? 'Requests waiting' : 'Your requests' ?></span>
                <a class="small" href="<?= e(url('/requests')) ?>"><?= $requests['manager'] ? ($requests['open'] > 0 ? 'Answer ' . number_format($requests['open']) : 'All requests') : 'Request an exam' ?></a>
            </div>
            <?php if ($requests['rows'] === []): ?>
                <?= $requests['manager']
                    ? $note('inbox', 'No requests waiting', 'Exams members ask for show here until they are collected or declined.')
                    : $note('inbox', 'No requests yet', 'Ask for an exam that is not in the library, and you will be told when it is.') ?>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($requests['rows'] as $rq): [$text, $tone, $ic] = $statusChip[$rq['status']] ?? [$rq['status'], 'neutral', null];
                    $code = strtoupper((string) $rq['exam_code']); $prov = provider_name(['name' => $rq['provider_name'], 'slug' => $rq['provider_slug']]); ?>
                    <li>
                        <div class="row-main">
                            <div class="row-text">
                                <?php if ($rq['status'] === 'collected'): ?>
                                    <a class="row-title" href="<?= e(url('/library/' . rawurlencode((string) $rq['provider_slug']) . '/' . rawurlencode((string) $rq['exam_code']))) ?>"><?= e($code) ?></a>
                                <?php else: ?><span class="row-title"><?= e($code) ?></span><?php endif; ?>
                                <div class="row-meta"><?= e($prov) ?><?= $requests['manager'] ? ' · ' . e(Access::displayName($rq)) : '' ?></div>
                            </div>
                        </div>
                        <div class="row-side"><?= chip($text, $tone, $ic) ?><span class="row-time"><?= e(fmt_ago((string) ($rq['resolved_at'] ?? $rq['created_at']))) ?></span></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($system):
    $failed = (int) ($jobCounts['failed'] ?? 0);
    $recent = array_merge($recentCompleted, $recentFailed);
    usort($recent, static fn($a, $b) => (int) $b['id'] <=> (int) $a['id']); ?>
<h2 class="dashboard-section">Running the library</h2>

<?php if ($collector && $totals !== null): ?>
<div class="kpi-strip">
    <?php foreach ([
        ['Exams in the library', number_format($totals['exams']), url('/library'), 'journal-bookmark', 'primary'],
        ['Questions', number_format($totals['questions']), url('/library'), 'patch-question', 'info'],
        ['Providers', number_format($providerCount), url('/providers'), 'buildings', 'neutral'],
        ['Failed jobs', number_format($failed), url('/jobs', ['status' => 'failed']), 'x-circle', $failed > 0 ? 'danger' : 'neutral'],
    ] as [$label, $value, $href, $iconName, $tone]): ?>
        <a class="kpi" href="<?= e($href) ?>" data-tone="<?= e($tone) ?>">
            <span class="kpi-icon"><?= icon($iconName) ?></span>
            <span class="kpi-text"><span class="kpi-value d-block"><?= e($value) ?></span><span class="kpi-label d-block"><?= e($label) ?></span></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="card mb-4">
    <div class="card-header">
        <span class="card-title"><?= icon('activity') ?>Active jobs</span>
        <?= $workerRunning ? chip('Worker running', 'success', 'cpu') : chip('Worker idle', 'neutral', 'cpu') ?>
    </div>
    <?php if ($active === []): ?>
        <?= $note('cup-hot', 'Nothing is running', 'Collections and inventory refreshes show here while they run, and keep running if you close the page.') ?>
    <?php else: ?>
    <div class="card-body">
        <?php foreach ($active as $job): ?>
            <div class="job-item">
                <div class="job-item-title"><a href="<?= e(url('/jobs/' . $job['uuid'])) ?>">#<?= (int) $job['id'] ?> · <?= e(job_label($job)) ?></a></div>
                <?= \App\Core\View::current()->partial('partials/job_progress', ['job' => $job, 'reload' => true]) ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-4">
    <?php if ($members !== null): ?>
    <div class="col-lg-<?= $collector ? 6 : 12 ?>">
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?= icon('people') ?>Studying this week</span>
                <a class="small" href="<?= e(url('/progress/everyone')) ?>">Everyone's progress</a>
            </div>
            <?php if ($members['people'] === []): ?>
                <?= $note('people', 'Nobody studied this week', 'Members who read or practise an exam are listed here.') ?>
            <?php else: ?>
            <div class="card-body pb-0 flex-grow-0"><p class="small text-muted mb-0"><?= number_format($members['count']) ?> <?= $members['count'] === 1 ? 'person' : 'people' ?> studied in the last 7 days.</p></div>
            <ul class="list-rows">
                <?php foreach ($members['people'] as $pp): $pct = StudyProgress::percent((int) $pp['correct'], (int) $pp['answered']); ?>
                    <li>
                        <div class="row-main">
                            <span class="avatar" aria-hidden="true"><?= e(initials(Access::displayName($pp))) ?></span>
                            <div class="row-text">
                                <a class="row-title" href="<?= e(url('/progress/people/' . rawurlencode((string) $pp['username']))) ?>"><?= e(Access::displayName($pp)) ?></a>
                                <div class="row-meta"><?= number_format((int) $pp['answered']) ?> answer<?= (int) $pp['answered'] === 1 ? '' : 's' ?><?= $pct !== null ? ', ' . $pct . '% right' : '' ?> · <?= (int) $pp['days'] ?> day<?= (int) $pp['days'] === 1 ? '' : 's' ?></div>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($collector): ?>
    <div class="col-lg-<?= $members !== null ? 6 : 12 ?>">
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?= icon('clock-history') ?>Recent jobs</span>
                <a class="small" href="<?= e(url('/jobs')) ?>">All jobs</a>
            </div>
            <?php if ($recent === []): ?>
                <?= $note('clock', 'No finished jobs yet', 'Finished collections and inventory refreshes are listed here.') ?>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach (array_slice($recent, 0, 6) as $j): [$tone, $iconName] = status_style($j['status']); ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="<?= e($tone) ?>"><?= icon($iconName) ?></span>
                            <div class="row-text">
                                <a class="row-title" href="<?= e(url('/jobs/' . $j['uuid'])) ?>">#<?= (int) $j['id'] ?> <?= e(job_label($j)) ?></a>
                                <div class="row-meta text-clamp-2"><?= e($j['message'] ?? '') ?></div>
                            </div>
                        </div>
                        <div class="row-side"><?= status_badge($j['status']) ?><span class="row-time"><?= e(fmt_ago($j['finished_at'])) ?></span></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('file-earmark-text') ?>Recently generated documents</span></div>
            <?php if ($recentDocuments === []): ?>
                <?= $note('file-earmark-plus', 'No documents yet', 'Open an exam in the library to generate Markdown or PDF study documents.') ?>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($recentDocuments as $d): $pdf = $d['format'] === 'pdf'; ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="<?= $pdf ? 'danger' : 'info' ?>"><?= icon($pdf ? 'filetype-pdf' : 'filetype-md') ?></span>
                            <div class="row-text">
                                <div class="row-title text-truncate"><?= e($d['filename']) ?></div>
                                <div class="row-meta"><?= e(strtoupper($d['format'])) ?> · <?= e(fmt_bytes($d['size_bytes'])) ?></div>
                            </div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/documents/' . $d['uuid'] . '/download')) ?>"><?= icon('download') ?>Download</a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($activity !== null): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?= icon('activity') ?>Recent activity</span>
                <a class="small" href="<?= e(url('/activity')) ?>">All activity</a>
            </div>
            <?php if ($activity['rows'] === [] && $activity['failed'] === 0 && $activity['signIns']['count'] === 0): ?>
                <?= $note('activity', 'Nothing recorded yet', 'Sign-ins and changes to accounts, settings, the library and backups are listed here.') ?>
            <?php else: ?>
            <ul class="list-rows">
                <?php if ($activity['failed'] > 0): ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="danger"><?= icon('shield-exclamation') ?></span>
                            <div class="row-text"><a class="row-title" href="<?= e(url('/activity', ['group' => 'signin'])) ?>"><?= number_format($activity['failed']) ?> failed sign-in<?= $activity['failed'] === 1 ? '' : 's' ?> in the last 24 hours</a></div>
                        </div>
                    </li>
                <?php endif; ?>
                <?php if ($activity['signIns']['count'] > 0): $si = $activity['signIns']; ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="neutral"><?= icon('box-arrow-in-right') ?></span>
                            <div class="row-text"><a class="row-title" href="<?= e(url('/activity', ['group' => 'signin'])) ?>"><?= number_format($si['count']) ?> sign-in<?= $si['count'] === 1 ? '' : 's' ?> by <?= number_format($si['people']) ?> <?= $si['people'] === 1 ? 'person' : 'people' ?> in the last 24 hours</a></div>
                        </div>
                    </li>
                <?php endif; ?>
                <?php foreach ($activity['rows'] as $a): ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="<?= e($a['tone']) ?>"><?= icon($a['icon']) ?></span>
                            <div class="row-text"><div class="row-title text-clamp-2"><?= e($a['text']) ?></div></div>
                        </div>
                        <div class="row-side"><span class="row-time"><?= e(fmt_ago((string) $a['created_at'])) ?></span></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
