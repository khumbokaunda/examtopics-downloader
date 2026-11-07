<?php /** @var array $form @var array<string,string> $errors */ ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/institutions')) ?>">Institutions</a></li>
    <li class="breadcrumb-item active" aria-current="page">Register</li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Register an institution</h1>
        <p class="page-subtitle">You become its owner. A platform reviewer checks the registration before people can be invited. Verification here confirms a reviewer looked at it; it is not accreditation or government recognition.</p>
    </div>
</header>
<form method="post" action="<?= e(url('/institutions/register')) ?>" data-once><?= csrf_field() ?>
    <?= \App\Core\View::current()->partial('institutions/_form', ['form' => $form, 'errors' => $errors]) ?>
    <div class="form-actions mt-4"><button class="btn btn-primary" type="submit"><?= icon('send') ?>Register and send for verification</button></div>
</form>
