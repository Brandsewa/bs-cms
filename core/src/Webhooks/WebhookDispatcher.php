<?php

declare(strict_types=1);

namespace BsCms\Webhooks;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Events\Event;
use BsCms\Security\Crypto;
use BsCms\Security\HttpClient;
use Throwable;

final class WebhookDispatcher
{
    private const MAX_ATTEMPTS = 6;
    private const CONSECUTIVE_FAILURES_THRESHOLD = 50;
    private const RETRY_DELAYS_SECONDS = [
        1 => 60,       // 1 min
        2 => 300,      // 5 min
        3 => 1800,     // 30 min
        4 => 7200,     // 120 min
        5 => 14400,    // 240 min
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly Crypto $crypto,
        private readonly HttpClient $httpClient,
        private readonly ?AuditLogger $auditLogger = null
    ) {
    }

    /**
     * Handle an event: queue deliveries for matching webhooks and attempt delivery.
     */
    public function onEvent(Event $event): void
    {
        $eventName = $event->name();
        $payload = $event->payload();
        $collectionKey = (string) ($payload['collection_key'] ?? $payload['collection'] ?? '');

        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT * FROM `{$prefix}webhooks` WHERE `is_active` = 1"
        );
        $webhooks = $stmt->fetchAll();

        foreach ($webhooks as $webhook) {
            $events = is_string($webhook['events']) ? json_decode($webhook['events'], true) : $webhook['events'];
            if (!is_array($events)) {
                continue;
            }

            // Check event match: '*' or explicit event name
            $matchesEvent = in_array('*', $events, true) || in_array($eventName, $events, true);
            if (!$matchesEvent) {
                continue;
            }

            // Check collection match (if collection filter is configured)
            if (!empty($webhook['collections'])) {
                $cols = is_string($webhook['collections']) ? json_decode($webhook['collections'], true) : $webhook['collections'];
                if (is_array($cols) && !empty($cols) && $collectionKey !== '') {
                    if (!in_array($collectionKey, $cols, true) && !in_array('*', $cols, true)) {
                        continue;
                    }
                }
            }

            $this->queueDelivery((string) $webhook['id'], $eventName, $payload);
        }
    }

    /**
     * Enqueue a webhook delivery and attempt synchronous delivery.
     *
     * @param array<string, mixed> $data
     */
    public function queueDelivery(string $webhookId, string $eventName, array $data): int
    {
        $prefix = $this->connection->getPrefix();
        $now = date('Y-m-d H:i:s.v');

        $deliveryPayload = [
            'event' => $eventName,
            'occurred_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'data' => $data,
        ];

        $payloadJson = json_encode($deliveryPayload, JSON_THROW_ON_ERROR);

        $this->connection->execute(
            "INSERT INTO `{$prefix}webhook_deliveries` (
                `webhook_id`, `event`, `payload`, `status`, `attempts`, `created_at`
            ) VALUES (?, ?, ?, 'pending', 0, ?)",
            [$webhookId, $eventName, $payloadJson, $now]
        );

        $deliveryId = (int) $this->connection->getPdo()->lastInsertId();

        // Attempt delivery immediately
        $this->deliver($deliveryId);

        return $deliveryId;
    }

    /**
     * Attempt to execute a webhook delivery.
     */
    public function deliver(int $deliveryId): bool
    {
        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT d.*, w.`url`, w.`secret_enc`, w.`name` AS `webhook_name`, w.`is_active`
             FROM `{$prefix}webhook_deliveries` d
             JOIN `{$prefix}webhooks` w ON d.`webhook_id` = w.`id`
             WHERE d.`id` = ?",
            [$deliveryId]
        );
        $delivery = $stmt->fetch();
        if (!$delivery) {
            return false;
        }

        $webhookId = (string) $delivery['webhook_id'];
        $url = (string) $delivery['url'];
        $payloadJson = (string) $delivery['payload'];
        $attempts = (int) $delivery['attempts'] + 1;

        $secret = '';
        try {
            $secret = $this->crypto->decrypt((string) $delivery['secret_enc']);
        } catch (Throwable) {
            $secret = '';
        }

        $timestamp = time();
        $signature = WebhookSignature::sign($payloadJson, $secret, $timestamp);

        $headers = [
            'Content-Type' => 'application/json',
            WebhookSignature::HEADER_NAME => $signature,
            'User-Agent' => 'BS-CMS-Webhook/1.0',
        ];

        $responseCode = null;
        $responseExcerpt = null;
        $success = false;

        try {
            $response = $this->httpClient->post($url, $payloadJson, $headers);
            $responseCode = $response['status'];
            $responseExcerpt = substr($response['body'], 0, 500);
            $success = ($responseCode >= 200 && $responseCode < 300);
        } catch (Throwable $e) {
            $responseExcerpt = substr($e->getMessage(), 0, 500);
            $responseCode = 0;
            $success = false;
        }

        if ($success) {
            $this->connection->execute(
                "UPDATE `{$prefix}webhook_deliveries` SET
                    `status` = 'delivered',
                    `attempts` = ?,
                    `response_code` = ?,
                    `response_excerpt` = ?,
                    `next_attempt_at` = NULL
                 WHERE `id` = ?",
                [$attempts, $responseCode, $responseExcerpt, $deliveryId]
            );
            return true;
        }

        // Failure handling
        $isTerminal = ($attempts >= self::MAX_ATTEMPTS);
        $status = $isTerminal ? 'failed' : 'pending';
        $nextAttemptAt = null;

        if (!$isTerminal) {
            $delay = self::RETRY_DELAYS_SECONDS[$attempts] ?? 3600;
            $nextAttemptAt = date('Y-m-d H:i:s.v', time() + $delay);
        }

        $this->connection->execute(
            "UPDATE `{$prefix}webhook_deliveries` SET
                `status` = ?,
                `attempts` = ?,
                `response_code` = ?,
                `response_excerpt` = ?,
                `next_attempt_at` = ?
             WHERE `id` = ?",
            [$status, $attempts, $responseCode, $responseExcerpt, $nextAttemptAt, $deliveryId]
        );

        // Check for 50 consecutive failures to auto-disable webhook
        $this->checkAutoDisable($webhookId, (string) $delivery['webhook_name']);

        return false;
    }

    /**
     * Auto-disable webhook if it has 50 consecutive failed deliveries.
     */
    private function checkAutoDisable(string $webhookId, string $webhookName): void
    {
        $prefix = $this->connection->getPrefix();

        // Count consecutive non-delivered attempts after the last successful delivery
        $countStmt = $this->connection->execute(
            "SELECT COUNT(*) AS `consecutive_failures`
             FROM `{$prefix}webhook_deliveries`
             WHERE `webhook_id` = ? AND (`status` = 'failed' OR (`attempts` > 0 AND (`response_code` IS NULL OR `response_code` < 200 OR `response_code` >= 300)))
               AND `id` > COALESCE((SELECT MAX(`id`) FROM `{$prefix}webhook_deliveries` WHERE `webhook_id` = ? AND `status` = 'delivered'), 0)",
            [$webhookId, $webhookId]
        );
        $row = $countStmt->fetch();
        $consecutiveFailures = (int) ($row['consecutive_failures'] ?? 0);

        if ($consecutiveFailures >= self::CONSECUTIVE_FAILURES_THRESHOLD) {
            $this->connection->execute(
                "UPDATE `{$prefix}webhooks` SET `is_active` = 0 WHERE `id` = ?",
                [$webhookId]
            );

            if ($this->auditLogger !== null) {
                $this->auditLogger->record(
                    actor: Actor::system(),
                    action: 'webhook.auto_disabled',
                    subject: new Subject('webhook', $webhookId),
                    summary: "Webhook '{$webhookName}' auto-disabled after {$consecutiveFailures} consecutive delivery failures.",
                    diff: ['consecutive_failures' => $consecutiveFailures, 'is_active' => false],
                    subjectId: $webhookId
                );
            }
        }
    }
}
