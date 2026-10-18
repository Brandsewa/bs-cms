<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Security\Crypto;
use BsCms\Security\HttpClient;
use BsCms\Security\Ulid;
use BsCms\Webhooks\WebhookDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class WebhooksController extends BaseController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Crypto $crypto,
        private readonly WebhookDispatcher $dispatcher,
        private readonly Authorizer $authorizer,
        private readonly AuditLogger $auditLogger
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        if (!$this->authorizer->can($actor, 'manage', new Subject('webhook', '*'))->allowed) {
            return $this->problem(403, 'Forbidden', 'Insufficient permissions to view webhooks.');
        }

        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT `id`, `name`, `url`, `events`, `collections`, `is_active`, `created_at`
             FROM `{$prefix}webhooks` ORDER BY `name` ASC"
        );
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            if (is_string($row['events'])) {
                $row['events'] = json_decode($row['events'], true) ?? [];
            }
            if (is_string($row['collections'])) {
                $row['collections'] = json_decode($row['collections'], true);
            }
            $row['has_secret'] = true;
        }
        unset($row);

        return $this->json(array_values($rows));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        if (!$this->authorizer->can($actor, 'manage', new Subject('webhook', '*'))->allowed) {
            return $this->problem(403, 'Forbidden', 'Insufficient permissions to manage webhooks.');
        }

        $body = $this->getBody($request);
        $name = trim((string) ($body['name'] ?? ''));
        $url = trim((string) ($body['url'] ?? ''));
        $secret = (string) ($body['secret'] ?? bin2hex(random_bytes(16)));

        $errors = [];
        if ($name === '') {
            $errors['name'] = ['Webhook name is required.'];
        }
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $errors['url'] = ['A valid webhook URL is required.'];
        } else {
            $ssrfError = self::validateWebhookUrlSsrf($url);
            if ($ssrfError !== null) {
                $errors['url'] = [$ssrfError];
            }
        }
        if (!empty($errors)) {
            return $this->problem(422, 'Validation Error', 'Invalid webhook payload.', null, $errors);
        }

        $events = $body['events'] ?? ['*'];
        if (is_string($events)) {
            $events = [$events];
        }
        $collections = $body['collections'] ?? null;
        if (is_string($collections)) {
            $collections = [$collections];
        }
        $isActive = !isset($body['is_active']) || !empty($body['is_active']) ? 1 : 0;

        $secretEnc = $this->crypto->encrypt($secret);
        $id = Ulid::generate();
        $now = date('Y-m-d H:i:s.v');
        $prefix = $this->connection->getPrefix();

        $this->connection->execute(
            "INSERT INTO `{$prefix}webhooks` (
                `id`, `name`, `url`, `secret_enc`, `events`, `collections`, `is_active`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $name,
                $url,
                $secretEnc,
                json_encode($events, JSON_THROW_ON_ERROR),
                $collections !== null ? json_encode($collections, JSON_THROW_ON_ERROR) : null,
                $isActive,
                $now,
            ]
        );

        $this->auditLogger->record(
            actor: $actor,
            action: 'webhook.create',
            subject: new Subject('webhook', $id),
            summary: "Created webhook '{$name}'",
            diff: ['after' => ['name' => $name, 'url' => $url, 'events' => $events, 'is_active' => $isActive]],
            requestId: (string) $request->getAttribute('request_id', ''),
            ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
            subjectId: $id
        );

        $created = $this->fetchWebhook($id);

        return $this->json($created ?? ['id' => $id], 201);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function get(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        if (!$this->authorizer->can($actor, 'manage', new Subject('webhook', '*'))->allowed) {
            return $this->problem(403, 'Forbidden', 'Insufficient permissions to view webhooks.');
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $webhook = $this->fetchWebhook($id);
        if ($webhook === null) {
            return $this->problem(404, 'Not Found', "Webhook '{$id}' not found.");
        }

        return $this->json($webhook);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function update(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        if (!$this->authorizer->can($actor, 'manage', new Subject('webhook', '*'))->allowed) {
            return $this->problem(403, 'Forbidden', 'Insufficient permissions to manage webhooks.');
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $existing = $this->fetchWebhook($id);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Webhook '{$id}' not found.");
        }

        $body = $this->getBody($request);
        $prefix = $this->connection->getPrefix();

        $name = isset($body['name']) ? trim((string) $body['name']) : null;
        $url = isset($body['url']) ? trim((string) $body['url']) : null;
        if ($url !== null) {
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                return $this->problem(422, 'Validation Error', 'Invalid webhook URL.', null, [
                    'url' => ['A valid webhook URL is required.'],
                ]);
            }
            $ssrfError = self::validateWebhookUrlSsrf($url);
            if ($ssrfError !== null) {
                return $this->problem(422, 'Validation Error', 'Invalid webhook URL.', null, [
                    'url' => [$ssrfError],
                ]);
            }
        }

        $secretEnc = isset($body['secret']) && (string) $body['secret'] !== ''
            ? $this->crypto->encrypt((string) $body['secret'])
            : null;

        $events = isset($body['events']) ? json_encode((array) $body['events'], JSON_THROW_ON_ERROR) : null;
        $collections = isset($body['collections']) ? json_encode((array) $body['collections'], JSON_THROW_ON_ERROR) : null;
        $isActive = isset($body['is_active']) ? (!empty($body['is_active']) ? 1 : 0) : null;

        $this->connection->execute(
            "UPDATE `{$prefix}webhooks` SET
                `name` = COALESCE(?, `name`),
                `url` = COALESCE(?, `url`),
                `secret_enc` = COALESCE(?, `secret_enc`),
                `events` = COALESCE(?, `events`),
                `collections` = COALESCE(?, `collections`),
                `is_active` = COALESCE(?, `is_active`)
             WHERE `id` = ?",
            [$name, $url, $secretEnc, $events, $collections, $isActive, $id]
        );

        $updated = $this->fetchWebhook($id);

        $this->auditLogger->record(
            actor: $actor,
            action: 'webhook.update',
            subject: new Subject('webhook', $id),
            summary: "Updated webhook '{$id}'",
            diff: ['before' => $existing, 'after' => $updated],
            requestId: (string) $request->getAttribute('request_id', ''),
            ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
            subjectId: $id
        );

        return $this->json($updated ?? []);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function delete(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        if (!$this->authorizer->can($actor, 'manage', new Subject('webhook', '*'))->allowed) {
            return $this->problem(403, 'Forbidden', 'Insufficient permissions to delete webhooks.');
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $existing = $this->fetchWebhook($id);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Webhook '{$id}' not found.");
        }

        $prefix = $this->connection->getPrefix();
        $this->connection->execute("DELETE FROM `{$prefix}webhooks` WHERE `id` = ?", [$id]);

        $this->auditLogger->record(
            actor: $actor,
            action: 'webhook.delete',
            subject: new Subject('webhook', $id),
            summary: "Deleted webhook '{$existing['name']}'",
            diff: ['before' => $existing],
            requestId: (string) $request->getAttribute('request_id', ''),
            ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
            subjectId: $id
        );

        return $this->json(['success' => true]);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function test(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        if (!$this->authorizer->can($actor, 'manage', new Subject('webhook', '*'))->allowed) {
            return $this->problem(403, 'Forbidden', 'Insufficient permissions to test webhooks.');
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $webhook = $this->fetchWebhook($id);
        if ($webhook === null) {
            return $this->problem(404, 'Not Found', "Webhook '{$id}' not found.");
        }

        $deliveryId = $this->dispatcher->queueDelivery($id, 'webhook.test', [
            'message' => 'Ping test from BS CMS',
            'triggered_by' => $actor->label,
        ]);

        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT * FROM `{$prefix}webhook_deliveries` WHERE `id` = ?",
            [$deliveryId]
        );
        $delivery = $stmt->fetch();
        if ($delivery && is_string($delivery['payload'])) {
            $delivery['payload'] = json_decode($delivery['payload'], true);
        }

        return $this->json($delivery ?? ['delivery_id' => $deliveryId]);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function deliveries(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        if (!$this->authorizer->can($actor, 'manage', new Subject('webhook', '*'))->allowed) {
            return $this->problem(403, 'Forbidden', 'Insufficient permissions to view webhook deliveries.');
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $webhook = $this->fetchWebhook($id);
        if ($webhook === null) {
            return $this->problem(404, 'Not Found', "Webhook '{$id}' not found.");
        }

        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT * FROM `{$prefix}webhook_deliveries` WHERE `webhook_id` = ? ORDER BY `id` DESC LIMIT 100",
            [$id]
        );
        $deliveries = $stmt->fetchAll();

        foreach ($deliveries as &$del) {
            if (is_string($del['payload'])) {
                $del['payload'] = json_decode($del['payload'], true);
            }
        }
        unset($del);

        return $this->json(array_values($deliveries));
    }

    /**
     * @param array<string, mixed> $args
     */
    public function retry(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        if (!$this->authorizer->can($actor, 'manage', new Subject('webhook', '*'))->allowed) {
            return $this->problem(403, 'Forbidden', 'Insufficient permissions to retry deliveries.');
        }

        $deliveryId = (int) ($args['id'] ?? $request->getAttribute('id', 0));
        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT * FROM `{$prefix}webhook_deliveries` WHERE `id` = ?",
            [$deliveryId]
        );
        $del = $stmt->fetch();
        if (!$del) {
            return $this->problem(404, 'Not Found', "Delivery '{$deliveryId}' not found.");
        }

        $success = $this->dispatcher->deliver($deliveryId);

        $stmt = $this->connection->execute("SELECT * FROM `{$prefix}webhook_deliveries` WHERE `id` = ?", [$deliveryId]);
        $updated = $stmt->fetch();
        if ($updated && is_string($updated['payload'])) {
            $updated['payload'] = json_decode($updated['payload'], true);
        }

        return $this->json($updated ?? ['success' => $success]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchWebhook(string $id): ?array
    {
        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT `id`, `name`, `url`, `events`, `collections`, `is_active`, `created_at`
             FROM `{$prefix}webhooks` WHERE `id` = ?",
            [$id]
        );
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        if (is_string($row['events'])) {
            $row['events'] = json_decode($row['events'], true) ?? [];
        }
        if (is_string($row['collections'])) {
            $row['collections'] = json_decode($row['collections'], true);
        }
        $row['has_secret'] = true;

        return $row;
    }

    /**
     * Validate webhook URL against SSRF attacks (scheme, private/loopback/link-local/metadata IP).
     *
     * @return string|null Error message if unsafe, or null if valid
     */
    private static function validateWebhookUrlSsrf(string $url): ?string
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'])) {
            return 'Invalid URL format.';
        }

        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return "URL scheme '{$scheme}' is not allowed (must be http or https).";
        }

        if (!isset($parsed['host']) || trim($parsed['host']) === '') {
            return 'URL is missing host.';
        }

        $host = trim($parsed['host']);
        if (strtolower($host) === 'localhost') {
            return "SSRF security violation: Host '{$host}' is restricted.";
        }

        $client = new HttpClient();
        try {
            $client->resolveAndValidateHost($host);
        } catch (\Throwable $e) {
            return 'SSRF security violation: ' . $e->getMessage();
        }

        return null;
    }
}
