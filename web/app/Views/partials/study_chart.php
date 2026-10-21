<?php
/**
 * Answers per day over the last 14 days: one column per day, today in full colour and the days
 * before it softer, a stub for a day with nothing, and the peak labelled. Each column has a
 * tooltip, and the same figures are in a table for screen readers. Drawn as SVG because the
 * Content Security Policy allows no inline styles.
 *
 * @var array<string,array{answered:int,correct:int,pages:int}> $days oldest first, ending today
 */
$slot = 24;
$bar = 14;
$top = 12;           // room for the peak's label
$plot = 40;          // tallest column
$base = $top + $plot;
$max = max(array_merge([0], array_column($days, 'answered')));
$peak = $max > 0 ? array_key_last(array_filter($days, static fn(array $d): bool => $d['answered'] === $max)) : null;
$last = array_key_last($days);
$label = static fn(string $day): string => (new DateTimeImmutable($day))->format('D j M');
?>
<figure class="study-chart mb-0">
    <figcaption class="study-chart-title">Answers per day, last 14 days<?= $max === 0 ? ': none yet' : '' ?></figcaption>
    <svg viewBox="0 0 <?= $slot * count($days) ?> <?= $base + 2 ?>" role="img" aria-label="Answers per day over the last 14 days; the table below has each day">
        <line class="study-chart-axis" x1="0" x2="<?= $slot * count($days) ?>" y1="<?= $base + 0.5 ?>" y2="<?= $base + 0.5 ?>"></line>
        <?php $i = 0; foreach ($days as $day => $d):
            $x = $i * $slot + ($slot - $bar) / 2;
            $h = $d['answered'] > 0 ? max(3, (int) round($plot * $d['answered'] / $max)) : 0;
            $y = $base - $h;
            $r = min(4, $h / 2);
            $tip = ($day === $last ? 'Today' : $label($day)) . ': ' . ($d['answered'] > 0
                ? $d['answered'] . ' answer' . ($d['answered'] === 1 ? '' : 's') . ', ' . $d['correct'] . ' right'
                : ($d['pages'] > 0 ? 'read, no answers checked' : 'nothing')); ?>
            <g class="study-chart-day<?= $day === $last ? ' is-today' : '' ?>">
                <title><?= e($tip) ?></title>
                <rect class="study-chart-hit" x="<?= $i * $slot ?>" y="0" width="<?= $slot ?>" height="<?= $base + 2 ?>"></rect>
                <?php if ($h > 0): ?>
                    <path class="study-chart-bar" d="M<?= $x ?>,<?= $base ?>V<?= $y + $r ?>Q<?= $x ?>,<?= $y ?> <?= $x + $r ?>,<?= $y ?>H<?= $x + $bar - $r ?>Q<?= $x + $bar ?>,<?= $y ?> <?= $x + $bar ?>,<?= $y + $r ?>V<?= $base ?>Z"></path>
                    <?php if ($day === $peak): ?><text class="study-chart-peak" x="<?= $x + $bar / 2 ?>" y="<?= $y - 4 ?>" text-anchor="middle"><?= number_format($d['answered']) ?></text><?php endif; ?>
                <?php else: ?>
                    <rect class="study-chart-empty<?= $d['pages'] > 0 ? ' is-read' : '' ?>" x="<?= $x ?>" y="<?= $base - 2 ?>" width="<?= $bar ?>" height="2" rx="1"></rect>
                <?php endif; ?>
            </g>
        <?php $i++; endforeach; ?>
    </svg>
    <div class="study-chart-axis-labels" aria-hidden="true"><span><?= e($label((string) array_key_first($days))) ?></span><span>Today</span></div>
    <table class="visually-hidden">
        <caption>Answers per day, last 14 days</caption>
        <thead><tr><th scope="col">Day</th><th scope="col">Answers</th><th scope="col">Right</th><th scope="col">Pages read</th></tr></thead>
        <tbody>
        <?php foreach ($days as $day => $d): ?>
            <tr><th scope="row"><?= e($day === $last ? 'Today' : $label($day)) ?></th><td><?= $d['answered'] ?></td><td><?= $d['correct'] ?></td><td><?= $d['pages'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</figure>
