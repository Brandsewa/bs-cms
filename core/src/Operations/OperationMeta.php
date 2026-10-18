<?php

declare(strict_types=1);

namespace BsCms\Operations;

/**
 * Metadata declaring operation capabilities, policies, and schemas.
 */
final class OperationMeta
{
    public const AI_POLICY_ALLOWED = 'allowed';
    public const AI_POLICY_DRAFT_ONLY_BLOCKED = 'draft_only_blocked';
    public const AI_POLICY_NEEDS_APPROVAL = 'needs_approval';

    /**
     * @param string $summary Human-readable summary of the capability
     * @param array<string, mixed> $inputSchema JSON Schema describing valid inputs
     * @param array<string, mixed>|null $outputSchema Optional JSON Schema describing output data
     * @param bool $mutates True if the operation modifies state and should run in a transaction
     * @param bool $destructive True if the operation deletes or drops data (requires confirm token)
     * @param bool $bulk True if the operation acts on multiple items (requires confirm token above threshold)
     * @param bool $idempotent True if repeating the operation with same input produces same outcome
     * @param bool $requiresStepUp True if user must have recently verified 2FA / password
     * @param string $aiPolicy Policy for AI clients/tokens: allowed | draft_only_blocked | needs_approval
     * @param string $rateLimitBucket Rate limit bucket name for this operation
     */
    public function __construct(
        public readonly string $summary,
        public readonly array $inputSchema = [],
        public readonly ?array $outputSchema = null,
        public readonly bool $mutates = false,
        public readonly bool $destructive = false,
        public readonly bool $bulk = false,
        public readonly bool $idempotent = false,
        public readonly bool $requiresStepUp = false,
        public readonly string $aiPolicy = self::AI_POLICY_ALLOWED,
        public readonly string $rateLimitBucket = 'api'
    ) {
    }
}
