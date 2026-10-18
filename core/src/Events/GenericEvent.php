<?php

declare(strict_types=1);

namespace BsCms\Events;

final class GenericEvent implements Event
{
    private string $occurredAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly string $name,
        private readonly array $payload,
        ?string $occurredAt = null
    ) {
        $this->occurredAt = $occurredAt ?? gmdate('Y-m-d\TH:i:s.v\Z');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function occurredAt(): string
    {
        return $this->occurredAt;
    }
}
