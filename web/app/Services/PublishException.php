<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** A dataset failed validation and was not published. */
final class PublishException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Dataset rejected: ' . implode(' ', array_slice($errors, 0, 5)));
    }
}
