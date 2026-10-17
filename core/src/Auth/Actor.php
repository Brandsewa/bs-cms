<?php

declare(strict_types=1);

namespace BsCms\Auth;

/**
 * Value object representing the authenticated actor for the current request or operation.
 * Used by Authorizer, AuditLogger, and Controllers.
 */
final class Actor
{
    public const TYPE_USER = 'user';
    public const TYPE_AI_CLIENT = 'ai_client';
    public const TYPE_API_TOKEN = 'api_token';
    public const TYPE_SYSTEM = 'system';
    public const TYPE_PLUGIN = 'plugin';

    public const AI_MODE_DRAFT_ONLY = 'draft_only';
    public const AI_MODE_APPROVAL_REQUIRED = 'approval_required';
    public const AI_MODE_FULL = 'full';

    /**
     * @param list<string> $roleIds
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $onBehalfOf = null,
        public readonly array $roleIds = [],
        public readonly ?string $aiMode = null
    ) {
    }

    public static function system(): self
    {
        return new self(
            type: self::TYPE_SYSTEM,
            id: 'system',
            label: 'System Kernel',
            onBehalfOf: null,
            roleIds: [],
            aiMode: null
        );
    }

    /**
     * @param list<string> $roleIds
     */
    public static function user(string $id, string $name = '', array $roleIds = []): self
    {
        return new self(
            type: self::TYPE_USER,
            id: $id,
            label: $name !== '' ? $name : $id,
            onBehalfOf: $id,
            roleIds: $roleIds,
            aiMode: null
        );
    }

    /**
     * @param list<string> $roleIds
     */
    public static function apiToken(string $id, string $name, string $userId, array $roleIds, string $aiMode = self::AI_MODE_DRAFT_ONLY): self
    {
        return new self(
            type: self::TYPE_API_TOKEN,
            id: $id,
            label: $name,
            onBehalfOf: $userId,
            roleIds: $roleIds,
            aiMode: $aiMode
        );
    }

    /**
     * @param list<string> $roleIds
     */
    public static function aiClient(string $id, string $clientName, string $userId, array $roleIds, string $aiMode = self::AI_MODE_DRAFT_ONLY): self
    {
        return new self(
            type: self::TYPE_AI_CLIENT,
            id: $id,
            label: $clientName,
            onBehalfOf: $userId,
            roleIds: $roleIds,
            aiMode: $aiMode
        );
    }

    public function isSystem(): bool
    {
        return $this->type === self::TYPE_SYSTEM;
    }

    public function isUser(): bool
    {
        return $this->type === self::TYPE_USER;
    }

    public function isAi(): bool
    {
        return $this->type === self::TYPE_AI_CLIENT || $this->type === self::TYPE_API_TOKEN;
    }

    public function hasRole(string $roleId): bool
    {
        return in_array($roleId, $this->roleIds, true);
    }
}
