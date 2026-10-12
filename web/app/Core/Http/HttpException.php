<?php

declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

/** An error whose message is safe to show to the (authenticated) user. */
final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message, $status);
    }

    public static function notFound(string $what = 'Page'): self
    {
        return new self(404, $what . ' not found.');
    }

    public static function badRequest(string $message): self
    {
        return new self(400, $message);
    }
}
