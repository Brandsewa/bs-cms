<?php

declare(strict_types=1);

namespace BsCms\Operations;

use BsCms\Auth\Actor;
use BsCms\Database\Connection;
use DateTimeImmutable;

/**
 * Execution context provided to operations by the Dispatcher.
 */
final class Context
{
    public function __construct(
        public readonly ?Actor $actor,
        public readonly string $requestId,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?DateTimeImmutable $clock = null,
        public readonly bool $dryRun = false,
        public readonly ?string $confirmToken = null,
        public readonly ?Connection $connection = null
    ) {
    }

    public function withConfirmToken(?string $confirmToken): self
    {
        return new self(
            actor: $this->actor,
            requestId: $this->requestId,
            ip: $this->ip,
            userAgent: $this->userAgent,
            clock: $this->clock,
            dryRun: $this->dryRun,
            confirmToken: $confirmToken,
            connection: $this->connection
        );
    }
}
