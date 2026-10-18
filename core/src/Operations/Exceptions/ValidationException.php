<?php

declare(strict_types=1);

namespace BsCms\Operations\Exceptions;

/**
 * Thrown when operation input fails schema or business validation.
 * Maps to RFC 9457 Problem Details with HTTP 422.
 */
final class ValidationException extends OperationException
{
    /**
     * @param array<string, string|list<string>> $errors
     */
    public function __construct(
        string $message = 'Input validation failed.',
        public readonly array $errors = []
    ) {
        parent::__construct($message, 422);
    }
}
