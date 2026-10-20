<?php

declare(strict_types=1);

namespace BsCms\Operations\Content;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\EntryRepository;
use BsCms\Content\Presenters\EntryPresenter;
use BsCms\Content\VersionManager;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Schema\CollectionRegistry;

final class RestoreVersionOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly VersionManager $versionManager,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'restore_version';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Restore an entry to a previous historical version',
            inputSchema: [
                'type' => 'object',
                'required' => ['collection', 'id', 'version'],
                'properties' => [
                    'collection' => [
                        'type' => 'string',
                        'description' => 'Collection handle',
                    ],
                    'id' => [
                        'type' => 'string',
                        'description' => 'Entry ID (ULID)',
                    ],
                    'version' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Target historical version number to restore',
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
            throw new ForbiddenException('Authentication required to restore version.');
        }

        $col = (string) ($input['collection'] ?? '');
        $decision = $this->authorizer->can($ctx->actor, 'update', new Subject('collection', $col));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to restore version in collection '{$col}': {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $handle = (string) ($input['collection'] ?? '');
        if (!$this->registry->has($handle)) {
            throw new ValidationException("Collection '{$handle}' not found.");
        }

        $collection = $this->registry->get($handle);
        $collectionId = (string) $collection['id'];
        $id = (string) ($input['id'] ?? '');
        $versionNo = (int) ($input['version'] ?? 0);

        $targetVersion = $this->versionManager->getVersion($collectionId, $id, $versionNo);
        if ($targetVersion === null) {
            throw new ValidationException("Version '{$versionNo}' not found for entry '{$id}'.");
        }

        $actor = $ctx->actor ?? \BsCms\Auth\Actor::anonymous();
        $userId = $actor->onBehalfOf ?? $actor->id;
        $snapshot = (array) ($targetVersion['snapshot'] ?? []);

        $restored = $this->repository->update(
            collectionHandle: $handle,
            id: $id,
            input: $snapshot,
            changeSummary: "Restored from version {$versionNo}",
            expectedVersion: null,
            actorType: $actor->type,
            actorLabel: $actor->label,
            updatedBy: $userId
        );

        $presented = EntryPresenter::forAuthenticated(
            $restored,
            $collection,
            $actor,
            $this->authorizer,
            false
        );

        return Result::ok([
            'id' => (string) ($restored['id'] ?? $id),
            'entry' => $presented,
            'restored_version' => $versionNo,
            'content_trust' => 'untrusted',
        ]);
    }
}
