<?php /** @var array $institution @var array $membership @var string $tab @var array $form @var array<string,string> $errors @var array $extras */ ?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<form method="post" action="<?= e(url('/institutions/' . $institution['uuid'] . '/profile')) ?>" data-once><?= csrf_field() ?>
    <?= \App\Core\View::current()->partial('institutions/_form', ['form' => $form, 'errors' => $errors, 'extras' => $extras]) ?>
    <div class="form-actions mt-4"><button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save the profile</button></div>
</form>
