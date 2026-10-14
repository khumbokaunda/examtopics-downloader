<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Mail\MailMessage;
use App\Services\Mail\MailTransport;

/** Keeps sent messages in memory; fails while $fail is set. */
final class ArrayTransport implements MailTransport
{
    /** @var list<MailMessage> */
    public array $sent = [];
    public bool $fail = false;

    public function send(MailMessage $message): void
    {
        if ($this->fail) {
            throw new \RuntimeException('SMTP Error: Could not authenticate.');
        }
        $this->sent[] = $message;
    }
}
