<?php

declare(strict_types=1);

namespace BsCms\Publishing;

/**
 * Presenter for Deployment records.
 * Masks callback_token_hash and internal security tokens.
 */
final class DeploymentPresenter
{
    /** @var list<string> */
    public const ALLOWED_FIELDS = [
        'id',
        'trigger_type',
        'status',
        'target',
        'changed_urls',
        'requested_by',
        'dispatched_at',
        'finished_at',
        'build_log_url',
        'error',
        'created_at',
    ];

    /**
     * @param array<string, mixed> $deploy
     * @return array<string, mixed>
     */
    public static function present(array $deploy): array
    {
        $changedUrls = $deploy['changed_urls'] ?? [];
        if (is_string($changedUrls)) {
            $decoded = json_decode($changedUrls, true);
            $changedUrls = is_array($decoded) ? $decoded : [];
        }

        $presented = [
            'id' => (string) ($deploy['id'] ?? ''),
            'trigger_type' => (string) ($deploy['trigger_type'] ?? 'manual'),
            'status' => (string) ($deploy['status'] ?? 'queued'),
            'target' => (string) ($deploy['target'] ?? 'github_actions'),
            'changed_urls' => $changedUrls,
            'requested_by' => isset($deploy['requested_by']) ? (string) $deploy['requested_by'] : null,
            'dispatched_at' => isset($deploy['dispatched_at']) ? (string) $deploy['dispatched_at'] : null,
            'finished_at' => isset($deploy['finished_at']) ? (string) $deploy['finished_at'] : null,
            'build_log_url' => isset($deploy['build_log_url']) ? (string) $deploy['build_log_url'] : null,
            'error' => isset($deploy['error']) ? (string) $deploy['error'] : null,
            'created_at' => (string) ($deploy['created_at'] ?? ''),
        ];

        return array_intersect_key($presented, array_flip(self::ALLOWED_FIELDS));
    }

    /**
     * @param list<array<string, mixed>> $deploys
     * @return list<array<string, mixed>>
     */
    public static function presentCollection(array $deploys): array
    {
        return array_map([self::class, 'present'], $deploys);
    }
}
