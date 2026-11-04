<?php
/**
 * A mock exam in progress. Nothing about the answers is in this page: questions come stripped
 * as in practice mode, and grading happens on the server when it is handed in. Without the
 * page's script every question shows at once and the form still works; with it, one question
 * shows at a time, picks are saved as they are made, and the clock hands it in at zero.
 *
 * @var array $provider @var array $exam @var array $version @var array $mock
 * @var list<array> $questions @var array<string,string> $localImages @var int|null $secondsLeft
 */
use App\Services\Documents\QuestionView;
use App\Services\Editions;

$total = count($questions);
$answered = count(array_filter($mock['keys'], static fn(string $k) => ($mock['picks'][$k] ?? '') !== ''));
?>
<form class="mock" method="post" action="<?= e(url('/mock/' . $mock['uuid'] . '/finish')) ?>" data-mock
      data-save-url="<?= e(url('/api/mock/' . $mock['uuid'] . '/answer')) ?>"<?= $secondsLeft !== null ? ' data-ends-in="' . $secondsLeft . '" data-minutes="' . (int) $mock['minutes'] . '"' : '' ?>
      data-confirm="Questions left unanswered count as wrong." data-confirm-title="Hand in your answers?" data-confirm-button="Hand in">
    <?= csrf_field() ?>
    <div class="mock-bar">
        <div class="mock-bar-title">
            <span class="fw-semibold"><?= e(Editions::shortCode((string) $exam['code'])) ?> mock exam</span>
            <span class="text-muted small" data-mock-answered><?= $answered ?> of <?= $total ?> answered</span>
        </div>
        <?php if ($secondsLeft !== null): ?>
            <span class="mock-timer" data-mock-timer role="timer" aria-live="off" title="Time left"><?= icon('stopwatch') ?><span data-field="time"><?= sprintf('%d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60) ?></span></span>
        <?php else: ?>
            <span class="mock-timer is-untimed"><?= icon('infinity') ?>No time limit</span>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= icon('check2-square') ?>Hand in</button>
    </div>

    <nav class="mock-nav" aria-label="Questions" hidden>
        <?php foreach ($questions as $i => $item): $key = QuestionView::key($item); ?>
            <button type="button" class="mock-nav-btn<?= ($mock['picks'][$key] ?? '') !== '' ? ' is-answered' : '' ?><?= in_array($key, $mock['flags'], true) ? ' is-flagged' : '' ?>" data-mock-go="<?= $i ?>" aria-label="Question <?= $i + 1 ?>"><?= $i + 1 ?></button>
        <?php endforeach; ?>
    </nav>

    <?php foreach ($questions as $i => $item): $key = QuestionView::key($item); $picked = str_split((string) ($mock['picks'][$key] ?? ''));
        $picks = (int) ($item['picks'] ?? 1); ?>
        <section class="card question-card mock-question" id="mq-<?= $i ?>" data-index="<?= $i ?>" data-key="<?= e($key) ?>" data-picks="<?= $picks ?>">
            <div class="card-body">
                <div class="q-head">
                    <h2 class="q-number"><span class="num">Question <?= $i + 1 ?> of <?= $total ?></span></h2>
                    <label class="mock-flag"><input class="form-check-input" type="checkbox" data-mock-flag<?= in_array($key, $mock['flags'], true) ? ' checked' : '' ?>> Mark to look at again</label>
                </div>
                <?= \App\Core\View::current()->partial('viewer/question_text', ['item' => $item, 'localImages' => $localImages]) ?>
                <ul class="list-unstyled choices mock-choices" aria-label="Your answer">
                    <?php foreach ((array) ($item['choices'] ?? []) as $c): $letter = (string) ($c['letter'] ?? ''); if ($letter === '') { continue; } ?>
                        <li><label class="choice mock-choice">
                            <input class="mock-pick" type="checkbox" name="pick_<?= $i ?>_<?= e($letter) ?>" value="1" data-letter="<?= e($letter) ?>"<?= in_array($letter, $picked, true) ? ' checked' : '' ?>>
                            <span class="choice-letter"><?= e($letter) ?>.</span>
                            <span class="choice-text"><?= nl2br(e((string) ($c['text'] ?? '')), false) ?><?php foreach ((array) ($c['images'] ?? []) as $u): $src = $localImages[(string) $u] ?? QuestionView::safeImageUrl((string) $u); ?><?php if ($src !== null): ?><img class="q-image" src="<?= e($src) ?>" alt="Choice <?= e($letter) ?>" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?><?php endforeach; ?></span>
                        </label></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($picks !== 1): ?><div class="small text-muted"><?= $picks > 1 ? 'Pick ' . $picks : 'Pick every answer that applies' ?></div><?php endif; ?>
                <div class="mock-steps" hidden>
                    <button class="btn btn-outline-secondary" type="button" data-mock-step="-1"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('chevron-left') ?>Previous</button>
                    <?php if ($i < $total - 1): ?>
                        <button class="btn btn-outline-primary" type="button" data-mock-step="1">Next<?= icon('chevron-right') ?></button>
                    <?php else: ?>
                        <button class="btn btn-primary" type="submit"><?= icon('check2-square') ?>Hand in</button>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
    <p class="mock-save-state small text-muted" data-mock-state>Your answers are saved as you go.</p>
</form>
<form method="post" action="<?= e(url('/mock/' . $mock['uuid'] . '/discard')) ?>" class="mt-2" data-confirm="Nothing you answered in it is counted." data-confirm-title="Give up this mock exam?" data-confirm-button="Give up" data-confirm-tone="danger">
    <?= csrf_field() ?><button class="btn btn-sm btn-link btn-link-danger" type="submit"><?= icon('x-circle') ?>Give up</button>
</form>
