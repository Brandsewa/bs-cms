<?php

declare(strict_types=1);

namespace BsCms\Operations\Content;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\VersionManager;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Schema\CollectionRegistry;

final class ListVersionsOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly VersionManager $versionManager,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'list_versions';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'List historical revisions for an entry',
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
            mutates: false,
            idempotent: true
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to list versions.');
        }

        $col = (string) ($input['collection'] ?? '');
        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('collection', $col));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to list versions in collection '{$col}': {$decision->reason}");
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

        $versions = $this->versionManager->listVersions($collectionId, $id);

        $items = [];
        foreach ($versions as $v) {
            $items[] = [
                'version' => (int) ($v['version_no'] ?? $v['version'] ?? 1),
                'created_at' => (string) ($v['created_at'] ?? ''),
                'created_by' => (string) ($v['created_by'] ?? ''),
                'actor_type' => (string) ($v['actor_type'] ?? 'user'),
                'change_summary' => (string) ($v['change_summary'] ?? ''),
            ];
        }

        return Result::ok([
            'versions' => $items,
            'total' => count($items),
            'content_trust' => 'untrusted',
        ]);
    }
}
