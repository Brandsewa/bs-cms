<?php

declare(strict_types=1);

namespace BsCms\Publishing;

/**
 * Presenter for publishing configuration.
 * Enforces a strict allow-list of safe attributes.
 * Secrets (GitHub tokens, webhook secrets) are NEVER returned; only boolean presence flags are exposed.
 */
final class PublishingSettingsPresenter
{
    /** @var list<string> */
    public const ALLOWED_FIELDS = [
        'target_type',
        'enabled',
        'debounce_window',
        'github_repository',
        'github_workflow_id',
        'has_github_token',
        'webhook_url',
        'has_webhook_secret',
        'updated_at',
    ];

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function present(array $config): array
    {
        $hasGithubToken = !empty($config['github_token']) || !empty($config['has_github_token']);
        $hasWebhookSecret = !empty($config['webhook_secret']) || !empty($config['has_webhook_secret']);

        $presented = [
            'target_type' => (string) ($config['target_type'] ?? 'github_actions'),
            'enabled' => isset($config['enabled']) ? (bool) $config['enabled'] : true,
            'debounce_window' => isset($config['debounce_window']) ? max(5, (int) $config['debounce_window']) : 60,
            'github_repository' => (string) ($config['github_repository'] ?? ($config['github']['repository'] ?? 'Brandsewa/bs-cms')),
            'github_workflow_id' => (string) ($config['github_workflow_id'] ?? ($config['github']['workflow_id'] ?? 'deploy.yml')),
            'has_github_token' => $hasGithubToken,
            'webhook_url' => isset($config['webhook_url']) ? (string) $config['webhook_url'] : ($config['webhook']['url'] ?? null),
            'has_webhook_secret' => $hasWebhookSecret,
            'updated_at' => isset($config['updated_at']) ? (string) $config['updated_at'] : null,
        ];

        return array_intersect_key($presented, array_flip(self::ALLOWED_FIELDS));
    }
}
