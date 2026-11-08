<?php
/** @var list<array> $mine */
use App\Services\InstitutionAccess;
use App\Services\Institutions;
$tone = \App\Services\Institutions::TONES;
?>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Institutions</h1>
        <p class="page-subtitle">The institutions you belong to. Each one's people and records are its own: belonging to one gives you nothing in another.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-primary" href="<?= e(url('/enrol')) ?>"><?= icon('key') ?>Join with a code</a>
        <a class="btn btn-primary" href="<?= e(url('/institutions/register')) ?>"><?= icon('building-add') ?>Register an institution</a>
    </div>
</header>

<div class="card">
    <?php if ($mine === []): ?>
        <div class="card-body"><?= empty_state('building', 'You do not belong to an institution', 'Register yours, accept an invitation (invitations arrive by email and in your inbox), or join with a code your institution gave you.', ['Register an institution', url('/institutions/register')]) ?></div>
    <?php else: ?>
    <ul class="list-rows">
        <?php foreach ($mine as $i): ?>
            <li class="request-row">
                <div class="row-main">
                    <span class="icon-bubble" data-tone="<?= e($tone[$i['status']] ?? 'neutral') ?>"><?= icon('building') ?></span>
                    <div class="row-text">
                        <div class="row-title"><a href="<?= e(url('/institutions/' . $i['uuid'])) ?>"><?= e($i['name']) ?></a> <?= chip(Institutions::STATUSES[$i['status']] ?? $i['status'], $tone[$i['status']] ?? 'neutral') ?></div>
                        <div class="row-meta"><?= e(InstitutionAccess::roleName((string) $i['member_role'])) ?> · <?= e(Institutions::TYPES[$i['type']] ?? $i['type']) ?> · <?= e($i['district']) ?></div>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
