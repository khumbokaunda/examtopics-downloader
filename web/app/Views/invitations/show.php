<?php /** @var array $invitation @var string $token @var string|null $refusal @var bool $signedIn @var bool $canRegister */
use App\Services\InstitutionAccess;
$here = '/invitations/' . $token;
?>
<div class="<?= $signedIn ? 'col-lg-7 mx-auto' : '' ?>">
<div class="card">
    <div class="card-body">
        <p class="text-muted small mb-1"><?= icon('envelope-open') ?> Invitation</p>
        <h1 class="h4 mb-2">Join <?= e($invitation['institution_name']) ?></h1>
        <p>You are invited as <strong><?= e(InstitutionAccess::roleName((string) $invitation['role'])) ?></strong>. The invitation is for <?= e($invitation['email']) ?>.</p>
        <?php if ($refusal !== null): ?>
            <div class="alert alert-warning" role="status"><?= icon('exclamation-triangle') ?><div class="alert-body"><?= e($refusal) ?></div></div>
        <?php endif; ?>
        <?php if (!$signedIn): ?>
            <?php if ($refusal === null): ?>
            <p>Sign in to an account with that email address to accept it.<?= $canRegister ? ' No account yet? Create one with that address, then open this link again.' : '' ?></p>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="<?= e(url('/login', ['next' => $here])) ?>"><?= icon('box-arrow-in-right') ?>Sign in</a>
                <?php if ($canRegister): ?><a class="btn btn-outline-secondary" href="<?= e(url('/register')) ?>"><?= icon('person-plus') ?>Create an account</a><?php endif; ?>
            </div>
            <?php endif; ?>
        <?php elseif ($refusal === null): ?>
            <div class="d-flex flex-wrap gap-2 align-items-start">
                <form method="post" action="<?= e(url($here . '/accept')) ?>" data-once><?= csrf_field() ?>
                    <?php if ($invitation['role'] === InstitutionAccess::STUDENT): ?>
                        <?= \App\Core\View::current()->partial('policies/_notice', ['key' => 'membership_notice', 'name' => 'notice', 'institution' => $invitation['institution_name']]) ?>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Accept and join</button></form>
                <form method="post" action="<?= e(url($here . '/decline')) ?>" data-once><?= csrf_field() ?><button class="btn btn-outline-secondary" type="submit"><?= icon('x') ?>Decline</button></form>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
