<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\Mail\FileTransport;
use App\Services\Mail\MailMessage;
use App\Services\Mail\MailTransport;
use App\Services\Mail\SmtpTransport;

/**
 * Email. SMTP settings are kept in the database (Settings > Email) so the mail provider can
 * change without server access; config.php's "mail" values are the defaults.
 *
 * send() never throws: it returns false and logs why (mail.log), because a page such as
 * "forgot password" must not fail with a server error when mail is down. Callers pass a short
 * HTML fragment; it is wrapped in the letterhead here, so no email can forget the branding,
 * and the plain-text part is made from the fragment (not the letterhead). Emails about events
 * go through MailQueue, which retries.
 */
final class Mailer
{
    public const ENCRYPTIONS = ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None (a local relay)'];

    public function __construct(private App $app, private Branding $branding, private ?MailTransport $transport = null)
    {
    }

    /** @return array{host:string,port:int,encryption:string,username:string,password:string,from_address:string,from_name:string,site_url:string} */
    public function settings(): array
    {
        $config = $this->app->config();
        $values = [
            'host' => (string) $config->get('mail.host', ''),
            'port' => (int) $config->get('mail.port', 587),
            'encryption' => (string) $config->get('mail.encryption', 'tls'),
            'username' => (string) $config->get('mail.username', ''),
            'password' => (string) $config->get('mail.password', ''),
            'from_address' => (string) $config->get('mail.from_address', ''),
            'from_name' => (string) $config->get('mail.from_name', ''),
            'site_url' => (string) $config->get('app_url', ''),
        ];
        try {
            $stored = new SettingsRepository($this->app->db());
            foreach (['host', 'encryption', 'username', 'password', 'from_address', 'from_name'] as $k) {
                $v = $stored->get('mail.' . $k);
                if ($v !== null && $v !== '') {
                    $values[$k] = $v;
                }
            }
            $port = $stored->get('mail.port');
            if ($port !== null && ctype_digit($port)) {
                $values['port'] = (int) $port;
            }
            $site = $stored->get('app_url');
            if ($site !== null && $site !== '') {
                $values['site_url'] = $site;
            }
        } catch (\Throwable) {
            // No database: the configured values stand.
        }
        if (!isset(self::ENCRYPTIONS[$values['encryption']])) {
            $values['encryption'] = 'tls';
        }
        $values['site_url'] = rtrim($values['site_url'], '/');
        return $values;
    }

    /**
     * Save the form. A blank password keeps the stored one; the form never shows it back.
     *
     * @param array<string,mixed> $input
     * @return string|null an error to show, or null when saved
     */
    public function save(array $input): ?string
    {
        $host = trim((string) ($input['host'] ?? ''));
        $port = trim((string) ($input['port'] ?? ''));
        $encryption = (string) ($input['encryption'] ?? 'tls');
        $from = trim((string) ($input['from_address'] ?? ''));
        $site = rtrim(trim((string) ($input['site_url'] ?? '')), '/');
        if ($host !== '' && preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host) !== 1) {
            return 'The host is a server name such as smtp.gmail.com.';
        }
        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            return 'The port is a number such as 587.';
        }
        if (!isset(self::ENCRYPTIONS[$encryption])) {
            return 'Choose an encryption.';
        }
        if ($from !== '' && !UserRepository::validEmail($from)) {
            return 'The from address must be an email address.';
        }
        if ($site !== '' && preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#', $site) !== 1) {
            return 'The site address is the address people open, such as https://examtopics.example.com.';
        }
        $s = new SettingsRepository($this->app->db());
        $s->set('mail.host', $host);
        $s->set('mail.port', $port !== '' ? (string) (int) $port : '587');
        $s->set('mail.encryption', $encryption);
        $s->set('mail.username', trim((string) ($input['username'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if ($password !== '') {
            $s->set('mail.password', $password);
        }
        $s->set('mail.from_address', $from);
        $s->set('mail.from_name', mb_substr(trim((string) ($input['from_name'] ?? '')), 0, 100));
        $s->set('app_url', $site);
        return null;
    }

    public function configured(): bool
    {
        $s = $this->settings();
        return $s['host'] !== '' && $this->fromAddress($s) !== '';
    }

    /** An absolute link into the app, or null while no site address is set. */
    public function link(string $path): ?string
    {
        $site = $this->settings()['site_url'];
        return $site !== '' ? $site . $path : null;
    }

    /**
     * Send one email. Never throws.
     *
     * @param string $fragment HTML paragraphs and links, already escaped by the caller
     */
    public function send(string $to, string $subject, string $fragment, ?string $text = null, ?string $toName = null): bool
    {
        $log = $this->app->logger();
        try {
            if (!UserRepository::validEmail($to)) {
                $log->warning('mail', 'Mail skipped: not an email address', ['subject' => $subject]);
                return false;
            }
            if (!$this->configured()) {
                $log->warning('mail', 'Mail skipped: email is not set up (Settings > Email)', ['to' => $to, 'subject' => $subject]);
                return false;
            }
            $s = $this->settings();
            $logo = $this->emailLogo();
            $message = new MailMessage(
                $to, $toName, $subject,
                $this->letterhead($fragment, $logo !== null),
                $text ?? self::textFrom($fragment),
                $this->fromAddress($s),
                $s['from_name'] !== '' ? $s['from_name'] : $this->branding->get()['name'],
                $logo !== null ? ['logo' => $logo] : [],
            );
            $this->transport($s)->send($message);
            $log->info('mail', 'Mail sent', ['to' => $to, 'subject' => $subject]);
            return true;
        } catch (\Throwable $e) {
            $log->error('mail', 'Mail failed', ['to' => $to, 'subject' => $subject, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** The letterhead around a fragment: inline styles and tables, because mail clients strip the rest. */
    public function letterhead(string $fragment, bool $withLogo): string
    {
        $b = $this->branding->get();
        $site = $this->settings()['site_url'];
        $font = 'font-family:Helvetica,Arial,sans-serif;';
        $logo = $withLogo ? '<img src="cid:logo" alt="" style="max-height:56px;max-width:220px;display:block;margin:0 auto 12px;border:0">' : '';
        $tagline = $b['tagline'] !== '' ? '<div style="font-size:13px;color:#6b7280;margin-top:4px">' . e($b['tagline']) . '</div>' : '';
        $siteLine = $site !== '' ? '<div style="margin-top:4px"><a href="' . e($site) . '" style="color:#6b7280">' . e(preg_replace('#^https?://#', '', $site)) . '</a></div>' : '';
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
            . '<body style="margin:0;padding:0;background:#f4f5f7">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3e5e8;border-radius:10px;' . $font . 'color:#1f2329">'
            . '<tr><td align="center" style="padding:28px 32px 8px;border-bottom:1px solid #eceef0">' . $logo
            . '<div style="font-size:18px;font-weight:600">' . e($b['name']) . '</div>' . $tagline . '<div style="height:12px"></div></td></tr>'
            . '<tr><td style="padding:24px 32px;font-size:15px;line-height:1.55">' . $fragment . '</td></tr>'
            . '<tr><td align="center" style="padding:16px 32px 24px;border-top:1px solid #eceef0;font-size:12px;color:#6b7280">'
            . '<div>' . e($b['name']) . '</div>' . $siteLine
            . '<div style="margin-top:8px">This message was sent automatically. Please do not reply.</div></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** Plain text from a fragment: links become "text (address)", paragraphs blank lines. */
    public static function textFrom(string $fragment): string
    {
        $t = preg_replace_callback('#<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', static function (array $m): string {
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = trim(strip_tags($m[2]));
            return $label === '' || $label === $href ? $href : $label . ' (' . $href . ')';
        }, $fragment) ?? $fragment;
        $t = preg_replace(['#<br\s*/?>#i', '#</(p|div|h[1-6]|ul|ol|table|tr)>#i', '#<li[^>]*>#i'], ["\n", "\n\n", '- '], $t) ?? $t;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+\n/", "\n", $t) ?? $t;
        return trim(preg_replace("/\n{3,}/", "\n\n", $t) ?? $t) . "\n";
    }

    /** Only PNG logos are embedded: no mainstream mail client shows SVG, so the name stands alone. */
    private function emailLogo(): ?string
    {
        $file = $this->branding->logoFile();
        return $file !== null && str_ends_with($file, '.png') && is_readable($file) ? $file : null;
    }

    /** @param array{username:string,from_address:string} $s */
    private function fromAddress(array $s): string
    {
        if ($s['from_address'] !== '') {
            return $s['from_address'];
        }
        return UserRepository::validEmail($s['username']) ? $s['username'] : '';
    }

    /** @param array{host:string,port:int,encryption:string,username:string,password:string} $s */
    private function transport(array $s): MailTransport
    {
        if ($this->transport !== null) {
            return $this->transport;
        }
        if ($this->app->config()->get('mail.transport', 'smtp') === 'file') {
            return new FileTransport($this->app->paths()->storage() . '/mail');
        }
        return new SmtpTransport($s);
    }
}
