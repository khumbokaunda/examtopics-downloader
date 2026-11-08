<?php /** @var array $institution @var array $membership @var string $tab @var list<array>|null $rows @var string|null $error @var array|null $results */
use App\Services\StudentImport;
$base = '/institutions/' . $institution['uuid'] . '/students/import';
$ok = $rows === null ? 0 : count(array_filter($rows, static fn(array $r): bool => in_array($r['action'], ['enrol', 'invite', 'keep'], true)));
$review = $rows === null ? 0 : count(array_filter($rows, static fn(array $r): bool => $r['action'] === 'review'));
$bad = $rows === null ? 0 : count($rows) - $ok - $review;
$tones = ['member' => 'info', 'existing_user' => 'primary', 'new_user' => 'primary', 'invited' => 'neutral', 'review' => 'warning', 'invalid' => 'danger'];
$says = ['member' => 'Update their record and classes', 'existing_user' => 'Invite their account to join', 'new_user' => 'Invite them to create an account and join',
    'invited' => 'Keep the invitation already waiting'];
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Students' => '/institutions/' . $institution['uuid'] . '/students', 'Import' => null]]) ?>

<?php if ($results !== null): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status"><?= icon('file-earmark-spreadsheet') ?><div class="alert-body flex-grow-1">The last import's outcome for every row, including any invitation links to send yourself.</div>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/results.csv')) ?>"><?= icon('download') ?>Download the results</a></div>
<?php endif; ?>

<?php if ($rows === null): ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('filetype-csv') ?>Import students from a spreadsheet</span></div>
            <div class="card-body">
                <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div><?php endif; ?>
                <form method="post" action="<?= e(url($base)) ?>" enctype="multipart/form-data" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="file">CSV file</label>
                        <input class="form-control" type="file" id="file" name="file" accept=".csv,text/csv" required>
                        <div class="form-text">Save the spreadsheet as CSV. Up to <?= StudentImport::MAX_ROWS ?> students and 1 MB. Nothing is saved until you have seen the preview.</div></div>
                    <button class="btn btn-primary" type="submit"><?= icon('eye') ?>Check the file</button>
                    <a class="btn btn-outline-secondary" href="<?= e(url($base . '/template.csv')) ?>"><?= icon('download') ?>Download the template</a>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('info-circle') ?>Columns</span></div>
            <div class="card-body small">
                <p><strong>email</strong> (needed): the student's email address.</p>
                <p><strong>name</strong>: shown in the preview and the results only; students keep their own name on their account.</p>
                <p><strong>student_number</strong>: the institution's number for them, different for every student.</p>
                <p><strong>classes</strong>: class names, separated by <span class="mono">;</span>. Add the intake when two classes share a name ("CCNA 1 2027").</p>
                <p><strong>programme</strong>: a programme's name or code.</p>
                <p class="mb-0">Rows are matched by email address only, never by name. Someone already a student here has their record updated and is put in the classes. Everyone else is invited as a student (their number, programme and classes apply when they accept), unless an invitation is already waiting, which is kept rather than sent twice, so importing the same file again is safe. A name or number that disagrees with what is on record is held for you to decide.</p>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('eye') ?>Preview: <?= $ok ?> ready, <?= $review ?> to decide, <?= $bad ?> not importable</span>
        <?php if ($review + $bad > 0): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/problems.csv')) ?>"><?= icon('download') ?>Download the problem rows</a><?php endif; ?></div>
    <form method="post" action="<?= e(url($base . '/confirm')) ?>" id="import-confirm" data-once><?= csrf_field() ?></form>
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead><tr><th>Line</th><th>Student</th><th>Number</th><th>Classes</th><th>What happens</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="small text-muted" data-label="Line"><?= (int) $r['line'] ?></td>
                    <td class="stack-title"><?= e($r['email'] !== '' ? $r['email'] : '(no email)') ?><?php if ($r['name'] !== ''): ?><div class="small text-muted"><?= e($r['name']) ?></div><?php endif; ?></td>
                    <td class="small mono" data-label="Number"><?= e($r['student_number']) ?></td>
                    <td class="small" data-label="Classes"><?= e(implode(', ', $r['class_labels'])) ?></td>
                    <td data-label="What happens"><?= chip(StudentImport::CATEGORIES[$r['category']] ?? $r['category'], $tones[$r['category']] ?? 'neutral') ?>
                        <?php if ($r['action'] === 'review'): ?>
                            <div class="small"><?= e((string) $r['conflict']) ?></div>
                            <div class="form-check small"><input class="form-check-input" type="checkbox" form="import-confirm" name="confirm[]" value="<?= (int) $r['line'] ?>" id="confirm-<?= (int) $r['line'] ?>">
                                <label class="form-check-label" for="confirm-<?= (int) $r['line'] ?>">Import it anyway</label></div>
                        <?php elseif ($r['action'] === null): ?><span class="small"><?= e($r['error']) ?></span>
                        <?php else: ?><span class="small text-muted"><?= e($says[$r['category']] ?? '') ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
        <?php if ($ok + $review > 0): ?>
            <button class="btn btn-primary" type="submit" form="import-confirm"><?= icon('check2') ?>Import the ready rows<?= $review > 0 ? ' and those ticked' : '' ?></button>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url($base)) ?>"><?= icon('x') ?>Start again</a>
        <?php if ($bad > 0): ?><span class="small text-muted">Rows that cannot be imported are left out. Fix them in the spreadsheet and import it again: rows already done are not repeated.</span><?php endif; ?>
    </div>
</div>
<?php endif; ?>
