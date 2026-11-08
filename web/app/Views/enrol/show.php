<?php /** @var array|null $code @var string|null $error */
use App\Services\InstitutionStructure;
?>
<div class="col-lg-7 mx-auto">
<div class="card">
    <div class="card-body">
        <?php if ($code === null): ?>
            <h1 class="h4 mb-2">Join an institution with a code</h1>
            <p class="text-muted">Your institution gives you a code such as <span class="mono">ABCD-7K2M-Q9WD</span>. You keep this account; joining adds the institution to it.</p>
            <?php if ($error !== null): ?><div class="alert alert-warning" role="alert"><?= icon('exclamation-triangle') ?><div class="alert-body"><?= e($error) ?></div></div><?php endif; ?>
            <form method="post" action="<?= e(url('/enrol')) ?>" class="toolbar" data-once><?= csrf_field() ?>
                <label class="visually-hidden" for="code">Code</label>
                <input class="form-control mono" id="code" name="code" maxlength="24" autocomplete="off" autocapitalize="characters" required placeholder="Code">
                <button class="btn btn-primary" type="submit"><?= icon('arrow-right') ?>Continue</button>
            </form>
        <?php else: ?>
            <p class="text-muted small mb-1"><?= icon('building') ?> Enrolment code <span class="mono"><?= e($code['code']) ?></span></p>
            <h1 class="h4 mb-2">Join <?= e($code['institution_name']) ?></h1>
            <p>You would join as a student<?= $code['programme_name'] !== null ? ' of ' . e($code['programme_name']) : '' ?><?= $code['class_name'] !== null ? ', in ' . e(InstitutionStructure::label((string) $code['class_name'], (string) $code['class_intake'])) : '' ?>.
                <?= (int) $code['requires_approval'] === 1 ? 'The institution approves each request first.' : 'You join straight away.' ?></p>
            <form method="post" action="<?= e(url('/enrol/join')) ?>" data-once><?= csrf_field() ?>
                <?= \App\Core\View::current()->partial('policies/_notice', ['key' => 'membership_notice', 'name' => 'notice', 'institution' => $code['institution_name']]) ?>
                <button class="btn btn-primary" type="submit"><?= icon('check2') ?><?= (int) $code['requires_approval'] === 1 ? 'Ask to join' : 'Join' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/enrol')) ?>">Not this one</a>
            </form>
        <?php endif; ?>
    </div>
</div>
</div>
