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

final class ScheduleEntryOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'schedule_entry';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Schedule an entry for future publication',
            inputSchema: [
                'type' => 'object',
                'required' => ['collection', 'id', 'publish_at'],
                'properties' => [
                    'collection' => [
                        'type' => 'string',
                        'description' => 'Collection handle',
                    ],
                    'id' => [
                        'type' => 'string',
                        'description' => 'Entry ID (ULID)',
                    ],
                    'publish_at' => [
                        'type' => 'string',
                        'description' => 'Scheduled publication datetime in ISO 8601 format',
                    ],
                ],
                'additionalProperties' => false,
            ],
            mutates: true,
            aiPolicy: OperationMeta::AI_POLICY_DRAFT_ONLY_BLOCKED
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to schedule entry.');
        }

        $col = (string) ($input['collection'] ?? '');
        $decision = $this->authorizer->can($ctx->actor, 'schedule', new Subject('collection', $col));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to schedule entry in collection '{$col}': {$decision->reason}");
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
        $publishAt = (string) ($input['publish_at'] ?? '');

        if ($id === '') {
            throw new ValidationException("Entry ID is required.");
        }

        if ($publishAt === '' || strtotime($publishAt) === false) {
            throw new ValidationException("Field 'publish_at' must be a valid future ISO-8601 date-time string.");
        }

        $targetTs = (int) strtotime($publishAt);
        if ($targetTs <= time()) {
            throw new ValidationException("Scheduled publication date 'publish_at' must be in the future.");
        }

        $actor = $ctx->actor ?? \BsCms\Auth\Actor::anonymous();
        $userId = $actor->onBehalfOf ?? $actor->id;

        $body = [
            'status' => 'scheduled',
            'scheduled_at' => date('Y-m-d H:i:s', $targetTs),
        ];

        try {
            $updated = $this->repository->update(
                collectionHandle: $handle,
                id: $id,
                input: $body,
                changeSummary: 'Scheduled via API',
                expectedVersion: null,
                actorType: $actor->type,
                actorLabel: $actor->label,
                updatedBy: $userId
            );
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage(), ['publish_at' => [$e->getMessage()]]);
        }

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
