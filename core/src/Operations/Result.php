<?php

declare(strict_types=1);

namespace BsCms\Operations;

/**
 * Standard structured result returned by Operation::handle.
 */
final class Result
{
    /**
     * @param array<string, mixed> $data Primary response data
     * @param int $statusCode HTTP status code equivalent (200, 201, 204)
     * @param array<string, mixed> $meta Additional pagination or execution metadata
     * @param list<array<string, mixed>> $events Domain events queued during execution
     */
    public function __construct(
        public readonly array $data = [],
        public readonly int $statusCode = 200,
        public readonly array $meta = [],
        public readonly array $events = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param list<array<string, mixed>> $events
     */
    public static function ok(array $data = [], array $meta = [], array $events = []): self
    {
        return new self($data, 200, $meta, $events);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param list<array<string, mixed>> $events
     */
    public static function created(array $data = [], array $meta = [], array $events = []): self
    {
        return new self($data, 201, $meta, $events);
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<array<string, mixed>> $events
     */
    public static function noContent(array $meta = [], array $events = []): self
    {
        return new self([], 204, $meta, $events);
    }
}
