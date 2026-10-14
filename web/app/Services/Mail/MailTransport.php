<?php

declare(strict_types=1);

namespace App\Services\Mail;

/** Delivers a message, or throws with the reason it could not. */
interface MailTransport
{
    public function send(MailMessage $message): void;
}
