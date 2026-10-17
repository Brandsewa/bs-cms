<?php

declare(strict_types=1);

namespace BsCms\Audit;

use DateTimeImmutable;

/**
 * Value object representing an audit log entry.
 */
final class AuditEntry
{
    /**
     * @param array<string, mixed>|null $diff
     */
    public function __construct(
        public readonly int $id,
        public readonly string $requestId,
        public readonly string $actorType,
        public readonly ?string $actorId,
        public readonly ?string $actorLabel,
        public readonly ?string $onBehalfOf,
        public readonly string $action,
        public readonly string $subjectType,
        public readonly ?string $subjectId,
        public readonly ?string $collectionKey,
        public readonly ?string $summary,
        public readonly ?array $diff,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly string $result,
        public readonly DateTimeImmutable $createdAt
    ) {
    }
}
