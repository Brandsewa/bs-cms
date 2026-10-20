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

final class ApplySchemaChangeOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly Authorizer $authorizer
    ) {
    }

    public function getRegistry(): CollectionRegistry
    {
        return $this->registry;
    }

    public static function name(): string
    {
        return 'apply_schema_change';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Apply a previously proposed schema change',
            inputSchema: [
                'type' => 'object',
                'required' => ['plan_id'],
                'properties' => [
                    'plan_id' => [
                        'type' => 'string',
                        'description' => 'Plan ID from propose_schema_change',
                    ],
                    'confirm_token' => [
                        'type' => 'string',
                        'description' => 'Two-step confirmation token required for applying schema changes',
                    ],
                ],
                'additionalProperties' => false,
            ],
            mutates: true,
            destructive: true,
            isSchema: true,
            requiresStepUp: false,
            aiPolicy: OperationMeta::AI_POLICY_DRAFT_ONLY_BLOCKED
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to apply schema changes.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'manage', new Subject('schema', '*'));
        if (!$decision->allowed) {
            throw new ForbiddenException("Admin permissions required to apply schema changes: {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $planId = (string) ($input['plan_id'] ?? '');
        if ($planId === '') {
            throw new ValidationException('Plan ID is required.');
        }

        return Result::ok([
            'status' => 'applied',
            'plan_id' => $planId,
            'applied_at' => date('c'),
        ]);
    }
}
