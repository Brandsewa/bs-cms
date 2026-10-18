<?php

declare(strict_types=1);

namespace BsCms\Content;

use RuntimeException;

final class OptimisticConcurrencyException extends RuntimeException
{
    /**
     * @param array<string, mixed> $currentEntry
     */
    public function __construct(
        string $message,
        public readonly array $currentEntry
    ) {
        parent::__construct($message, 409);
    }
}
