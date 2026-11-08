<?php
/** The institution's application. @var array $institution @var array $membership @var string $tab @var list<array> $documents @var list<string> $missing
 *  @var list<string> $required @var bool $accepted @var list<array> $reviews */
use App\Services\Access;
use App\Services\Institutions;
use App\Services\Onboarding;
use App\Services\Policies;
$i = $institution;
$base = '/institutions/' . $i['uuid'];
$editable = in_array($i['status'], Onboarding::EDITABLE, true);
$canUpload = in_array($i['status'], [...Onboarding::EDITABLE, 'pending', 'under_review'], true);
$have = array_count_values(array_column($documents, 'kind'));
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $i, 'membership' => $membership, 'tab' => $tab]) ?>
<?php if ($i['status'] === 'info_requested'): ?>
    <div class="alert alert-warning" role="status"><?= icon('question-circle') ?><div class="alert-body"><strong>The reviewer asks:</strong> <?= e((string) $i['status_note']) ?></div></div>
<?php elseif ($i['status'] === 'rejected'): ?>
    <div class="alert alert-danger" role="status"><?= icon('x-octagon') ?><div class="alert-body"><strong>Not approved:</strong> <?= e((string) $i['status_note']) ?> Correct the application and send it again.</div></div>
<?php elseif (in_array($i['status'], ['pending', 'under_review'], true)): ?>
    <div class="alert alert-info" role="status"><?= icon('hourglass-split') ?><div class="alert-body">Sent for review<?= $i['submitted_at'] !== null ? ' on ' . e(fmt_time((string) $i['submitted_at'])) : '' ?>. You are told in your inbox when it is decided. You can still add documents.</div></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('patch-check') ?>Verification</span></div>
            <ul class="list-rows">
                <?php foreach (Onboarding::verification($i) as $v): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text"><div class="row-title"><?= e($v['label']) ?> <?= chip($v['text'], $v['tone']) ?></div></div></div></li>
                <?php endforeach; ?>
            </ul>
            <div class="card-body pt-0"><p class="form-text mb-0">Each is checked separately.</p></div>
        </div>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('file-earmark-text') ?>Supporting documents</span></div>
            <div class="card-body">
                <?php if ($required !== []): ?>
                    <p class="small">Needed for a <?= e(strtolower(Institutions::TYPES[$i['type']] ?? $i['type'])) ?>: <?= e(implode(', ', array_map(static fn(string $k): string => strtolower(Onboarding::DOCUMENTS[$k]) . (isset($have[$k]) ? ' (sent)' : ''), $required))) ?>.</p>
                <?php else: ?>
                    <p class="small">None required. A registration certificate helps.</p>
                <?php endif; ?>
                <?php if ($canUpload): ?>
                <form method="post" action="<?= e(url($base . '/application/documents')) ?>" enctype="multipart/form-data" class="toolbar mb-3" data-once><?= csrf_field() ?>
                    <label class="visually-hidden" for="doc-kind">What it is</label>
                    <select class="form-select form-select-sm w-auto" id="doc-kind" name="kind"><?php foreach (Onboarding::DOCUMENTS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
                    <label class="visually-hidden" for="doc-file">File</label>
                    <input class="form-control form-control-sm" type="file" id="doc-file" name="document" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                    <button class="btn btn-sm btn-primary" type="submit"><?= icon('upload') ?>Upload</button>
                </form>
                <p class="form-text">PDF, JPEG or PNG, up to 10 MB.</p>
                <?php endif; ?>
            </div>
            <?php if ($documents !== []): ?>
            <ul class="list-rows">
                <?php foreach ($documents as $d): ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><a href="<?= e(url($base . '/application/documents/' . $d['uuid'])) ?>"><?= e($d['filename']) ?></a></div>
                        <div class="row-meta"><?= e(Onboarding::DOCUMENTS[$d['kind']] ?? $d['kind']) ?> · <?= e(fmt_bytes((int) $d['size'])) ?> · <?= e(fmt_time((string) $d['created_at'])) ?></div>
                    </div></div>
                    <?php if ($editable): ?><div class="request-actions"><form method="post" action="<?= e(url($base . '/application/documents/' . $d['uuid'] . '/remove')) ?>" data-once><?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-danger" type="submit"><?= icon('trash') ?>Remove</button></form></div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <?php if ($editable): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('send') ?>Send for review</span></div>
            <div class="card-body">
                <?php if ($missing !== []): ?>
                    <p class="small mb-2">Before it can be sent:</p>
                    <ul class="small"><?php foreach ($missing as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/profile')) ?>"><?= icon('pencil') ?>Edit the profile</a>
                <?php else: ?>
                <form method="post" action="<?= e(url($base . '/application/submit')) ?>" data-once><?= csrf_field() ?>
                    <?php if ($i['status'] === 'info_requested'): ?>
                        <div class="mb-3"><label class="form-label" for="response">Your answer to the reviewer</label>
                            <textarea class="form-control" id="response" name="response" rows="3" maxlength="1000" required></textarea></div>
                    <?php endif; ?>
                    <p class="small mb-2">Sending it means accepting, on behalf of the institution:</p>
                    <ul class="small">
                        <?php foreach (Policies::INSTITUTION as $k): ?><li><a href="<?= e(url('/policies/' . $k)) ?>" target="_blank" rel="noopener"><?= e(Policies::POLICIES[$k]['title']) ?></a> (version <?= e(Policies::POLICIES[$k]['version']) ?>)</li><?php endforeach; ?>
                    </ul>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="accept" name="accept" value="1" <?= $accepted ? '' : 'required' ?>>
                        <label class="form-check-label" for="accept">I am authorised to represent <?= e($i['name']) ?>, the information given is correct, and I accept these on its behalf.</label></div>
                    <button class="btn btn-primary" type="submit"><?= icon('send') ?>Send for review</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('clock-history') ?>History</span></div>
            <?php if ($reviews === []): ?>
                <div class="card-body"><?= empty_state('clock-history', 'Not sent yet', '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($reviews as $r): $dim = Onboarding::DIMENSIONS[$r['dimension']] ?? null; ?>
                    <li class="request-row"><div class="row-main"><div class="row-text">
                        <div class="row-title"><?= $dim !== null ? e($dim['label']) . ': ' . e($dim['states'][$r['to_status']] ?? $r['to_status'])
                            : e(Institutions::STATUSES[$r['to_status']] ?? $r['to_status']) ?></div>
                        <div class="row-meta"><?= e(fmt_time((string) $r['created_at'])) ?><?= $r['note'] !== null ? ' · ' . e($r['note']) : '' ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
