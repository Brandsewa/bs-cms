<?php

declare(strict_types=1);

namespace BsCms\Publishing;

use BsCms\Database\Connection;
use BsCms\Security\HttpClient;
use BsCms\Security\Ulid;
use BsCms\Settings\SettingsRepository;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Service orchestrating static site deployments:
 * - Enqueuing deploy requests and debouncing within the window.
 * - Merging requests into deployments rows.
 * - Dispatching via GitHub Actions (repository_dispatch) or Webhook.
 * - Handling status callbacks with single-use tokens and state machine rules.
 */
final class DeploymentService
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_BUILDING = 'building';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        private readonly Connection $connection,
        private readonly PublishingSettingsRepository $publishingSettings,
        private readonly SettingsRepository $settings,
        private readonly HttpClient $httpClient,
        private readonly IndexNowService $indexNowService,
        private readonly ?string $appUrl = null
    ) {
    }

    /**
     * Enqueue a deploy request with changed URLs.
     * Appends to queued deploy requests in settings for debouncing.
     *
     * @param list<string> $changedUrls
     */
    public function requestDeploy(array $changedUrls = [], ?string $requestedBy = null, string $triggerType = 'content_change'): void
    {
        $config = $this->publishingSettings->get();
        if (empty($config['enabled'])) {
            return;
        }

        /** @var array<string, mixed> $pending */
        $pending = (array) $this->settings->get('publishing.pending_requests', []);

        $now = Clock::timestamp();
        $firstRequestedAt = (int) ($pending['first_requested_at'] ?? $now);
        /** @var list<string> $existingUrls */
        $existingUrls = is_array($pending['urls'] ?? null) ? array_values($pending['urls']) : [];

        $allUrls = array_merge($existingUrls, $changedUrls);
        /** @var list<string> $urls */
        $urls = array_values(array_unique(array_filter($allUrls, static fn (string $u): bool => $u !== '')));

        $this->settings->set('publishing.pending_requests', [
            'first_requested_at' => $firstRequestedAt,
            'last_requested_at' => $now,
            'urls' => $urls,
            'trigger_type' => $triggerType,
            'requested_by' => $requestedBy,
        ]);
    }

    /**
     * Run the debounce check (called by scheduler / lazy runner / cron).
     * If the debounce window has elapsed since the first request, creates a deployment and dispatches it.
     *
     * @return array<string, mixed>|null
     */
    public function processDebounceQueue(): ?array
    {
        /** @var array<string, mixed>|null $pending */
        $pending = $this->settings->get('publishing.pending_requests', null);
        if (!is_array($pending) || empty($pending['urls'])) {
            return null;
        }

        $config = $this->publishingSettings->get();
        $debounceWindow = max(5, (int) ($config['debounce_window'] ?? 60));
        $now = Clock::timestamp();
        $firstRequestedAt = (int) ($pending['first_requested_at'] ?? $now);

        // Check if window has elapsed
        if (($now - $firstRequestedAt) < $debounceWindow) {
            return null;
        }

        // Window has elapsed! Clear pending requests atomically
        $this->settings->set('publishing.pending_requests', null);

        /** @var list<string> $urls */
        $urls = is_array($pending['urls']) ? array_values($pending['urls']) : [];
        $triggerType = (string) ($pending['trigger_type'] ?? 'content_change');
        $requestedBy = isset($pending['requested_by']) ? (string) $pending['requested_by'] : null;

        return $this->createAndDispatchDeployment($urls, $triggerType, $requestedBy);
    }

    /**
     * Create a deployment row and trigger dispatch.
     *
     * @param list<string> $urls
     * @return array<string, mixed>
     */
    public function createAndDispatchDeployment(
        array $urls = [],
        string $triggerType = 'manual',
        ?string $requestedBy = null
    ): array {
        $config = $this->publishingSettings->get();
        $target = (string) ($config['target_type'] ?? 'github_actions');

        $deployId = Ulid::generate();
        $callbackToken = bin2hex(random_bytes(32)); // 64 hex characters
        $callbackTokenHash = hash('sha256', $callbackToken);
        $nowSql = Clock::sql(Clock::now());

        $table = $this->connection->prefixTable('deployments');
        $stmt = $this->connection->getPdo()->prepare(
            "INSERT INTO `{$table}` (
                `id`, `trigger_type`, `status`, `target`, `changed_urls`, `requested_by`,
                `dispatched_at`, `finished_at`, `build_log_url`, `error`, `callback_token_hash`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, ?)"
        );

        $stmt->execute([
            $deployId,
            $triggerType,
            self::STATUS_QUEUED,
            $target,
            Json::encode($urls),
            $requestedBy,
            $callbackTokenHash,
            $nowSql,
        ]);

        $deployment = [
            'id' => $deployId,
            'trigger_type' => $triggerType,
            'status' => self::STATUS_QUEUED,
            'target' => $target,
            'changed_urls' => $urls,
            'requested_by' => $requestedBy,
            'callback_token' => $callbackToken,
            'callback_token_hash' => $callbackTokenHash,
            'created_at' => $nowSql,
        ];

        // Execute dispatch
        $dispatched = $this->dispatch($deployment, $config);

        return $dispatched;
    }

    /**
     * Dispatch the deployment according to target_type.
     *
     * @param array<string, mixed> $deployment
     * @param array<string, mixed>|null $config
     * @return array<string, mixed>
     */
    public function dispatch(array $deployment, ?array $config = null): array
    {
        if ($config === null) {
            $config = $this->publishingSettings->get();
        }

        $deployId = (string) $deployment['id'];
        $target = (string) ($config['target_type'] ?? 'github_actions');
        $callbackToken = (string) ($deployment['callback_token'] ?? '');

        $baseUrl = rtrim((string) ($this->appUrl ?: $this->settings->get('site_url', 'http://localhost')), '/');
        $callbackUrl = "{$baseUrl}/api/v1/deployments/{$deployId}/status";

        $nowSql = Clock::sql(Clock::now());

        if ($target === 'manual') {
            // Manual: remains queued until user builds and runs deploy script
            return $deployment;
        }

        if ($target === 'github_actions') {
            $repo = (string) ($config['github_repository'] ?? '');
            $token = (string) ($config['github_token'] ?? '');

            if ($repo === '' || $token === '') {
                $err = 'GitHub repository or token not configured.';
                $this->markDispatchFailed($deployId, $err);
                $deployment['status'] = self::STATUS_FAILED;
                $deployment['error'] = $err;
                return $deployment;
            }

            $endpoint = "https://api.github.com/repos/{$repo}/dispatches";
            $payload = [
                'event_type' => 'deploy_site',
                'client_payload' => [
                    'deployment_id' => $deployId,
                    'callback_url' => $callbackUrl,
                    'callback_token' => $callbackToken,
                ],
            ];

            try {
                $resp = $this->httpClient->post(
                    $endpoint,
                    Json::encode($payload),
                    [
                        'Accept' => 'application/vnd.github+json',
                        'Authorization' => "Bearer {$token}",
                        'User-Agent' => 'BS-CMS-Publishing-Pipeline',
                        'Content-Type' => 'application/json',
                    ]
                );

                if ($resp['status'] >= 200 && $resp['status'] < 300) {
                    $this->markDispatched($deployId, $nowSql);
                    $deployment['status'] = self::STATUS_DISPATCHED;
                    $deployment['dispatched_at'] = $nowSql;
                } else {
                    $err = "GitHub API returned status {$resp['status']}: " . substr($resp['body'], 0, 200);
                    $this->markDispatchFailed($deployId, $err);
                    $deployment['status'] = self::STATUS_FAILED;
                    $deployment['error'] = $err;
                }
            } catch (Throwable $e) {
                $err = "GitHub dispatch error: {$e->getMessage()}";
                $this->markDispatchFailed($deployId, $err);
                $deployment['status'] = self::STATUS_FAILED;
                $deployment['error'] = $err;
            }

            return $deployment;
        }

        if ($target === 'webhook') {
            $webhookUrl = (string) ($config['webhook_url'] ?? '');
            $webhookSecret = (string) ($config['webhook_secret'] ?? '');

            if ($webhookUrl === '') {
                $err = 'Webhook URL not configured.';
                $this->markDispatchFailed($deployId, $err);
                $deployment['status'] = self::STATUS_FAILED;
                $deployment['error'] = $err;
                return $deployment;
            }

            $body = Json::encode([
                'deployment_id' => $deployId,
                'callback_url' => $callbackUrl,
                'callback_token' => $callbackToken,
                'changed_urls' => $deployment['changed_urls'] ?? [],
                'timestamp' => Clock::now()->format(\DateTimeInterface::ATOM),
            ]);

            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'BS-CMS-Webhook-Dispatcher',
            ];

            if ($webhookSecret !== '') {
                $sig = hash_hmac('sha256', $body, $webhookSecret);
                $headers['X-BS-Signature'] = "sha256={$sig}";
            }

            try {
                $resp = $this->httpClient->post($webhookUrl, $body, $headers);
                if ($resp['status'] >= 200 && $resp['status'] < 300) {
                    $this->markDispatched($deployId, $nowSql);
                    $deployment['status'] = self::STATUS_DISPATCHED;
                    $deployment['dispatched_at'] = $nowSql;
                } else {
                    $err = "Webhook returned HTTP {$resp['status']}";
                    $this->markDispatchFailed($deployId, $err);
                    $deployment['status'] = self::STATUS_FAILED;
                    $deployment['error'] = $err;
                }
            } catch (Throwable $e) {
                $err = "Webhook dispatch error: {$e->getMessage()}";
                $this->markDispatchFailed($deployId, $err);
                $deployment['status'] = self::STATUS_FAILED;
                $deployment['error'] = $err;
            }

            return $deployment;
        }

        return $deployment;
    }

    /**
     * Handle status callback from external build agent / GitHub Actions.
     *
     * @param string $deploymentId
     * @param string $rawCallbackToken
     * @param array{status: string, build_log_url?: string|null, error?: string|null} $input
     * @return array<string, mixed>
     */
    public function handleStatusCallback(string $deploymentId, string $rawCallbackToken, array $input): array
    {
        $table = $this->connection->prefixTable('deployments');
        $hash = hash('sha256', $rawCallbackToken);

        $stmt = $this->connection->getPdo()->prepare(
            "SELECT * FROM `{$table}` WHERE `id` = ? LIMIT 1"
        );
        $stmt->execute([$deploymentId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Deployment not found.');
        }

        // Validate callback token hash
        if (!hash_equals((string) $row['callback_token_hash'], $hash)) {
            throw new RuntimeException('Invalid or expired callback token.');
        }

        $currentStatus = (string) $row['status'];
        $newStatus = (string) $input['status'];

        // Allowed transitions state machine
        $allowedTransitions = [
            self::STATUS_QUEUED => [self::STATUS_DISPATCHED, self::STATUS_BUILDING, self::STATUS_FAILED, self::STATUS_CANCELLED],
            self::STATUS_DISPATCHED => [self::STATUS_BUILDING, self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED],
            self::STATUS_BUILDING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED],
            // Terminal states allow no further transitions (idempotent ignore or rejection)
            self::STATUS_SUCCEEDED => [],
            self::STATUS_FAILED => [],
            self::STATUS_CANCELLED => [],
        ];

        if ($currentStatus === $newStatus) {
            // Idempotent duplicate update
            return $row;
        }

        if (!isset($allowedTransitions[$currentStatus]) || !in_array($newStatus, $allowedTransitions[$currentStatus], true)) {
            throw new RuntimeException("Invalid status transition from '{$currentStatus}' to '{$newStatus}'.");
        }

        $nowSql = Clock::sql(Clock::now());
        $buildLogUrl = !empty($input['build_log_url'])
            ? substr((string) $input['build_log_url'], 0, 1024)
            : $row['build_log_url'];

        $error = !empty($input['error'])
            ? substr((string) $input['error'], 0, 1000)
            : $row['error'];

        $finishedAt = in_array($newStatus, [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED], true)
            ? $nowSql
            : null;

        // If terminal state reached, invalidate callback token hash so it cannot be replayed
        $updatedTokenHash = $row['callback_token_hash'];
        if ($finishedAt !== null) {
            $updatedTokenHash = hash('sha256', bin2hex(random_bytes(32))); // burned
        }

        $updateStmt = $this->connection->getPdo()->prepare(
            "UPDATE `{$table}`
             SET `status` = ?, `build_log_url` = ?, `error` = ?, `finished_at` = ?, `callback_token_hash` = ?
             WHERE `id` = ?"
        );
        $updateStmt->execute([
            $newStatus,
            $buildLogUrl,
            $error,
            $finishedAt,
            $updatedTokenHash,
            $deploymentId,
        ]);

        $row['status'] = $newStatus;
        $row['build_log_url'] = $buildLogUrl;
        $row['error'] = $error;
        $row['finished_at'] = $finishedAt;

        // On succeeded: trigger IndexNow notification for changed URLs
        if ($newStatus === self::STATUS_SUCCEEDED) {
            $this->onDeploymentSucceeded($row);
        }

        return $row;
    }

    /**
     * Get deployment by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getDeployment(string $id): ?array
    {
        $table = $this->connection->prefixTable('deployments');
        $stmt = $this->connection->getPdo()->prepare(
            "SELECT * FROM `{$table}` WHERE `id` = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * List recent deployments.
     *
     * @return list<array<string, mixed>>
     */
    public function listDeployments(int $limit = 20): array
    {
        $table = $this->connection->prefixTable('deployments');
        $stmt = $this->connection->getPdo()->prepare(
            "SELECT * FROM `{$table}` ORDER BY `created_at` DESC LIMIT ?"
        );
        $stmt->bindValue(1, max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        /** @var list<array<string, mixed>> $result */
        $result = array_values($rows);
        return $result;
    }

    private function markDispatched(string $id, string $dispatchedAt): void
    {
        $table = $this->connection->prefixTable('deployments');
        $stmt = $this->connection->getPdo()->prepare(
            "UPDATE `{$table}` SET `status` = ?, `dispatched_at` = ? WHERE `id` = ?"
        );
        $stmt->execute([self::STATUS_DISPATCHED, $dispatchedAt, $id]);
    }

    private function markDispatchFailed(string $id, string $error): void
    {
        $table = $this->connection->prefixTable('deployments');
        $now = Clock::sql(Clock::now());
        $stmt = $this->connection->getPdo()->prepare(
            "UPDATE `{$table}` SET `status` = ?, `error` = ?, `finished_at` = ? WHERE `id` = ?"
        );
        $stmt->execute([self::STATUS_FAILED, substr($error, 0, 1000), $now, $id]);
    }

    /**
     * @param array<string, mixed> $deployment
     */
    private function onDeploymentSucceeded(array $deployment): void
    {
        $rawUrls = $deployment['changed_urls'] ?? [];
        if (is_string($rawUrls)) {
            $decoded = json_decode($rawUrls, true);
            $rawUrls = is_array($decoded) ? $decoded : [];
        }

        if (empty($rawUrls) || !is_array($rawUrls)) {
            return;
        }

        /** @var list<string> $cleanUrls */
        $cleanUrls = array_values(array_filter($rawUrls, static fn ($u): bool => is_string($u)));
        if (empty($cleanUrls)) {
            return;
        }

        $indexNowKey = (string) $this->settings->get('seo.indexnow_key', '');
        $siteUrl = (string) $this->settings->get('site_url', $this->appUrl ?: 'http://localhost');
        $parsed = parse_url($siteUrl);
        $host = (string) ($parsed['host'] ?? 'bskickstarter.com');

        if ($indexNowKey !== '') {
            $this->indexNowService->notify($cleanUrls, $host, $indexNowKey);
        }
    }
}
