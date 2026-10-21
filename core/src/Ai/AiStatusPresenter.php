<?php

declare(strict_types=1);

namespace BsCms\Ai;

final class AiStatusPresenter
{
    /**
     * @param array<string, mixed> $data
     * @return array{ai_disabled: bool, active_grants_count: int, active_ai_tokens_count: int, pending_changes_count: int}
     */
    public static function present(array $data): array
    {
        return [
            'ai_disabled' => (bool) ($data['ai_disabled'] ?? false),
            'active_grants_count' => (int) ($data['active_grants_count'] ?? 0),
            'active_ai_tokens_count' => (int) ($data['active_ai_tokens_count'] ?? 0),
            'pending_changes_count' => (int) ($data['pending_changes_count'] ?? 0),
        ];
    }
}
