<?php

declare(strict_types=1);

namespace BsCms\Publishing;

/**
 * Presenter for BuildToken records.
 * Raw token secret is NEVER exposed here; only prefix, hash, and metadata.
 */
final class BuildTokenPresenter
{
    /** @var list<string> */
    public const ALLOWED_FIELDS = [
        'id',
        'name',
        'token_prefix',
        'last_used_at',
        'revoked_at',
        'created_at',
    ];

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    public static function present(array $token): array
    {
        $presented = [
            'id' => (string) ($token['id'] ?? ''),
            'name' => (string) ($token['name'] ?? ''),
            'token_prefix' => (string) ($token['token_prefix'] ?? ''),
            'last_used_at' => isset($token['last_used_at']) ? (string) $token['last_used_at'] : null,
            'revoked_at' => isset($token['revoked_at']) ? (string) $token['revoked_at'] : null,
            'created_at' => (string) ($token['created_at'] ?? ''),
        ];

        return array_intersect_key($presented, array_flip(self::ALLOWED_FIELDS));
    }

    /**
     * @param list<array<string, mixed>> $tokens
     * @return list<array<string, mixed>>
     */
    public static function presentCollection(array $tokens): array
    {
        return array_map([self::class, 'present'], $tokens);
    }
}
