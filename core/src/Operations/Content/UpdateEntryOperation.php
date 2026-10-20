<?php

declare(strict_types=1);

namespace BsCms\Operations\Content;

use BsCms\Auth\Actor;
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

final class UpdateEntryOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'update_entry';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Update an existing entry fields and content',
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
                    'title' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'description' => 'Updated title',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'maxLength' => 190,
                        'description' => 'Updated slug',
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Updated custom fields (accepts <field>_markdown for richtext)',
                    ],
                    'version' => [
                        'type' => 'integer',
                        'description' => 'Optimistic locking version number',
                    ],
                ],
                'additionalProperties' => false,
            ],
            mutates: true
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to update entry.');
        }

        $col = (string) ($input['collection'] ?? '');
        $entryId = (string) ($input['id'] ?? '');
        $existing = $entryId !== '' ? $this->repository->find($col, $entryId) : null;
        $decision = $this->authorizer->can($ctx->actor, 'update', new Subject('collection', $col), $existing);
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to update entry in collection '{$col}': {$decision->reason}");
        }

        if ($ctx->actor->aiMode === Actor::AI_MODE_DRAFT_ONLY) {
            if ($existing !== null && ($existing['status'] ?? 'draft') === 'published') {
                throw new ForbiddenException("Modifying published entries directly is forbidden in draft_only mode. Modify drafts only or request approval.");
            }
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

        $body = [];
        if (isset($input['title']) && is_string($input['title'])) {
            $body['title'] = $input['title'];
        }
        if (isset($input['slug']) && is_string($input['slug'])) {
            $body['slug'] = $input['slug'];
        }
        if (isset($input['version']) && is_int($input['version'])) {
            $body['version'] = $input['version'];
        }
        if (isset($input['data']) && is_array($input['data'])) {
            $body = array_merge($body, $input['data']);
        }

        $expectedVersion = isset($input['version']) && is_int($input['version']) ? $input['version'] : null;
        $changeSummary = isset($input['change_summary']) && is_string($input['change_summary']) ? $input['change_summary'] : null;

        $updated = $this->repository->update(
            collectionHandle: $handle,
            id: $id,
            input: $body,
            changeSummary: $changeSummary,
            expectedVersion: $expectedVersion,
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
