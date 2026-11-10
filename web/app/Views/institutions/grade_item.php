<?php /** Marks of one offline grade item. @var array $institution @var array $membership @var string $tab @var array $crumb @var array $course @var array $item
 *  @var list<array> $learners @var array<int,array> $marks by membership id */
use App\Services\Access;
use App\Services\Gradebook;
$base = '/institutions/' . $institution['uuid'] . '/courses/' . $course['uuid'] . '/grades/' . $item['uuid'];
$final = $course['grades_final_at'] !== null;
$max = Gradebook::fmt((float) $item['max_marks']);
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab, 'crumb' => $crumb]) ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('pencil-square') ?><?= e($item['title']) ?>: out of <?= e($max) ?>, weight <?= e(Gradebook::fmt((float) $item['weight'])) ?></span>
                <?= $item['released_at'] !== null ? chip('Shown to learners', 'success') : chip('Not shown yet', 'neutral') ?></div>
            <?php if ($learners === []): ?>
                <div class="card-body"><?= empty_state('mortarboard', 'No learners', 'The class has no students yet.', null, true) ?></div>
            <?php else: ?>
            <form method="post" action="<?= e(url($base . '/marks')) ?>" data-once><?= csrf_field() ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-stack">
                        <thead><tr><th>Learner</th><th>Mark</th><th>Feedback</th></tr></thead>
                        <tbody>
                        <?php foreach ($learners as $l): $mk = $marks[(int) $l['membership_id']] ?? null; $id = 'mk-' . $l['membership_uuid']; ?>
                            <tr>
                                <td class="stack-title"><label for="<?= e($id) ?>"><?= e(Access::displayName($l)) ?></label><?php if ($l['student_number'] !== null): ?><div class="small text-muted"><?= e($l['student_number']) ?></div><?php endif; ?></td>
                                <td data-label="Mark" class="grade-mark"><input class="form-control form-control-sm" type="number" id="<?= e($id) ?>" name="marks[<?= e($l['membership_uuid']) ?>]" min="0" max="<?= e($max) ?>" step="0.01"
                                    value="<?= $mk !== null && $mk['marks'] !== null ? e(Gradebook::fmt((float) $mk['marks'])) : '' ?>"<?= $final ? ' disabled' : '' ?> aria-label="Mark for <?= e(Access::displayName($l)) ?>"></td>
                                <td data-label="Feedback"><textarea class="form-control form-control-sm" name="feedback[<?= e($l['membership_uuid']) ?>]" rows="1" maxlength="2000"<?= $final ? ' disabled' : '' ?>
                                    aria-label="Feedback for <?= e(Access::displayName($l)) ?>"><?= e((string) ($mk['feedback'] ?? '')) ?></textarea></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$final): ?><div class="card-body"><button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save the marks</button></div><?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <?php if ($final): ?>
            <div class="alert alert-info" role="status"><?= icon('lock') ?><div class="alert-body">The course grades are final. Make them provisional again to change marks.</div></div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('filetype-csv') ?>Upload marks</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url($base . '/import')) ?>" enctype="multipart/form-data" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="marks-file">CSV file</label><input class="form-control" type="file" id="marks-file" name="file" accept=".csv,text/csv" required>
                        <div class="form-text">Columns: student (number, email or username), marks, and feedback if you like. Nothing is saved if a row is wrong.</div></div>
                    <button class="btn btn-outline-primary" type="submit"><?= icon('upload') ?>Upload</button>
                </form>
            </div>
        </div>
        <?php if ($item['released_at'] === null): ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('send') ?>Show to learners</span></div>
            <div class="card-body">
                <p class="small text-muted">Learners see their mark and feedback from then on, and are told.</p>
                <form method="post" action="<?= e(url($base . '/release')) ?>" data-confirm="Each learner sees their mark and feedback." data-confirm-title="Release the marks?" data-confirm-button="Release"><?= csrf_field() ?>
                    <button class="btn btn-primary" type="submit"><?= icon('send') ?>Release the marks</button></form>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
