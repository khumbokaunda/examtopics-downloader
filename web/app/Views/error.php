<?php /** @var int $status @var string $message */ ?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card shadow-sm mt-4">
            <div class="card-body p-4">
                <div class="text-muted small text-uppercase fw-semibold">Error <?= (int) $status ?></div>
                <h1 class="h4 mt-1"><?= $status === 404 ? 'Not found' : ($status === 403 ? 'Not allowed' : ($status === 409 ? 'Data problem' : 'Something went wrong')) ?></h1>
                <p class="mb-3"><?= e($message) ?></p>
                <a class="btn btn-primary" href="<?= e(url('/')) ?>">Back to the dashboard</a>
            </div>
        </div>
    </div>
</div>
