<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** A job of the same kind is already queued or running. */
final class ActiveJobException extends RuntimeException
{
    /** @param array<string,mixed>|null $job the job holding the slot */
    public function __construct(public readonly ?array $job)
    {
        $what = match ($job['type'] ?? '') {
            'collect' => 'A collection job is already ' . ($job['status'] ?? 'active')
                . '. Only one collection or image download runs at a time to avoid overloading the source site.',
            'images' => 'An image download is already ' . ($job['status'] ?? 'active')
                . '. Only one collection or image download runs at a time to avoid overloading the source site.',
            'inventory' => 'An inventory refresh for this provider is already ' . ($job['status'] ?? 'active') . '.',
            'providers' => 'Provider discovery is already ' . ($job['status'] ?? 'active') . '.',
            default => 'A similar job is already active.',
        };
        parent::__construct($what);
    }
}
