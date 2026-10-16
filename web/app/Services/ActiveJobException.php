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
            'collect' => 'This exam is already ' . (($job['status'] ?? '') === 'running' ? 'being collected' : 'in the queue') . '.',
            'images' => 'This exam\'s images are already ' . (($job['status'] ?? '') === 'running' ? 'being downloaded' : 'in the queue')
                . '. Collect it again once that has finished.',
            'inventory' => 'An inventory refresh for this provider is already ' . ($job['status'] ?? 'active') . '.',
            'providers' => 'Provider discovery is already ' . ($job['status'] ?? 'active') . '.',
            default => 'A similar job is already active.',
        };
        parent::__construct($what);
    }
}
