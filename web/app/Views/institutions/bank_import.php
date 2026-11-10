<?php /** @var array $institution @var array $membership @var string $tab @var array $bank @var list<array> $topics @var list<array>|null $rows @var string|null $error */
use App\Services\QuestionBanks;
use App\Services\QuestionImport;
$bankUrl = '/institutions/' . $institution['uuid'] . '/banks/' . $bank['uuid'];
$base = $bankUrl . '/import';
$ok = $rows === null ? 0 : count(array_filter($rows, static fn(array $r): bool => $r['errors'] === []));
$bad = $rows === null ? 0 : count($rows) - $ok;
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Question banks' => '/institutions/' . $institution['uuid'] . '/banks', $bank['name'] => $bankUrl, 'Import' => null]]) ?>

<?php if ($rows === null): ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('upload') ?>Import questions into <?= e($bank['name']) ?></span></div>
            <div class="card-body">
                <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body"><?= e($error) ?></div></div><?php endif; ?>
                <form method="post" action="<?= e(url($base)) ?>" enctype="multipart/form-data" data-once><?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="file">File</label>
                        <input class="form-control" type="file" id="file" name="file" accept=".csv,.txt,text/csv,text/plain" required>
                        <div class="form-text">Up to <?= QuestionImport::MAX_ROWS ?> questions. You see a preview first.</div></div>
                    <div class="mb-3"><label class="form-label" for="format">Layout</label>
                        <select class="form-select" id="format" name="format">
                            <option value="">Work it out from the file name</option>
                            <?php foreach (QuestionImport::FORMATS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                        </select></div>
                    <button class="btn btn-primary" type="submit"><?= icon('eye') ?>Check the file</button>
                    <a class="btn btn-outline-secondary" href="<?= e(url($base . '/template.csv')) ?>"><?= icon('download') ?>Download the CSV template</a>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('filetype-csv') ?>CSV columns</span></div>
            <div class="card-body small">
                <p><strong>question</strong> and <strong>answer</strong> are needed.</p>
                <p><strong>a</strong> to <strong>j</strong>: the choices. <strong>answer</strong>: the letter of the right one, several as <span class="mono">A,C</span>, or True or False.</p>
                <p><strong>type</strong>: single, multiple or true_false. Left empty, it follows the answer.</p>
                <p><strong>marks</strong> (1 when empty), <strong>difficulty</strong> (easy, medium or hard; medium when empty), <strong>explanation</strong>, <strong>reference</strong>.</p>
                <p class="mb-0"><strong>topic</strong>: one of this bank's topics, by name<?= $topics === [] ? ' (it has none yet)' : '' ?>.</p>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('file-text') ?>Aiken text</span></div>
            <div class="card-body small">
                <pre class="mono mb-2">Which layer routes packets?
A. Physical
B. Network
C. Transport
ANSWER: B</pre>
                <p class="mb-0">One right answer each. A blank line between questions.</p>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= icon('eye') ?>Preview: <?= $ok ?> ready, <?= $bad ?> not importable</span>
        <?php if ($bad > 0): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/problems.csv')) ?>"><?= icon('download') ?>Download the problem rows</a><?php endif; ?></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead><tr><th>Line</th><th>Question</th><th>Kind</th><th>Answer</th><th>What happens</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="small text-muted" data-label="Line"><?= (int) $r['line'] ?></td>
                    <td class="stack-title fw-normal"><?= e(QuestionBanks::excerpt((string) $r['text'], 140)) ?: '<span class="text-muted">(no question)</span>' ?></td>
                    <td class="small" data-label="Kind"><?= e(QuestionBanks::TYPES[$r['kind']] ?? $r['kind']) ?></td>
                    <td class="small mono" data-label="Answer"><?= e(mb_substr((string) $r['answer'], 0, 20)) ?></td>
                    <td data-label="What happens"><?php if ($r['errors'] === []): ?><?= chip('Imported as a draft', 'success') ?><?php else: ?><?= chip('Not imported', 'danger') ?>
                        <div class="small"><?= e(implode(' ', $r['errors'])) ?></div><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body d-flex flex-wrap gap-2">
        <?php if ($ok > 0): ?>
        <form method="post" action="<?= e(url($base . '/confirm')) ?>" data-once><?= csrf_field() ?>
            <button class="btn btn-primary" type="submit"><?= icon('check2') ?>Import <?= $ok ?> question<?= $ok === 1 ? '' : 's' ?></button></form>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url($base)) ?>">Choose another file</a>
    </div>
</div>
<?php endif; ?>
