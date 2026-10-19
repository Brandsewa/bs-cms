<?php

declare(strict_types=1);

namespace BsCms\Operations\Schema;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Schema\CollectionRegistry;

final class ProposeSchemaChangeOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'propose_schema_change';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Propose a collection schema modification or new collection',
            inputSchema: [
                'type' => 'object',
                'required' => ['action', 'collection'],
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['create_collection', 'add_field', 'update_field', 'delete_field'],
                        'description' => 'Type of schema modification to propose',
                    ],
                    'collection' => [
                        'type' => 'string',
                        'description' => 'Collection handle to modify or create',
                    ],
                    'definition' => [
                        'type' => 'object',
                        'description' => 'Schema or field definition parameters',
                    ],
                ],
                'additionalProperties' => false,
            ],
            mutates: false,
            isSchema: true
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to propose schema changes.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'manage', new Subject('schema', '*'));
        if (!$decision->allowed) {
            throw new ForbiddenException("Admin permissions required to propose schema changes: {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $action = (string) ($input['action'] ?? '');
        $col = (string) ($input['collection'] ?? '');
        $def = (array) ($input['definition'] ?? []);

        if ($action === '') {
            throw new ValidationException('Action must be specified.');
        }

        if ($action !== 'create_collection' && $col !== '' && !$this->registry->has($col)) {
            throw new ValidationException("Collection '{$col}' not found.");
        }

        $planId = 'plan_' . bin2hex(random_bytes(10));
        $diff = [
            'action' => $action,
            'collection' => $col,
            'definition' => $def,
            'created_at' => date('c'),
        ];

        return Result::ok([
            'plan_id' => $planId,
            'status' => 'proposed',
            'action' => $action,
            'collection' => $col,
            'diff' => $diff,
            'requires_confirmation' => true,
        ]);
    }
}
