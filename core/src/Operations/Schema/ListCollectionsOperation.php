<?php

declare(strict_types=1);

namespace BsCms\Operations\Schema;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Schema\CollectionRegistry;

final class ListCollectionsOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'list_collections';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'List all content collections with schema metadata and counts',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [],
                'additionalProperties' => false,
            ],
            mutates: false,
            idempotent: true
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to list collections.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('collection', '*'));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to list collections: {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $all = $this->registry->all();
        $items = [];

        foreach ($all as $col) {
            // Check individual collection read permission
            $handle = (string) ($col['handle'] ?? '');
            if ($ctx->actor !== null) {
                $canRead = $this->authorizer->can($ctx->actor, 'read', new Subject('collection', $handle));
                if (!$canRead->allowed) {
                    continue;
                }
            }

            $fields = (array) ($col['fields'] ?? []);
            $items[] = [
                'handle' => $handle,
                'name' => (string) ($col['label_singular'] ?? $col['name'] ?? $handle),
                'description' => (string) ($col['description'] ?? ''),
                'fields_count' => count($fields),
                'is_singleton' => ($col['kind'] ?? '') === 'single',
            ];
        }

        return Result::ok([
            'items' => $items,
            'total' => count($items),
            'content_trust' => 'untrusted',
        ]);
    }
}
