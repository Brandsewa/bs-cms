<?php

declare(strict_types=1);

namespace BsCms\Operations\Exceptions;

/**
 * Thrown when a destructive or bulk operation requires two-step confirmation.
 * Maps to RFC 9457 Problem Details with HTTP 409 Conflict and a confirm_token.
 */
final class ConfirmationRequiredException extends OperationException
{
    public function __construct(
        string $message,
        public readonly string $confirmToken,
        public readonly int $expiresIn = 300
    ) {
        parent::__construct($message, 409);
    }
}
