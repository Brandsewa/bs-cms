<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Operations\ConfirmTokenService;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\SchemaApplier;
use BsCms\Schema\SchemaLock;
use BsCms\Schema\SchemaPlanner;
use BsCms\Security\Ulid;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class CollectionsController extends BaseController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionRegistry $registry,
        private readonly SchemaPlanner $planner,
        private readonly SchemaApplier $applier,
        private readonly Authorizer $authorizer,
        private readonly ConfirmTokenService $confirmTokenService,
        private readonly AuditLogger $auditLogger
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('schema', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $collections = $this->registry->all();
        return $this->json(array_values($collections));
    }

    /**
     * @param array<string, mixed> $args
     */
    public function get(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('schema', $handle));
        if ($permError !== null) {
            return $permError;
        }

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        return $this->json($this->registry->get($handle));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('schema', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getBody($request);
        if (empty($body['handle'])) {
            return $this->problem(422, 'Unprocessable Content', 'Collection handle is required.', null, [
                'handle' => ['Collection handle is required.'],
            ]);
        }

        $actor = $this->getActor($request);

        try {
            $plan = $this->planner->plan($body);
            $this->applier->apply($plan, $body, $actor->id, 'api');
            $created = $this->registry->get((string) $body['handle']);

            $this->auditLogger->record(
                actor: $actor,
                action: 'collection.create',
                subject: new Subject('schema', (string) $body['handle']),
                summary: "Created collection {$body['handle']}",
                diff: ['after' => $created],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                subjectId: (string) ($created['id'] ?? $body['handle']),
                collectionKey: (string) $body['handle']
            );

            return $this->json($created, 201);
        } catch (Throwable $e) {
            $status = ($e instanceof \InvalidArgumentException) ? 422 : 500;
            return $this->problem($status, 'Schema Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function update(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('schema', $handle));
        if ($permError !== null) {
            return $permError;
        }

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $body = $this->getBody($request);
        $body['handle'] = $handle; // Enforce handle consistency

        $actor = $this->getActor($request);

        try {
            $plan = $this->planner->plan($body);

            // Two-step confirmation for destructive changes (data loss)
            if ($plan->riskFlags['data_loss']) {
                $confirmToken = (string) ($body['confirm_token'] ?? $request->getHeaderLine('X-Confirm-Token'));
                $canonicalInput = $body;
                unset($canonicalInput['confirm_token']);

                if ($confirmToken === '') {
                    $token = $this->confirmTokenService->generate($actor->id, 'collection.update', $canonicalInput);
                    return $this->json([
                        'type' => 'https://errors.bscms.org/confirmation-required',
                        'title' => 'Confirmation Required',
                        'status' => 409,
                        'detail' => 'This schema modification contains destructive changes and requires confirmation.',
                        'confirm_token' => $token,
                        'hazards' => $plan->riskFlags,
                        'changes' => $plan->changes,
                    ], 409);
                }

                $this->confirmTokenService->validateAndConsume($confirmToken, $actor->id, 'collection.update', $canonicalInput);
            }

            $before = $this->registry->get($handle);
            $this->applier->apply($plan, $body, $actor->id, 'api');
            $updated = $this->registry->get($handle);

            $this->auditLogger->record(
                actor: $actor,
                action: 'collection.update',
                subject: new Subject('schema', $handle),
                summary: "Updated collection {$handle}",
                diff: ['before' => $before, 'after' => $updated],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                subjectId: (string) ($updated['id'] ?? $handle),
                collectionKey: $handle
            );

            return $this->json($updated);
        } catch (Throwable $e) {
            $status = ($e instanceof \InvalidArgumentException) ? 422 : 500;
            return $this->problem($status, 'Schema Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function delete(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'delete', new Subject('schema', $handle));
        if ($permError !== null) {
            return $permError;
        }

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $existing = $this->registry->get($handle);
        if (!empty($existing['is_system'])) {
            return $this->problem(403, 'Forbidden', "Built-in system collection '{$handle}' cannot be deleted.");
        }

        $actor = $this->getActor($request);
        $body = $this->getBody($request);

        $confirmToken = (string) ($body['confirm_token'] ?? $request->getHeaderLine('X-Confirm-Token'));
        $canonicalInput = ['handle' => $handle];

        if ($confirmToken === '') {
            $token = $this->confirmTokenService->generate($actor->id, 'collection.delete', $canonicalInput);
            return $this->json([
                'type' => 'https://errors.bscms.org/confirmation-required',
                'title' => 'Confirmation Required',
                'status' => 409,
                'detail' => "Deleting collection '{$handle}' permanently drops all data and cannot be undone.",
                'confirm_token' => $token,
            ], 409);
        }

        try {
            $this->confirmTokenService->validateAndConsume($confirmToken, $actor->id, 'collection.delete', $canonicalInput);

            $collectionId = (string) $existing['id'];
            $prefix = $this->connection->getPrefix();
            $tableName = $prefix . 'c_' . $handle;

            $this->connection->transaction(function (Connection $conn) use ($prefix, $collectionId, $handle) {
                // Delete relations and versions
                $conn->execute("DELETE FROM `{$prefix}entry_relations` WHERE `from_collection_id` = ? OR `to_collection_id` = ?", [$collectionId, $collectionId]);
                $conn->execute("DELETE FROM `{$prefix}entry_versions` WHERE `collection_id` = ?", [$collectionId]);
                $conn->execute("DELETE FROM `{$prefix}saved_views` WHERE `collection_key` = ?", [$handle]);
                $conn->execute("DELETE FROM `{$prefix}fields` WHERE `collection_id` = ?", [$collectionId]);
                $conn->execute("DELETE FROM `{$prefix}schema_changes` WHERE `collection_id` = ?", [$collectionId]);
                $conn->execute("DELETE FROM `{$prefix}collections` WHERE `id` = ?", [$collectionId]);
            });

            // Drop collection table outside transaction (DDL causes implicit commit)
            $this->connection->execute("DROP TABLE IF EXISTS `{$tableName}`");

            $this->registry->invalidate();

            $this->auditLogger->record(
                actor: $actor,
                action: 'collection.delete',
                subject: new Subject('schema', $handle),
                summary: "Deleted collection {$handle}",
                diff: ['before' => $existing],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                subjectId: $collectionId,
                collectionKey: $handle
            );

            return $this->json(['success' => true]);
        } catch (Throwable $e) {
            return $this->problem(500, 'Delete Error', $e->getMessage());
        }
    }

    public function plan(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('schema', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getBody($request);
        if (empty($body['handle'])) {
            return $this->problem(422, 'Unprocessable Content', 'Collection handle is required.');
        }

        try {
            $plan = $this->planner->plan($body);
            $prefix = $this->connection->getPrefix();
            $changeId = Ulid::generate();
            $now = date('Y-m-d H:i:s.v');
            $actor = $this->getActor($request);

            $collectionId = null;
            if ($this->registry->has((string) $body['handle'])) {
                $existing = $this->registry->get((string) $body['handle']);
                $collectionId = $existing['id'] ?? null;
            }

            $this->connection->execute(
                "INSERT INTO `{$prefix}schema_changes` (
                    `id`, `collection_id`, `change_type`, `before_state`, `after_state`,
                    `sql_statements`, `applied_by`, `applied_via`, `dry_run`, `status`, `created_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'api', 0, 'proposed', ?)",
                [
                    $changeId,
                    $collectionId,
                    $plan->changes[0]['type'] ?? 'create_collection',
                    null,
                    json_encode($body, JSON_THROW_ON_ERROR),
                    json_encode($plan->sqlStatements, JSON_THROW_ON_ERROR),
                    $actor->id,
                    $now,
                ]
            );

            return $this->json([
                'change_id' => $changeId,
                'status' => 'proposed',
                'plan' => $plan->changes,
                'sql_up' => $plan->sqlStatements,
                'risk_flags' => $plan->riskFlags,
                'estimated_rows' => $plan->estimatedRows,
                'created_at' => $now,
            ]);
        } catch (Throwable $e) {
            return $this->problem(422, 'Plan Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function applyChange(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $changeId = (string) ($args['change_id'] ?? $request->getAttribute('change_id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('schema', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT * FROM `{$prefix}schema_changes` WHERE `id` = ?",
            [$changeId]
        );
        $change = $stmt->fetch();
        if (!$change) {
            return $this->problem(404, 'Not Found', "Schema change '{$changeId}' not found.");
        }

        if ($change['status'] === 'applied') {
            return $this->problem(409, 'Conflict', "Schema change '{$changeId}' has already been applied.");
        }

        $targetDefinition = json_decode((string) ($change['after_state'] ?? '{}'), true) ?? [];
        $actor = $this->getActor($request);
        $body = $this->getBody($request);

        $plan = $this->planner->plan($targetDefinition);
        if (!empty($plan->riskFlags['data_loss'])) {
            $confirmToken = (string) ($body['confirm_token'] ?? $request->getHeaderLine('X-Confirm-Token'));
            $canonicalInput = ['change_id' => $changeId];
            if ($confirmToken === '') {
                $token = $this->confirmTokenService->generate($actor->id, 'schema.apply', $canonicalInput);
                return $this->json([
                    'type' => 'https://errors.bscms.org/confirmation-required',
                    'title' => 'Confirmation Required',
                    'status' => 409,
                    'detail' => 'Applying this schema change contains destructive modifications.',
                    'confirm_token' => $token,
                ], 409);
            }

            try {
                $this->confirmTokenService->validateAndConsume($confirmToken, $actor->id, 'schema.apply', $canonicalInput);
            } catch (Throwable $e) {
                return $this->problem(400, 'Confirmation Failed', $e->getMessage());
            }
        }

        try {
            $this->applier->apply($plan, $targetDefinition, appliedBy: $actor->id, appliedVia: 'api');

            $this->connection->execute(
                "UPDATE `{$prefix}schema_changes` SET `status` = 'applied' WHERE `id` = ?",
                [$changeId]
            );

            return $this->json(['success' => true, 'change_id' => $changeId]);
        } catch (Throwable $e) {
            return $this->problem(500, 'Apply Error', $e->getMessage());
        }
    }

    public function changes(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('schema', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $prefix = $this->connection->getPrefix();
        $stmt = $this->connection->execute(
            "SELECT `id`, `collection_id`, `change_type`, `applied_by`, `applied_via`, `dry_run`, `status`, `created_at`
             FROM `{$prefix}schema_changes`
             ORDER BY `created_at` DESC LIMIT 100"
        );
        $rows = $stmt->fetchAll();
        return $this->json($rows);
    }
}
