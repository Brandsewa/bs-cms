<?php

declare(strict_types=1);

namespace BsCms\Ai;

final class AiDisconnectPresenter
{
    /**
     * @param array<string, mixed> $data
     * @return array{status: string, ai_disabled: bool, revoked_grants_count: int, revoked_tokens_count: int, expired_pending_changes_count: int}
     */
    public static function present(array $data): array
    {
        return [
            'status' => (string) ($data['status'] ?? 'disconnected'),
            'ai_disabled' => (bool) ($data['ai_disabled'] ?? true),
            'revoked_grants_count' => (int) ($data['revoked_grants_count'] ?? 0),
            'revoked_tokens_count' => (int) ($data['revoked_tokens_count'] ?? 0),
            'expired_pending_changes_count' => (int) ($data['expired_pending_changes_count'] ?? 0),
        ];
    }
}
