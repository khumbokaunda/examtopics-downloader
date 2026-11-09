<?php
/** The institution's application. @var array $institution @var array $membership @var string $tab @var list<array> $slots @var list<string> $missing
 *  @var bool $accepted @var list<array> $reviews */
use App\Services\Institutions;
use App\Services\Onboarding;
use App\Services\Policies;
$i = $institution;
$base = '/institutions/' . $i['uuid'];
$editable = in_array($i['status'], Onboarding::EDITABLE, true);
$canUpload = in_array($i['status'], [...Onboarding::EDITABLE, 'pending', 'under_review'], true);
?>
<?= \App\Core\View::current()->partial('institutions/_header', ['institution' => $i, 'membership' => $membership, 'tab' => $tab]) ?>
<div class="card mb-4"><div class="card-body"><?= \App\Core\View::current()->partial('institutions/_milestones', ['institution' => $i]) ?></div></div>
<?php if ($i['status'] === 'info_requested'): ?>
    <div class="alert alert-warning" role="status"><?= icon('question-circle') ?><div class="alert-body"><strong>Reviewer asks:</strong> <?= e((string) $i['status_note']) ?></div></div>
<?php elseif ($i['status'] === 'rejected'): ?>
    <div class="alert alert-danger" role="status"><?= icon('x-octagon') ?><div class="alert-body"><strong>Not approved:</strong> <?= e((string) $i['status_note']) ?></div></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('file-earmark-text') ?>Documents</span></div>
            <?php if ($slots === []): ?>
                <div class="card-body"><?= empty_state('file-earmark', 'None needed', '', null, true) ?></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($slots as $slot): $file = $slot['files'][0] ?? null; ?>
                    <li class="request-row doc-slot">
                        <div class="row-main"><span class="icon-bubble" data-tone="<?= $file !== null ? 'success' : ((int) $slot['required'] === 1 ? 'warning' : 'neutral') ?>"><?= icon($file !== null ? 'check2' : 'file-earmark-arrow-up') ?></span><div class="row-text">
                            <div class="row-title"><?= e($slot['label']) ?> <?= (int) $slot['required'] === 1 ? chip('Required', 'warning') : chip('Optional', 'neutral') ?></div>
                            <div class="row-meta"><?php if ($file !== null): ?><a href="<?= e(url($base . '/application/documents/' . $file['uuid'])) ?>"><?= e($file['filename']) ?></a> · <?= e(fmt_bytes((int) $file['size'])) ?>
                                <?php elseif ($slot['help'] !== null): ?><?= e($slot['help']) ?><?php else: ?>Not uploaded<?php endif; ?></div>
                        </div></div>
                        <?php if ($canUpload): ?>
                        <form method="post" action="<?= e(url($base . '/application/documents')) ?>" enctype="multipart/form-data" class="request-actions" data-once><?= csrf_field() ?>
                            <input type="hidden" name="slot" value="<?= e($slot['uuid']) ?>">
                            <label class="btn btn-sm <?= $file !== null ? 'btn-outline-secondary' : 'btn-primary' ?> mb-0"><?= icon('upload') ?><?= $file !== null ? 'Replace' : 'Upload' ?>
                                <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="visually-hidden" data-autosubmit required></label>
                            <noscript><button class="btn btn-sm btn-outline-primary" type="submit">Send</button></noscript>
                        </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="card-body pt-0"><p class="form-text mb-0">PDF, JPEG or PNG, up to 10 MB.</p></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <?php if ($editable): ?>
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('send') ?>Send for review</span></div>
            <div class="card-body">
                <?php if ($missing !== []): ?>
                    <ul class="small mb-3"><?php foreach ($missing as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base . '/profile')) ?>"><?= icon('pencil') ?>Edit the profile</a>
                <?php else: ?>
                <form method="post" action="<?= e(url($base . '/application/submit')) ?>" data-once><?= csrf_field() ?>
                    <?php if ($i['status'] === 'info_requested'): ?>
                        <div class="mb-3"><label class="form-label" for="response">Your answer</label><textarea class="form-control" id="response" name="response" rows="3" maxlength="1000" required></textarea></div>
                    <?php endif; ?>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="accept" name="accept" value="1" <?= $accepted ? '' : 'required' ?>>
                        <label class="form-check-label" for="accept">I represent <?= e($i['name']) ?> and accept the
                            <?php foreach (Policies::INSTITUTION as $n => $k): ?><a href="<?= e(url('/policies/' . $k)) ?>" target="_blank" rel="noopener"><?= e(strtolower(Policies::POLICIES[$k]['title'])) ?></a><?= $n < count(Policies::INSTITUTION) - 2 ? ', ' : ($n === count(Policies::INSTITUTION) - 2 ? ' and ' : '.') ?><?php endforeach; ?></label></div>
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
                        <div class="row-title fw-normal"><?= $dim !== null ? e($dim['label']) . ': ' . e($dim['states'][$r['to_status']] ?? $r['to_status'])
                            : e(Institutions::STATUSES[$r['to_status']] ?? $r['to_status']) ?></div>
                        <div class="row-meta"><?= e(fmt_time((string) $r['created_at'])) ?><?= $r['note'] !== null ? ' · ' . e($r['note']) : '' ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
