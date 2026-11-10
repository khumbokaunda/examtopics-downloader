<?php /** @var array $institution @var array $membership @var string $tab @var int $tables */ ?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab]) ?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('box-arrow-down') ?>Export everything</span></div>
            <div class="card-body">
                <p>One ZIP file with everything <?= e($institution['name']) ?> holds here, to keep a copy or to move elsewhere:</p>
                <ul class="small">
                    <li>A spreadsheet (CSV) for each kind of record: <?= $tables + 3 ?> files, among them people, students, classes, courses, grades, assessments, answers, results, transcripts and credentials.</li>
                    <li>The activity history, in words.</li>
                    <li>Uploaded files: documents, course materials and handed-in work.</li>
                </ul>
                <p class="small text-muted">Invitation and verification tokens are left out. The file holds personal data about every student: keep it safe.</p>
                <form method="post" action="<?= e(url('/institutions/' . $institution['uuid'] . '/export')) ?>"><?= csrf_field() ?>
                    <button class="btn btn-primary" type="submit"><?= icon('download') ?>Download the export</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-body small text-muted">
            <p>You are asked for your password first, as for a backup.</p>
            <p class="mb-0">Each export is recorded in the activity history.</p>
        </div></div>
    </div>
</div>
