<?php

declare(strict_types=1);

namespace App\Services\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/** SMTP with authentication, through PHPMailer: STARTTLS on 587, SSL on 465. */
final class SmtpTransport implements MailTransport
{
    /** @param array{host:string,port:int,encryption:string,username:string,password:string} $settings */
    public function __construct(private array $settings, private int $timeout = 20)
    {
    }

    public static function available(): bool
    {
        return class_exists(PHPMailer::class);
    }

    public function send(MailMessage $message): void
    {
        if (!self::available()) {
            throw new RuntimeException('The mail library is not installed. On the server, run composer install --no-dev in the web folder.');
        }
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->settings['host'];
        $mail->Port = $this->settings['port'];
        $mail->Timeout = $this->timeout;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        if ($this->settings['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($this->settings['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        if ($this->settings['username'] !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $this->settings['username'];
            $mail->Password = $this->settings['password'];
        }
        $mail->setFrom($message->fromAddress, $message->fromName);
        $mail->addAddress($message->to, (string) $message->toName);
        $mail->Subject = $message->subject;
        $mail->isHTML(true);
        $mail->Body = $message->html;
        $mail->AltBody = $message->text;
        foreach ($message->embeds as $cid => $path) {
            $mail->addEmbeddedImage($path, $cid, basename($path));
        }
        $mail->send();
    }
}
