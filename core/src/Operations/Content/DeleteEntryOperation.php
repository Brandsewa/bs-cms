<?php

declare(strict_types=1);

namespace BsCms\Operations\Content;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\EntryRepository;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Schema\CollectionRegistry;

final class DeleteEntryOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'delete_entry';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Delete an entry (soft delete or permanent trash)',
            inputSchema: [
                'type' => 'object',
                'required' => ['collection', 'id'],
                'properties' => [
                    'collection' => [
                        'type' => 'string',
                        'description' => 'Collection handle',
                    ],
                    'id' => [
                        'type' => 'string',
                        'description' => 'Entry ID (ULID)',
                    ],
                    'permanent' => [
                        'type' => 'boolean',
                        'description' => 'If true, permanently remove instead of soft deleting',
                        'default' => false,
                    ],
                    'confirm_token' => [
                        'type' => 'string',
                        'description' => 'Two-step confirmation token required for destructive deletion',
                    ],
                ],
                'additionalProperties' => false,
            ],
            mutates: true,
            destructive: true,
            idempotent: true,
            aiPolicy: OperationMeta::AI_POLICY_DRAFT_ONLY_BLOCKED
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to delete entry.');
        }

        $col = (string) ($input['collection'] ?? '');
        $decision = $this->authorizer->can($ctx->actor, 'delete', new Subject('collection', $col));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to delete entry in collection '{$col}': {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $handle = (string) ($input['collection'] ?? '');
        if (!$this->registry->has($handle)) {
            throw new ValidationException("Collection '{$handle}' not found.");
        }

        $id = (string) ($input['id'] ?? '');
        $permanent = (bool) ($input['permanent'] ?? false);

        $this->repository->delete($handle, $id, $permanent);

        return Result::ok([
            'status' => 'deleted',
            'deleted' => true,
            'id' => $id,
            'collection' => $handle,
            'permanent' => $permanent,
        ]);
    }
}
