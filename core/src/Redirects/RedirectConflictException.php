<?php

declare(strict_types=1);

namespace BsCms\Redirects;

use RuntimeException;

/**
 * Thrown when attempting to create a redirect rule whose from_path and match_type already exist.
 * Maps to HTTP 409 Conflict.
 */
final class RedirectConflictException extends RuntimeException
{
    public function __construct(string $message = 'A redirect rule for this path and match type already exists.')
    {
        parent::__construct($message, 409);
    }
}
