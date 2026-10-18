<?php

declare(strict_types=1);

namespace BsCms\Operations\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base exception for operations layer failures.
 */
class OperationException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
