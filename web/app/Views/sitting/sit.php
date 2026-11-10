<?php
/**
 * A sitting in progress. Nothing about the right answers is on this page: only the question,
 * its pictures and its choices in this candidate's order. Without the page's script every
 * question shows at once and handing in sends every answer; with it, one question shows at a
 * time, each answer is saved as it is picked (and kept on this device until the server has it),
 * and the clock, set by the server, hands it in at zero.
 *
 * @var array $session @var list<array> $questions @var array<int,array> $responses @var int $secondsLeft @var int $seq @var list<array> $messages
 */
use App\Controllers\InstitutionBankController;

$total = count($questions);
$answered = count(array_filter($responses, static fn(array $r): bool => $r['answer'] !== ''));
?>
<div class="sitting-paused" data-paused<?= $session['paused_at'] === null ? ' hidden' : '' ?> role="alert"><div class="card"><div class="card-body text-center py-5">
    <?= icon('pause-circle') ?><h2 class="h5 mt-2">Paused by the invigilator</h2><p class="mb-0 text-muted">Your clock has stopped. Answers are kept.</p>
</div></div></div>
<form class="mock sitting" method="post" action="<?= e(url('/sit/' . $session['uuid'] . '/submit')) ?>" data-sitting
      data-uuid="<?= e($session['uuid']) ?>" data-save-url="<?= e(url('/api/sit/' . $session['uuid'] . '/answer')) ?>"
      data-status-url="<?= e(url('/api/sit/' . $session['uuid'])) ?>" data-events-url="<?= e(url('/api/sit/' . $session['uuid'] . '/events')) ?>"
      data-monitor="<?= e(implode(' ', \App\Services\Integrity::types((string) $session['monitoring']))) ?>" data-ends-in="<?= $secondsLeft ?>" data-minutes="<?= (int) $session['duration_minutes'] ?>" data-seq="<?= $seq ?>"
      data-confirm="You cannot change your answers after handing in." data-confirm-title="Hand in your answers?" data-confirm-button="Hand in">
    <?= csrf_field() ?><input type="hidden" name="sent" value="1">
    <div class="sitting-messages" data-messages aria-live="polite">
        <?php foreach (array_reverse($messages) as $g): ?><div class="alert alert-info" data-message-id="<?= (int) $g['id'] ?>"><?= icon('chat-left-text') ?><span><strong>From the invigilator:</strong> <?= e($g['body']) ?></span></div><?php endforeach; ?>
    </div>
    <div class="mock-bar">
        <div class="mock-bar-title">
            <span class="fw-semibold"><?= e($session['title']) ?></span>
            <span class="text-muted small"><?= e($session['institution_name']) ?> · <?= e(\App\Services\Access::displayName($session)) ?> · <span data-mock-answered><?= $answered ?> of <?= $total ?> answered</span></span>
        </div>
        <span class="mock-timer" data-mock-timer role="timer" aria-live="off" title="Time left, kept by the server"><?= icon('stopwatch') ?><span data-field="time"><?= sprintf('%d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60) ?></span></span>
        <span class="sitting-save" data-save-state role="status"><?= icon('cloud-check') ?><span>Saved</span></span>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-fullscreen hidden><?= icon('arrows-fullscreen') ?>Full screen</button>
        <button class="btn btn-primary" type="submit"><?= icon('check2-square') ?>Hand in</button>
    </div>

    <nav class="mock-nav" aria-label="Questions" hidden>
        <?php foreach ($questions as $i => $q): $r = $responses[(int) $q['position']] ?? null; ?>
            <button type="button" class="mock-nav-btn<?= ($r['answer'] ?? '') !== '' ? ' is-answered' : '' ?><?= (int) ($r['flagged'] ?? 0) ? ' is-flagged' : '' ?>" data-mock-go="<?= $i ?>" aria-label="Question <?= $i + 1 ?>"><?= $i + 1 ?></button>
        <?php endforeach; ?>
    </nav>

    <?php foreach ($questions as $i => $q):
        $pos = (int) $q['position'];
        $r = $responses[$pos] ?? null;
        $picked = str_split((string) ($r['answer'] ?? ''));
        $choices = [];
        foreach ((array) json_decode((string) $q['choices'], true) as $c) { $choices[(string) $c['key']] = (string) $c['text']; }
        $multiple = $q['type'] === 'multiple';
        $images = (array) (json_decode((string) ($q['images'] ?? ''), true) ?: []); ?>
        <section class="card question-card mock-question" data-position="<?= $pos ?>" data-multiple="<?= $multiple ? '1' : '0' ?>">
            <div class="card-body">
                <div class="q-head">
                    <h2 class="q-number"><span class="num">Question <?= $i + 1 ?> of <?= $total ?></span> <span class="text-muted small fw-normal"><?= e(rtrim(rtrim((string) $q['marks'], '0'), '.')) ?> marks</span></h2>
                    <label class="mock-flag"><input class="form-check-input" type="checkbox" data-flag<?= (int) ($r['flagged'] ?? 0) ? ' checked' : '' ?>> Mark to look at again</label>
                </div>
                <div class="bank-question-text"><?= nl2br(e((string) $q['text'])) ?></div>
                <?php foreach ($images as $img): ?><img class="bank-question-image" src="<?= e(InstitutionBankController::imageSrc((string) $img)) ?>" alt="Picture for question <?= $i + 1 ?>" loading="lazy"><?php endforeach; ?>
                <?php if ($q['type'] === 'essay'): ?>
                <label class="small text-muted mb-2 d-block" for="w<?= $pos ?>">Write your answer. A marker gives the marks.</label>
                <textarea class="form-control mock-text" id="w<?= $pos ?>" name="a<?= $pos ?>" rows="10" maxlength="<?= \App\Services\Sittings::MAX_WRITTEN ?>"><?= e((string) ($r['answer'] ?? '')) ?></textarea>
                <?php else: ?>
                <fieldset>
                    <legend class="small text-muted mb-2"><?= $multiple ? 'Choose every right answer.' : 'Choose one answer.' ?></legend>
                    <ul class="list-unstyled choices mock-choices">
                        <?php foreach (str_split((string) $q['choice_order']) as $n => $key): if (!isset($choices[$key])) { continue; } ?>
                            <li><label class="choice mock-choice">
                                <input class="mock-pick" type="<?= $multiple ? 'checkbox' : 'radio' ?>" name="a<?= $pos ?><?= $multiple ? '[]' : '' ?>" value="<?= e($key) ?>"<?= in_array($key, $picked, true) ? ' checked' : '' ?>>
                                <span class="choice-letter"><?= $q['type'] === 'true_false' ? '' : chr(65 + $n) . '.' ?></span>
                                <span class="choice-text"><?= nl2br(e($choices[$key]), false) ?></span>
                            </label></li>
                        <?php endforeach; ?>
                    </ul>
                </fieldset>
                <?php if (!$multiple): ?><button class="btn btn-sm btn-link px-0" type="button" data-clear>Clear my answer</button><?php endif; ?>
                <?php endif; ?>
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
    <p class="mock-save-state small text-muted" data-mock-state>Answers save as you go, even offline.</p>
</form>
