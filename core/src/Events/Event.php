<?php

declare(strict_types=1);

namespace BsCms\Events;

interface Event
{
    /**
     * Unique dot-notated event name, e.g. 'entry.created', 'entry.published'.
     */
    public function name(): string;

    /**
     * Event payload serialized as an array.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;

    /**
     * ISO-8601 UTC timestamp string of when the event occurred.
     */
    public function occurredAt(): string;
}
