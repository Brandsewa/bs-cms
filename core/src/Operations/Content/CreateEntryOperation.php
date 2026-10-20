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

final class CreateEntryOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'create_entry';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Create a new draft entry in a collection',
            inputSchema: [
                'type' => 'object',
                'required' => ['collection', 'title'],
                'properties' => [
                    'collection' => [
                        'type' => 'string',
                        'description' => 'Collection handle',
                    ],
                    'title' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'description' => 'Entry title',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'maxLength' => 190,
                        'description' => 'URL slug (auto-generated if omitted)',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'published'],
                        'default' => 'draft',
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Custom collection fields payload (accepts <field>_markdown for richtext)',
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
            throw new ForbiddenException('Authentication required to create entry.');
        }

        $col = (string) ($input['collection'] ?? '');
        $status = (string) ($input['status'] ?? 'draft');
        $decision = $this->authorizer->can($ctx->actor, 'create', new Subject('collection', $col), ['status' => $status]);
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to create entry in collection '{$col}': {$decision->reason}");
        }

        if ($status === 'published') {
            if ($ctx->actor->aiMode === Actor::AI_MODE_DRAFT_ONLY) {
                throw new ForbiddenException("Creating published entries is forbidden in draft_only mode. Create entries as draft or request approval.");
            }
            $pubDecision = $this->authorizer->can($ctx->actor, 'publish', new Subject('collection', $col));
            if (!$pubDecision->allowed) {
                throw new ForbiddenException("Insufficient permissions to publish entry in collection '{$col}': {$pubDecision->reason}");
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
        $actor = $ctx->actor ?? \BsCms\Auth\Actor::anonymous();
        $userId = $actor->onBehalfOf ?? $actor->id;

        $body = [
            'title' => (string) ($input['title'] ?? ''),
            'status' => (string) ($input['status'] ?? 'draft'),
        ];

        if (isset($input['slug']) && is_string($input['slug'])) {
            $body['slug'] = $input['slug'];
        }

        if (isset($input['data']) && is_array($input['data'])) {
            $body = array_merge($body, $input['data']);
        }

        $created = $this->repository->create($handle, $body, $actor->type, $actor->label, $userId);

        $presented = EntryPresenter::forAuthenticated(
            $created,
            $collection,
            $actor,
            $this->authorizer,
            false
        );

        return Result::created([
            'id' => (string) ($created['id'] ?? ''),
            'entry' => $presented,
            'content_trust' => 'untrusted',
        ]);
    }
}
