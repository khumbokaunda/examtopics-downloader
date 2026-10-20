<?php /** @var list<string> $codes */ ?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Save your recovery codes</h1>
        <p class="page-subtitle">If you lose your phone, each of these lets you sign in once instead of a code from your app. This is the only time they are shown.</p>
    </div>
</header>
<div class="card recovery-card">
    <div class="card-body">
        <ol class="recovery-codes">
            <?php foreach ($codes as $c): ?><li class="mono"><?= e($c) ?></li><?php endforeach; ?>
        </ol>
        <p class="small text-muted">Keep them somewhere other than your phone: a password manager, or printed and put away. Making new codes stops these from working.</p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="<?= e(url('/account/two-step/codes.txt')) ?>"><?= icon('download') ?>Download as a text file</a>
            <form method="post" action="<?= e(url('/account/two-step/codes/done')) ?>"><?= csrf_field() ?>
                <button class="btn btn-primary" type="submit"><?= icon('check2') ?>I have saved them</button>
            </form>
        </div>
    </div>
</div>
