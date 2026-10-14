<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Paths;

/**
 * Writes each message to storage/mail as an HTML file and a text file instead of sending it
 * (config mail.transport = "file"), for trying the app out without a mail server.
 */
final class FileTransport implements MailTransport
{
    public function __construct(private string $dir)
    {
    }

    public function send(MailMessage $message): void
    {
        Paths::ensureDir($this->dir);
        $name = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $head = 'To: ' . $message->to . "\nFrom: " . $message->fromName . ' <' . $message->fromAddress . ">\nSubject: " . $message->subject . "\n\n";
        file_put_contents($this->dir . '/' . $name . '.txt', $head . $message->text);
        file_put_contents($this->dir . '/' . $name . '.html', $message->html);
    }
}
