<?php

declare(strict_types=1);

namespace BsCms\Publishing;

use BsCms\Database\Connection;
use BsCms\Security\Crypto;
use BsCms\Settings\SettingsRepository;
use BsCms\Support\Clock;

/**
 * Repository for publishing settings.
 * Handles encrypted storage of GitHub PAT and Webhook secret using Crypto.
 */
final class PublishingSettingsRepository
{
    private const SETTING_KEY = 'publishing.config';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ?Crypto $crypto = null
    ) {
    }

    /**
     * Load current publishing configuration with decrypted secrets.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $raw = $this->settings->get(self::SETTING_KEY, null);
        if (!is_array($raw)) {
            return [
                'target_type' => 'github_actions',
                'enabled' => true,
                'debounce_window' => 60,
                'github_repository' => 'Brandsewa/bs-cms',
                'github_workflow_id' => 'deploy.yml',
                'github_token' => '',
                'webhook_url' => '',
                'webhook_secret' => '',
                'url_patterns' => [
                    'posts' => '/blog/{slug}/',
                    'pages' => '/{slug}/',
                ],
                'updated_at' => null,
            ];
        }

        $config = $raw;
        if (!isset($config['url_patterns']) || !is_array($config['url_patterns'])) {
            $config['url_patterns'] = [
                'posts' => '/blog/{slug}/',
                'pages' => '/{slug}/',
            ];
        }

        // Decrypt secrets if present
        if (!empty($config['github_token_encrypted']) && $this->crypto !== null) {
            try {
                $config['github_token'] = $this->crypto->decrypt((string) $config['github_token_encrypted']);
            } catch (\Throwable) {
                $config['github_token'] = '';
            }
        } elseif (!isset($config['github_token'])) {
            $config['github_token'] = '';
        }

        if (!empty($config['webhook_secret_encrypted']) && $this->crypto !== null) {
            try {
                $config['webhook_secret'] = $this->crypto->decrypt((string) $config['webhook_secret_encrypted']);
            } catch (\Throwable) {
                $config['webhook_secret'] = '';
            }
        } elseif (!isset($config['webhook_secret'])) {
            $config['webhook_secret'] = '';
        }

        return $config;
    }

    /**
     * Update publishing configuration with encrypted secrets.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> The stored configuration
     */
    public function update(array $input, ?string $updatedBy = null): array
    {
        $current = $this->get();

        $targetType = (string) ($input['target_type'] ?? ($current['target_type'] ?? 'github_actions'));
        if (!in_array($targetType, ['github_actions', 'webhook', 'manual'], true)) {
            $targetType = 'github_actions';
        }

        $enabled = isset($input['enabled']) ? (bool) $input['enabled'] : (bool) ($current['enabled'] ?? true);
        $debounceWindow = isset($input['debounce_window']) ? max(5, (int) $input['debounce_window']) : (int) ($current['debounce_window'] ?? 60);

        $githubRepo = (string) ($input['github_repository'] ?? ($current['github_repository'] ?? 'Brandsewa/bs-cms'));
        $githubWorkflow = (string) ($input['github_workflow_id'] ?? ($current['github_workflow_id'] ?? 'deploy.yml'));

        $webhookUrl = isset($input['webhook_url']) ? (string) $input['webhook_url'] : (string) ($current['webhook_url'] ?? '');

        $urlPatterns = isset($input['url_patterns']) && is_array($input['url_patterns'])
            ? $input['url_patterns']
            : ($current['url_patterns'] ?? [
                'posts' => '/blog/{slug}/',
                'pages' => '/{slug}/',
            ]);

        // Preserve or update secrets
        $githubToken = isset($input['github_token']) && $input['github_token'] !== '' ? (string) $input['github_token'] : (string) ($current['github_token'] ?? '');
        $webhookSecret = isset($input['webhook_secret']) && $input['webhook_secret'] !== '' ? (string) $input['webhook_secret'] : (string) ($current['webhook_secret'] ?? '');

        $githubTokenEncrypted = $current['github_token_encrypted'] ?? null;
        if ($githubToken !== '' && $this->crypto !== null) {
            $githubTokenEncrypted = $this->crypto->encrypt($githubToken);
        }

        $webhookSecretEncrypted = $current['webhook_secret_encrypted'] ?? null;
        if ($webhookSecret !== '' && $this->crypto !== null) {
            $webhookSecretEncrypted = $this->crypto->encrypt($webhookSecret);
        }

        $now = Clock::sql(Clock::now());

        $toStore = [
            'target_type' => $targetType,
            'enabled' => $enabled,
            'debounce_window' => $debounceWindow,
            'github_repository' => $githubRepo,
            'github_workflow_id' => $githubWorkflow,
            'github_token_encrypted' => $githubTokenEncrypted,
            'has_github_token' => !empty($githubToken),
            'webhook_url' => $webhookUrl,
            'webhook_secret_encrypted' => $webhookSecretEncrypted,
            'has_webhook_secret' => !empty($webhookSecret),
            'url_patterns' => $urlPatterns,
            'updated_at' => $now,
            'updated_by' => $updatedBy,
        ];

        $this->settings->set(self::SETTING_KEY, $toStore, false, $updatedBy);

        $toStore['github_token'] = $githubToken;
        $toStore['webhook_secret'] = $webhookSecret;
        return $toStore;
    }
}
