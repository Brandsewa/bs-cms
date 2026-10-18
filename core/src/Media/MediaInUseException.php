<?php

declare(strict_types=1);

namespace BsCms\Media;

use BsCms\Operations\Exceptions\OperationException;

/**
 * Thrown when attempting to delete media currently in use without force=true.
 * Maps to RFC 9457 Problem Details with HTTP 409 Conflict.
 */
final class MediaInUseException extends OperationException
{
    /**
     * @param list<array<string, mixed>> $usages
     */
    public function __construct(
        string $message = 'Media item is currently in use.',
        public readonly array $usages = []
    ) {
        parent::__construct($message, 409);
    }
}
