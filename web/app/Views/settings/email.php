<?php
/** @var array{host:string,port:int,encryption:string,username:string,password:string,from_address:string,from_name:string,site_url:string} $mail
 *  @var bool $passwordStored @var bool $configured @var bool $library @var array<string,int> $counts @var list<array> $recent @var string $me
 *  @var array<string,string> $encryptions @var list<array{user:array,ok:bool,reason:string}> $recipients */
$tone = ['sent' => 'success', 'queued' => 'neutral', 'sending' => 'info', 'failed' => 'danger'];
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('/settings')) ?>">Settings</a></li>
    <li class="breadcrumb-item active" aria-current="page">Email</li>
</ol></nav>
<header class="page-header">
    <div class="page-header-text">
        <h1 class="page-title">Email</h1>
        <p class="page-subtitle">The mail server used for sign-up codes, password resets and notifications.</p>
    </div>
    <div class="page-actions"><?= $configured ? chip('set up', 'success', 'check2-circle') : chip('not set up', 'warning', 'exclamation-triangle') ?></div>
</header>

<?php if (!$configured): ?>
    <div class="alert alert-info"><?= icon('info-circle') ?><div class="alert-body">Until email is set up, the sign-in page does not offer <strong>Create an account</strong> or <strong>Forgot it?</strong>, because both send a code by email. Fill in the mail server below and save; the links then appear.</div></div>
<?php endif; ?>
<?php if (!$library): ?>
    <div class="alert alert-danger"><?= icon('exclamation-octagon') ?><div class="alert-body">The mail library is not installed, so nothing can be sent. On the server, run <code>composer install --no-dev</code> in the <code>web</code> folder.</div></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('envelope-at') ?>Mail server (SMTP)</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/settings/email')) ?>" data-once autocomplete="off"><?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label" for="host">Host</label><input class="form-control" id="host" name="host" value="<?= e($mail['host']) ?>" placeholder="smtp.gmail.com"></div>
                        <div class="col-md-4"><label class="form-label" for="port">Port</label><input class="form-control" id="port" name="port" inputmode="numeric" value="<?= e((string) $mail['port']) ?>"></div>
                        <div class="col-md-6">
                            <label class="form-label" for="encryption">Encryption</label>
                            <select class="form-select" id="encryption" name="encryption">
                                <?php foreach ($encryptions as $k => $label): ?><option value="<?= e($k) ?>" <?= $mail['encryption'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" value="<?= e($mail['username']) ?>" autocomplete="off"></div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Password</label>
                            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" placeholder="<?= $passwordStored ? 'Stored (leave empty to keep)' : '' ?>">
                            <div class="form-text">Leave empty to keep the stored password. It is never shown again.</div>
                        </div>
                        <div class="col-md-6"><label class="form-label" for="from_address">From address</label><input class="form-control" id="from_address" name="from_address" type="email" value="<?= e($mail['from_address']) ?>"><div class="form-text">Empty: the username, if it is an address.</div></div>
                        <div class="col-md-6"><label class="form-label" for="from_name">From name</label><input class="form-control" id="from_name" name="from_name" maxlength="100" value="<?= e($mail['from_name']) ?>"><div class="form-text">Empty: the system name.</div></div>
                        <div class="col-md-6"><label class="form-label" for="site_url">Site address</label><input class="form-control" id="site_url" name="site_url" value="<?= e($mail['site_url']) ?>" placeholder="https://examtopics.example.com"><div class="form-text">Links in emails start with this.</div></div>
                    </div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check2') ?>Save email settings</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card mb-4" id="test">
            <div class="card-header"><span class="card-title"><?= icon('send') ?>Send a test message</span></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/settings/email/test')) ?>" class="toolbar" data-once data-busy="Sending…"><?= csrf_field() ?>
                    <label class="visually-hidden" for="to">Send to</label>
                    <input class="form-control" id="to" name="to" type="email" value="<?= e($me) ?>" placeholder="you@example.com">
                    <button class="btn btn-outline-primary" type="submit" <?= $configured ? '' : 'disabled' ?>><?= icon('send') ?>Send</button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('google') ?>Using Gmail</span></div>
            <div class="card-body small">
                <ol class="ps-3 mb-2">
                    <li>Host <code>smtp.gmail.com</code>, port <code>587</code>, STARTTLS.</li>
                    <li>Username: the full Gmail address.</li>
                    <li>Password: a 16-character <strong>app password</strong>, not your normal one. Two-step verification must be on first; create one in your Google account under Security, App passwords (<code>myaccount.google.com/apppasswords</code>).</li>
                </ol>
                <p class="text-muted mb-0">The password is stored in the database, so backups contain it. Keep backup files private.</p>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card" id="recipients">
            <div class="card-header"><span class="card-title"><?= icon('people') ?>Who gets notification emails</span><span class="small text-muted">People told about exam requests and sign-ups</span></div>
            <ul class="list-rows">
                <?php foreach ($recipients as $r): ?>
                    <li>
                        <div class="row-main">
                            <span class="icon-bubble" data-tone="<?= $r['ok'] ? 'success' : 'warning' ?>"><?= icon($r['ok'] ? 'envelope-check' : 'envelope-exclamation') ?></span>
                            <div class="row-text">
                                <div class="row-title"><?= e(\App\Services\Access::displayName($r['user'])) ?> <span class="text-muted fw-normal">· <?= e($r['user']['username']) ?></span></div>
                                <div class="row-meta"><?= e($r['reason']) ?></div>
                            </div>
                        </div>
                        <div class="row-side"><?= $r['ok'] ? chip('emailed', 'success') : chip('inbox only', 'warning') ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="card-footer small text-muted">Everyone also sees their notifications under the bell. Someone who makes a request is not notified of their own request.</div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><span class="card-title"><?= icon('mailbox') ?>Notification emails</span>
                <span class="chip-group"><?php foreach ($counts as $status => $n): ?><?= chip($n . ' ' . $status, $tone[$status] ?? 'neutral') ?><?php endforeach; ?></span></div>
            <?php if ($recent === []): ?>
                <div class="card-body"><?= empty_state('mailbox', 'No notification emails yet', 'Emails about requests and sign-ups are listed here, and retried if a send fails.', null, true) ?></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead><tr><th>Queued</th><th>To</th><th>Subject</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $m): ?>
                        <tr>
                            <td class="small stack-title"><?= e(fmt_time($m['created_at'])) ?></td>
                            <td class="small" data-label="To"><?= e($m['to_email']) ?></td>
                            <td class="small" data-label="Subject"><?= e($m['subject']) ?></td>
                            <td data-label="Status"><?= chip((string) $m['status'], $tone[$m['status']] ?? 'neutral') ?><?php if ($m['last_error']): ?><span class="cell-sub text-muted"><?= e($m['last_error']) ?>, <?= (int) $m['attempts'] ?> attempt<?= (int) $m['attempts'] === 1 ? '' : 's' ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
