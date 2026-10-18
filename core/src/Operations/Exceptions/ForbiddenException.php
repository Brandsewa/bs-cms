<?php

declare(strict_types=1);

namespace BsCms\Operations\Exceptions;

/**
 * Thrown when actor lacks required permissions or fails policy checks.
 * Maps to RFC 9457 Problem Details with HTTP 403.
 */
final class ForbiddenException extends OperationException
{
    public function __construct(string $message = 'Forbidden.')
    {
        parent::__construct($message, 403);
    }
}
