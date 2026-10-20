<?php

declare(strict_types=1);

namespace BsCms\Operations\System;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;

final class GetAuditLogOperation implements Operation
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'get_audit_log';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Query security and operational audit logs',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'default' => 20,
                    ],
                    'action' => [
                        'type' => 'string',
                        'description' => 'Filter by action name',
                    ],
                    'actor_type' => [
                        'type' => 'string',
                        'description' => 'Filter by actor type (user, oauth, api_token)',
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
            throw new ForbiddenException('Authentication required to read audit log.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('audit', '*'));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to view audit logs: {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $limit = max(1, min(100, (int) ($input['limit'] ?? 20)));
        $filters = [];

        if (isset($input['action']) && is_string($input['action'])) {
            $filters['action'] = $input['action'];
        }
        if (isset($input['actor_type']) && is_string($input['actor_type'])) {
            $filters['actor_type'] = $input['actor_type'];
        }

        $queryResult = $this->auditLogger->query($filters, $limit);
        $items = array_map(fn ($e) => $e->toArray(), $queryResult['items']);

        return Result::ok([
            'items' => $items,
            'total' => count($items),
            'next_cursor' => $queryResult['next_cursor'],
            'content_trust' => 'untrusted',
        ]);
    }
}
