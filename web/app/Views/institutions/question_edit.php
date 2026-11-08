<?php
/** @var array $institution @var array $membership @var string $tab @var array $bank @var array|null $question @var array $form @var list<string> $errors @var list<array> $topics */
use App\Controllers\InstitutionBankController;
use App\Services\QuestionBanks;
$bankPath = '/institutions/' . $institution['uuid'] . '/banks/' . $bank['uuid'];
$new = $question === null;
$action = $new ? $bankPath . '/questions' : $bankPath . '/questions/' . $question['uuid'];
$choices = $form['type'] === 'true_false' ? [] : array_values($form['choices']);
$rows = min(QuestionBanks::MAX_CHOICES, max(4, count($choices) + 2));
$tf = '';
foreach ($form['choices'] as $c) {
    if ($form['type'] === 'true_false' && !empty($c['correct'])) {
        $tf = (string) $c['key'];
    }
}
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $institution, 'membership' => $membership, 'tab' => $tab,
    'crumb' => ['Question banks' => '/institutions/' . $institution['uuid'] . '/banks', $bank['name'] => $bankPath, $new ? 'New question' : 'Edit question' => null]]) ?>
<?php if ($errors !== []): ?>
    <div class="alert alert-danger" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body">Nothing was saved. <?= e(implode(' ', $errors)) ?></div></div>
<?php endif; ?>
<?php if (!$new && $question['published_version'] !== null): ?>
    <div class="alert alert-info" role="status"><?= icon('info-circle') ?><div class="alert-body">Saving makes version <?= (int) $question['latest_version'] + 1 ?>, a draft. Assessments keep using version <?= (int) $question['published_version'] ?> until the new one is reviewed and published.</div></div>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>" enctype="multipart/form-data" data-once data-question-form><?= csrf_field() ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('pencil-square') ?>The question</span></div>
            <div class="card-body">
                <div class="mb-3"><label class="form-label" for="qtype">Kind</label>
                    <select class="form-select" id="qtype" name="type" data-question-type>
                        <?php foreach (QuestionBanks::TYPES as $k => $v): ?><option value="<?= e($k) ?>" <?= $form['type'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="mb-3"><label class="form-label" for="qtext">Question</label>
                    <textarea class="form-control" id="qtext" name="text" rows="5" maxlength="<?= QuestionBanks::MAX_TEXT ?>" required><?= e($form['text']) ?></textarea>
                    <div class="form-text">Plain text. Line breaks are kept.</div></div>

                <fieldset class="mb-3" data-for-types="single multiple">
                    <legend class="form-label">Choices <span class="text-muted small fw-normal">(tick the right <span data-for-types="single">one</span><span data-for-types="multiple">ones</span>; empty rows are left out)</span></legend>
                    <?php for ($n = 0; $n < $rows; $n++): $c = $choices[$n] ?? null; ?>
                        <div class="bank-choice-row">
                            <input class="form-check-input" type="checkbox" id="correct-<?= $n ?>" name="correct[]" value="<?= $n ?>" <?= !empty($c['correct']) ? 'checked' : '' ?> aria-label="Choice <?= $n + 1 ?> is right">
                            <label class="visually-hidden" for="choice-<?= $n ?>">Choice <?= $n + 1 ?></label>
                            <textarea class="form-control form-control-sm" id="choice-<?= $n ?>" name="choice_text[<?= $n ?>]" rows="1" maxlength="4000" placeholder="Choice <?= chr(65 + $n) ?>"><?= e((string) ($c['text'] ?? '')) ?></textarea>
                        </div>
                    <?php endfor; ?>
                    <div class="form-check mt-2" data-for-types="multiple">
                        <input class="form-check-input" type="checkbox" id="partial" name="partial_credit" value="1" <?= $form['partial_credit'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="partial">Give part marks for partly right answers</label>
                    </div>
                </fieldset>

                <fieldset class="mb-3" data-for-types="true_false">
                    <legend class="form-label">The statement is</legend>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" id="tf-t" name="tf_answer" value="T" <?= $tf === 'T' ? 'checked' : '' ?>><label class="form-check-label" for="tf-t">True</label></div>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" id="tf-f" name="tf_answer" value="F" <?= $tf === 'F' ? 'checked' : '' ?>><label class="form-check-label" for="tf-f">False</label></div>
                </fieldset>

                <div class="mb-3"><label class="form-label" for="explanation">Explanation (optional)</label>
                    <textarea class="form-control" id="explanation" name="explanation" rows="3" maxlength="5000"><?= e((string) ($form['explanation'] ?? '')) ?></textarea>
                    <div class="form-text">Shown when candidates review answers.</div></div>
                <div class="mb-0"><label class="form-label" for="reference">Reference (optional)</label>
                    <input class="form-control" id="reference" name="reference" value="<?= e((string) ($form['reference'] ?? '')) ?>" maxlength="1000" placeholder="Course notes, chapter 4"></div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('sliders') ?>Details</span></div>
            <div class="card-body">
                <div class="mb-3"><label class="form-label" for="marks">Marks</label>
                    <input class="form-control" id="marks" name="marks" type="number" min="0.25" max="1000" step="0.25" value="<?= e(rtrim(rtrim((string) $form['marks'], '0'), '.')) ?>" required></div>
                <div class="mb-3"><label class="form-label" for="difficulty">Difficulty</label>
                    <select class="form-select" id="difficulty" name="difficulty">
                        <?php foreach (QuestionBanks::DIFFICULTIES as $k => $v): ?><option value="<?= e($k) ?>" <?= $form['difficulty'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="mb-0"><label class="form-label" for="topic">Topic</label>
                    <select class="form-select" id="topic" name="topic"><option value="">None</option>
                        <?php foreach ($topics as $t): ?><option value="<?= e($t['uuid']) ?>" <?= (int) ($form['topic_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= $t['depth'] ? '&nbsp;&nbsp;' : '' ?><?= e($t['name']) ?></option><?php endforeach; ?>
                    </select>
                    <?php if ($topics === []): ?><div class="form-text">Add topics on the bank's page to sort questions by them.</div><?php endif; ?></div>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('image') ?>Pictures</span></div>
            <div class="card-body">
                <?php foreach ($form['images'] as $img): ?>
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="keep-<?= e(substr(md5((string) $img), 0, 8)) ?>" name="keep_images[]" value="<?= e((string) $img) ?>" checked>
                        <label class="form-check-label" for="keep-<?= e(substr(md5((string) $img), 0, 8)) ?>"><img class="bank-thumb" src="<?= e(InstitutionBankController::imageSrc((string) $img)) ?>" alt="Kept picture"> Keep</label></div>
                <?php endforeach; ?>
                <label class="form-label small" for="image">Add a picture (PNG, JPEG, GIF or WebP)</label>
                <input class="form-control form-control-sm" type="file" id="image" name="image" accept="image/png,image/jpeg,image/gif,image/webp">
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit"><?= icon('check2') ?><?= $new ? 'Save as a draft' : 'Save as a new version' ?></button>
            <?php if ($new): ?><button class="btn btn-outline-primary" type="submit" name="then" value="another"><?= icon('plus') ?>Save and write another</button><?php endif; ?>
            <a class="btn btn-outline-secondary" href="<?= e(url($new ? $bankPath : $bankPath . '/questions/' . $question['uuid'])) ?>">Cancel</a>
        </div>
    </div>
</div>
</form>
