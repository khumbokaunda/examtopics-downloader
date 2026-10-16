<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Repositories\UserRepository;
use App\Services\Mail\SmtpTransport;
use App\Services\Access;
use App\Services\Mailer;
use App\Services\Notifier;

/** Settings > Email: the SMTP server, the site address used in links, and a test message. */
final class EmailController extends Controller
{
    public function index(): Response
    {
        $mailer = $this->s->mailer();
        $settings = $mailer->settings();
        return $this->render('settings/email', [
            'title' => 'Email',
            'nav' => 'settings',
            'mail' => ['password' => ''] + $settings,
            'passwordStored' => $settings['password'] !== '',
            'configured' => $mailer->configured(),
            'library' => SmtpTransport::available(),
            'counts' => $this->s->mailQueue()->counts(),
            'recent' => $this->s->mailQueue()->recent(15),
            'me' => (string) ($this->auth->user()['email'] ?? ''),
            'encryptions' => Mailer::ENCRYPTIONS,
            'recipients' => $this->recipients(),
        ]);
    }

    /**
     * The people told about requests and sign-ups, and whether each gets those notifications
     * by email: only confirmed addresses of people who have not turned email off do.
     *
     * @return list<array{user:array<string,mixed>,ok:bool,reason:string}>
     */
    private function recipients(): array
    {
        $out = [];
        foreach ($this->s->users()->all() as $u) {
            if ($u['status'] !== 'active' || !(Access::can($u, 'requests.manage') || Access::can($u, 'users.manage'))) {
                continue;
            }
            $prefs = json_decode((string) ($u['preferences'] ?? ''), true);
            $reason = match (true) {
                $u['email'] === null => 'No email address on the account.',
                $u['email_verified_at'] === null => 'The address is not confirmed yet: confirm it with a code on the account page.',
                is_array($prefs) && ($prefs['email_notifications'] ?? '1') === '0' => 'Turned emails off on the account page.',
                default => 'Gets them at ' . $u['email'] . '.',
            };
            $out[] = ['user' => $u, 'ok' => Notifier::wantsEmail($u), 'reason' => $reason];
        }
        return $out;
    }

    public function save(): Response
    {
        $input = [];
        foreach (['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name', 'site_url'] as $k) {
            $input[$k] = (string) $this->request->input($k, '');
        }
        $error = $this->s->mailer()->save($input);
        if ($error !== null) {
            $this->flash('danger', $error);
        } else {
            // The host only: never the password.
            $this->app->logger()->info('security', 'Email settings saved', ['username' => $this->auth->user()['username'] ?? null, 'host' => $input['host']]);
            $this->flash('success', 'Email settings saved.' . ($input['password'] === '' ? ' The stored password was kept.' : ''));
        }
        return $this->redirect('/settings/email');
    }

    public function test(): Response
    {
        $to = UserRepository::normalizeEmail((string) $this->request->input('to', ''));
        $to = $to !== '' ? $to : (string) ($this->auth->user()['email'] ?? '');
        if (!UserRepository::validEmail($to)) {
            $this->flash('danger', 'Type an address to send the test message to.');
            return $this->redirect('/settings/email#test');
        }
        $mailer = $this->s->mailer();
        $site = $mailer->settings()['site_url'];
        $ok = $mailer->send($to, 'Test message', '<p>This is a test message from ' . e($this->s->branding()->get()['name']) . '. Email is working.</p>'
            . ($site !== '' ? '<p>Links in emails will point to <a href="' . e($site) . '">' . e($site) . '</a>.</p>' : ''));
        $this->flash($ok ? 'success' : 'danger', $ok
            ? 'Test message sent to ' . $to . '. If it does not arrive, look in the spam folder.'
            : 'Send failed. Check storage/logs/mail.log on the server for the SMTP error, and recheck the host, port, encryption, username and password.');
        return $this->redirect('/settings/email#test');
    }
}
