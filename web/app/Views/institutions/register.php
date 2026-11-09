<?php /** @var array $form @var array<string,string> $errors @var array $extras */ ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/institutions')) ?>">Institutions</a></li>
    <li class="breadcrumb-item active" aria-current="page">Register</li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Register an institution</h1>
        <p class="page-subtitle">You become its owner. We review it first.</p>
    </div>
</header>
<form method="post" action="<?= e(url('/institutions/register')) ?>" data-once><?= csrf_field() ?>
    <?= \App\Core\View::current()->partial('institutions/_form', ['form' => $form, 'errors' => $errors, 'extras' => $extras, 'wizard' => true,
        'finish' => '<div class="form-actions"><button class="btn btn-primary" type="submit">' . icon('send') . 'Register</button></div>']) ?>
</form>
