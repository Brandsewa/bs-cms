<?php

declare(strict_types=1);

namespace BsCms\Users;

/**
 * Presenter / DTO for User entities.
 * Enforces a strict allow-list of safe public attributes.
 * Prevents credentials, password hashes, and 2FA secrets from leaking across any API response.
 */
final class UserPresenter
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'id',
        'email',
        'name',
        'status',
        'avatar_media_id',
        'locale',
        'timezone',
        'two_factor_enabled',
        'roles',
        'created_at',
        'updated_at',
        'last_login_at',
    ];

    /**
     * Format a single user record into a safe presentation array.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function present(array $user): array
    {
        $presented = [
            'id' => (string) ($user['id'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'status' => (string) ($user['status'] ?? 'active'),
            'avatar_media_id' => isset($user['avatar_media_id']) ? (string) $user['avatar_media_id'] : null,
            'locale' => (string) ($user['locale'] ?? 'en'),
            'timezone' => (string) ($user['timezone'] ?? 'UTC'),
            'two_factor_enabled' => !empty($user['totp_enabled_at']) || !empty($user['two_factor_enabled']),
            'roles' => isset($user['roles']) && is_array($user['roles']) ? array_values($user['roles']) : [],
            'created_at' => (string) ($user['created_at'] ?? ''),
            'updated_at' => isset($user['updated_at']) ? (string) $user['updated_at'] : null,
            'last_login_at' => isset($user['last_login_at']) ? (string) $user['last_login_at'] : null,
        ];

        // Ensure only allowed keys exist
        return array_intersect_key($presented, array_flip(self::ALLOWED_FIELDS));
    }

    /**
     * Format a collection of user records.
     *
     * @param array<int, array<string, mixed>> $users
     * @return array<int, array<string, mixed>>
     */
    public static function presentCollection(array $users): array
    {
        return array_map([self::class, 'present'], $users);
    }
}
