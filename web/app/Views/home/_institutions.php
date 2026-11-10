<?php
/**
 * The institution side of home: what is set for the person, their results, and each institution
 * with what waits on them there.
 *
 * @var array{institutions:list<array>,assigned:list<array>,more:int,results:list<array>} $institutional
 */
use App\Services\InstitutionAccess;
use App\Services\Institutions;
use App\Services\Sittings;
$tone = ['open' => 'success', 'upcoming' => 'info', 'closed' => 'neutral'];
$label = ['open' => 'Open', 'upcoming' => 'Not open yet', 'closed' => 'Closed'];
$student = array_filter($institutional['institutions'], static fn(array $r): bool => $r['role'] === InstitutionAccess::STUDENT) !== [];
?>
<div class="row g-4 mb-4">
    <?php if ($student || $institutional['assigned'] !== [] || $institutional['results'] !== []): ?>
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header"><span class="card-title"><?= icon('pencil-square') ?>Set for you</span><a class="small" href="<?= e(url('/my/assessments')) ?>">All assessments</a></div>
            <?php if ($institutional['assigned'] === []): ?>
                <div class="card-note"><span class="card-note-text text-muted">Nothing open or coming up.</span></div>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($institutional['assigned'] as $a): $w = Sittings::window($a); ?>
                    <li class="request-row">
                        <div class="row-main"><span class="icon-bubble" data-tone="<?= e($tone[$w]) ?>"><?= icon($a['open_session'] !== null ? 'play-circle' : 'clipboard-check') ?></span><div class="row-text">
                            <div class="row-title"><a href="<?= e(url('/my/assessments/' . $a['institution_uuid'] . '/' . $a['uuid'])) ?>"><?= e($a['title']) ?></a>
                                <?= $a['open_session'] !== null ? chip('In progress', 'warning') : chip($label[$w], $tone[$w]) ?></div>
                            <div class="row-meta"><?= e($a['institution_short'] ?: $a['institution_name']) ?> · <?= (int) $a['duration_minutes'] ?> minutes<?= $a['closes_at'] !== null && $w !== 'upcoming' ? ' · closes ' . e(fmt_time((string) $a['closes_at'])) : '' ?><?= $w === 'upcoming' ? ' · opens ' . e(fmt_time((string) $a['opens_at'])) : '' ?></div>
                        </div></div>
                        <?php if ($a['open_session'] !== null): ?><div class="request-actions"><a class="btn btn-sm btn-primary" href="<?= e(url('/sit/' . $a['open_session'])) ?>"><?= icon('play-fill') ?>Carry on</a></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($institutional['more'] > 0): ?><div class="card-note"><span class="card-note-text text-muted"><?= (int) $institutional['more'] ?> more.</span></div><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($institutional['results'] !== []): ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('bar-chart') ?>Your results</span><a class="small" href="<?= e(url('/my/record')) ?>">Academic record</a></div>
            <ul class="list-rows">
                <?php foreach ($institutional['results'] as $h): ?>
                    <li><div class="row-main"><span class="icon-bubble" data-tone="primary"><?= icon('check2-circle') ?></span><div class="row-text">
                        <div class="row-title"><a href="<?= e(url('/sit/' . $h['uuid'] . '/result')) ?>"><?= e($h['title']) ?></a></div>
                        <div class="row-meta"><?= e($h['institution_name']) ?> · published <?= e(fmt_time((string) $h['results_published_at'])) ?></div>
                    </div></div></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="<?= $student || $institutional['assigned'] !== [] || $institutional['results'] !== [] ? 'col-xl-5' : 'col-12' ?>">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('building') ?>Your institutions</span><a class="small" href="<?= e(url('/enrol')) ?>">Join with a code</a></div>
            <ul class="list-rows">
                <?php foreach ($institutional['institutions'] as $row): $i = $row['institution']; ?>
                    <li class="request-row">
                        <div class="row-main"><span class="icon-bubble" data-tone="primary"><?= icon('building') ?></span><div class="row-text">
                            <div class="row-title"><a href="<?= e(url('/institutions/' . $i['uuid'])) ?>"><?= e($i['name']) ?></a>
                                <?= $i['status'] !== 'verified' ? chip(Institutions::STATUSES[$i['status']] ?? $i['status'], Institutions::TONES[$i['status']] ?? 'neutral') : '' ?></div>
                            <div class="row-meta"><?= e(InstitutionAccess::roleName($row['role'])) ?></div>
                        </div></div>
                        <?php if ($row['todo'] !== []): ?>
                        <div class="request-actions">
                            <?php foreach ($row['todo'] as $t): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url($t['href'])) ?>"><?= icon($t['icon']) ?><?= e($t['label']) ?></a><?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
