<?php

declare(strict_types=1);

namespace BsCms\Operations\Content;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\EntryRepository;
use BsCms\Content\Presenters\EntryPresenter;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Schema\CollectionRegistry;

final class PublishEntryOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'publish_entry';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Publish a draft entry making it publicly accessible',
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
                ],
                'additionalProperties' => false,
            ],
            mutates: true,
            idempotent: true,
            aiPolicy: OperationMeta::AI_POLICY_DRAFT_ONLY_BLOCKED
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to publish entry.');
        }

        $col = (string) ($input['collection'] ?? '');
        $decision = $this->authorizer->can($ctx->actor, 'publish', new Subject('collection', $col));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to publish entry in collection '{$col}': {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $handle = (string) ($input['collection'] ?? '');
        if (!$this->registry->has($handle)) {
            throw new ValidationException("Collection '{$handle}' not found.");
        }

        $collection = $this->registry->get($handle);
        $id = (string) ($input['id'] ?? '');
        $existing = $this->repository->find($handle, $id);
        if ($existing === null) {
            throw new ValidationException("Target entry '{$id}' not found in collection '{$handle}'.");
        }

        $actor = $ctx->actor ?? \BsCms\Auth\Actor::anonymous();
        $userId = $actor->onBehalfOf ?? $actor->id;

        $body = [
            'status' => 'published',
            'published_at' => date('Y-m-d H:i:s'),
        ];

        $updated = $this->repository->update(
            collectionHandle: $handle,
            id: $id,
            input: $body,
            changeSummary: 'Published via API',
            expectedVersion: null,
            actorType: $actor->type,
            actorLabel: $actor->label,
            updatedBy: $userId
        );

        $presented = EntryPresenter::forAuthenticated(
            $updated,
            $collection,
            $actor,
            $this->authorizer,
            false
        );

        return Result::ok([
            'id' => (string) ($updated['id'] ?? $id),
            'entry' => $presented,
            'content_trust' => 'untrusted',
        ]);
    }
}
