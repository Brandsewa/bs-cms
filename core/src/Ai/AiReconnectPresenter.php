<?php

declare(strict_types=1);

namespace BsCms\Ai;

final class AiReconnectPresenter
{
    /**
     * @param array<string, mixed> $data
     * @return array{status: string, ai_disabled: bool}
     */
    public static function present(array $data): array
    {
        return [
            'status' => 'connected',
            'ai_disabled' => false,
        ];
    }
}
