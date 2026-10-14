<?php

declare(strict_types=1);

namespace App\Services\Mail;

/** One email, ready for a transport. */
final class MailMessage
{
    /** @param array<string,string> $embeds content ID => file path (images shown inline) */
    public function __construct(
        public readonly string $to,
        public readonly ?string $toName,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly array $embeds = [],
    ) {
    }
}
