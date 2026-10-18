<?php

declare(strict_types=1);

namespace BsCms\Operations\Exceptions;

/**
 * Thrown when an operation requires step-up re-authentication.
 * Maps to RFC 9457 Problem Details with HTTP 403 and error code 'step_up_required'.
 */
final class StepUpRequiredException extends OperationException
{
    public function __construct(string $message = 'This operation requires step-up re-authentication.')
    {
        parent::__construct($message, 403);
    }
}
