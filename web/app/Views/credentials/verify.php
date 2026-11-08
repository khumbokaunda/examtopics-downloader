<?php /** Public. Only what is needed to verify the credential, never answers, contact details or monitoring. @var array $c @var bool $authentic @var string $state */
use App\Services\Credentials;
use App\Services\Onboarding;
$dims = array_slice(Onboarding::verification($c + ['status' => $c['institution_status']]), 0, 3);
?>
<div class="card auth-card">
    <div class="card-body">
        <h1 class="h4 mb-3">Credential verification</h1>
        <?php if ($state === 'revoked'): ?>
            <div class="alert alert-danger" role="alert"><?= icon('x-octagon') ?><div class="alert-body"><strong>Revoked</strong> on <?= e(gmdate('j F Y', (int) strtotime($c['revoked_at'] . ' UTC'))) ?>. Reason: <?= e((string) $c['revoke_reason']) ?></div></div>
        <?php elseif ($state === 'replaced'): ?>
            <div class="alert alert-warning" role="alert"><?= icon('arrow-repeat') ?><div class="alert-body"><strong>Replaced</strong> by a corrected credential<?= $c['replacement_number'] !== null ? ', ' . e((string) $c['replacement_number']) : '' ?>. This one no longer stands.</div></div>
        <?php elseif ($state === 'expired'): ?>
            <div class="alert alert-warning" role="alert"><?= icon('hourglass-bottom') ?><div class="alert-body"><strong>Expired</strong> on <?= e(gmdate('j F Y', (int) strtotime($c['expires_on'] . ' UTC'))) ?>. It was genuine, and is no longer current.</div></div>
        <?php elseif ($authentic): ?>
            <div class="alert alert-success" role="status"><?= icon('patch-check') ?><div class="alert-body"><strong>Genuine and current.</strong> Issued by <?= e((string) $c['issuer_name']) ?> through ExamLib.</div></div>
        <?php else: ?>
            <div class="alert alert-danger" role="alert"><?= icon('exclamation-octagon') ?><div class="alert-body">This record does not match its signature. Contact <?= e((string) $c['issuer_name']) ?> before relying on it.</div></div>
        <?php endif; ?>
        <dl class="row mb-3">
            <dt class="col-5">Credential</dt><dd class="col-7 mono"><?= e($c['number']) ?></dd>
            <dt class="col-5">Kind</dt><dd class="col-7"><?= e($c['title']) ?><div class="small text-muted"><?= e(Credentials::CATEGORIES[$c['category']] ?? $c['category']) ?></div></dd>
            <dt class="col-5">Holder</dt><dd class="col-7"><?= e($c['holder_name']) ?></dd>
            <dt class="col-5">Certifies</dt><dd class="col-7"><?= e($c['achievement']) ?></dd>
            <?php if ($c['programme_name'] !== null): ?><dt class="col-5">Programme</dt><dd class="col-7"><?= e($c['programme_name']) ?></dd><?php endif; ?>
            <?php if ($c['assessment_title'] !== null): ?><dt class="col-5">Assessment</dt><dd class="col-7"><?= e($c['assessment_title']) ?></dd><?php endif; ?>
            <dt class="col-5">Achieved</dt><dd class="col-7"><?= e(gmdate('j F Y', (int) strtotime($c['achieved_on'] . ' UTC'))) ?></dd>
            <dt class="col-5">Issued</dt><dd class="col-7"><?= e(gmdate('j F Y', (int) strtotime($c['issued_at'] . ' UTC'))) ?><?= $c['expires_on'] !== null ? '<div class="small text-muted">Valid until ' . e(gmdate('j F Y', (int) strtotime($c['expires_on'] . ' UTC'))) . '</div>' : '' ?></dd>
            <dt class="col-5">Signed by</dt><dd class="col-7"><?= e((string) $c['signatory_name']) ?>, <?= e((string) $c['signatory_position']) ?></dd>
            <dt class="col-5">Issuer</dt><dd class="col-7"><?= e((string) $c['issuer_name']) ?>
                <div class="small text-muted"><?= e(implode('; ', array_map(static fn(array $d): string => $d['label'] . ': ' . strtolower($d['text']), $dims))) ?></div>
                <?php if ($c['category'] === 'qualification' && $c['awarding_body'] !== null): ?><div class="small text-muted">Authorised awarding body for <?= e((string) $c['awarding_body']) ?></div><?php endif; ?>
                <?php if ($c['institution_status'] === 'suspended'): ?><div class="small text-danger">The issuer is currently suspended by ExamLib. Credentials it issued before are shown as they were issued.</div><?php endif; ?></dd>
        </dl>
        <p class="small text-muted mb-0">ExamLib shows only what is needed to check this credential. Signature key <?= e((string) $c['key_id']) ?>; public keys at <a href="<?= e(url('/verify/keys')) ?>">/verify/keys</a>. <a href="<?= e(url('/policies/credential_verification')) ?>">About verification</a>.</p>
    </div>
</div>
