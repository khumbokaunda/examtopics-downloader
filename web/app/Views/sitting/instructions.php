<?php /** @var array $a @var string $window */
use App\Services\Assessments;
$left = (int) $a['max_attempts'] - (int) $a['attempts_used'];
$base = '/my/assessments/' . $a['institution_uuid'] . '/' . $a['uuid'];
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/my/assessments')) ?>">My assessments</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($a['title']) ?></li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title"><?= e($a['title']) ?></h1>
        <p class="page-subtitle"><?= e($a['institution_name']) ?> · <?= e(Assessments::TYPES[$a['type']] ?? $a['type']) ?></p>
    </div>
</header>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('info-circle') ?>Before you start</span></div>
            <div class="card-body">
                <dl class="row mb-3">
                    <dt class="col-sm-5">Questions</dt><dd class="col-sm-7"><?= (int) $a['question_count'] ?></dd>
                    <dt class="col-sm-5">Time allowed</dt><dd class="col-sm-7"><?= (int) $a['duration_minutes'] ?> minutes, counted from when you start<?= $a['closes_at'] !== null ? ', and never past the closing time' : '' ?></dd>
                    <dt class="col-sm-5">Pass mark</dt><dd class="col-sm-7"><?= $a['pass_percent'] !== null ? e(rtrim(rtrim((string) $a['pass_percent'], '0'), '.')) . '%' : 'None' ?></dd>
                    <dt class="col-sm-5">Attempts</dt><dd class="col-sm-7"><?= $left ?> of <?= (int) $a['max_attempts'] ?> left</dd>
                    <dt class="col-sm-5">Wrong answers</dt><dd class="col-sm-7"><?= (float) $a['negative_marking'] > 0 ? 'Lose ' . e(rtrim(rtrim((string) $a['negative_marking'], '0'), '.')) . ' times the question\'s marks. Unanswered questions lose nothing.' : 'Lose nothing; there is no penalty for guessing.' ?></dd>
                    <?php if ($a['closes_at'] !== null): ?><dt class="col-sm-5">Closes</dt><dd class="col-sm-7"><?= e(fmt_time((string) $a['closes_at'])) ?></dd><?php endif; ?>
                </dl>
                <?php if (trim((string) ($a['instructions'] ?? '')) !== ''): ?>
                    <h2 class="section-heading">Instructions</h2>
                    <p><?= nl2br(e((string) $a['instructions'])) ?></p>
                <?php endif; ?>
                <h2 class="section-heading">The rules</h2>
                <ul class="small">
                    <li>The clock starts when you start and keeps running if you close the page. When it reaches zero, the server hands in the answers it has received.</li>
                    <li>Answers are saved as you pick them. If your connection drops, they stay on this device and are sent when it returns; the bar at the top says if any have not arrived.</li>
                    <li>You can go back to any question and change your answer until you hand in. After that nothing can be changed.</li>
                    <li>Do the work on your own, using only what the institution allows.</li>
                </ul>
                <h2 class="section-heading">What is recorded</h2>
                <p class="small mb-0">When you started and handed in, the questions you were given, and each answer you picked with the time it reached the server. Nothing else about your device or what you do outside this page is recorded. The institution sees your answers and result.</p>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card">
            <div class="card-body">
                <?php if ($a['open_session'] !== null): ?>
                    <p>You have started this and the clock is running.</p>
                    <a class="btn btn-primary" href="<?= e(url('/sit/' . $a['open_session'])) ?>"><?= icon('play-fill') ?>Carry on</a>
                <?php elseif ($window === 'upcoming'): ?>
                    <p class="mb-0">It opens <?= e(fmt_time((string) $a['opens_at'])) ?>.</p>
                <?php elseif ($window === 'closed'): ?>
                    <p class="mb-0">It has closed.</p>
                <?php elseif ($left <= 0): ?>
                    <p class="mb-0">You have used every attempt.</p>
                <?php else: ?>
                    <form method="post" action="<?= e(url($base . '/start')) ?>" data-once><?= csrf_field() ?>
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="accept" name="accept" value="1" required>
                            <label class="form-check-label" for="accept">I have read the rules and what is recorded, and I accept them.</label></div>
                        <button class="btn btn-primary btn-lg" type="submit"><?= icon('play-fill') ?>Start: the clock starts now</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
