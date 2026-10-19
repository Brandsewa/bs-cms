<?php

declare(strict_types=1);

namespace BsCms\Operations\System;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use PDO;

final class ListPendingChangesOperation implements Operation
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'list_pending_changes';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'List changes awaiting human review and approval',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['pending', 'approved', 'rejected'],
                        'default' => 'pending',
                        'description' => 'Filter by status (pending, approved, rejected)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'default' => 20,
                    ],
                ],
                'additionalProperties' => false,
            ],
            mutates: false,
            idempotent: true
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to list pending changes.');
        }

        // Available to users who can read audit or manage collections
        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('pending_changes', '*'));
        if (!$decision->allowed) {
            $auditDecision = $this->authorizer->can($ctx->actor, 'read', new Subject('audit', '*'));
            if (!$auditDecision->allowed) {
                throw new ForbiddenException("Insufficient permissions to list pending changes: {$decision->reason}");
            }
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $status = (string) ($input['status'] ?? 'pending');
        $limit = max(1, min(100, (int) ($input['limit'] ?? 20)));

        $table = $this->connection->prefixTable('pending_changes');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("SELECT `id`, `actor_type`, `actor_label`, `operation`, `subject_type`, `subject_id`, `status`, `created_at`, `expires_at`
            FROM `{$table}`
            WHERE `status` = :status
            ORDER BY `created_at` DESC
            LIMIT :limit");
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (string) $row['id'],
                'actor_type' => (string) $row['actor_type'],
                'actor_label' => (string) $row['actor_label'],
                'operation' => (string) $row['operation'],
                'subject_type' => (string) $row['subject_type'],
                'subject_id' => $row['subject_id'] !== null ? (string) $row['subject_id'] : null,
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
                'expires_at' => (string) $row['expires_at'],
            ];
        }

        return Result::ok([
            'items' => $items,
            'total' => count($items),
            'status_filter' => $status,
            'content_trust' => 'untrusted',
        ]);
    }
}
