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

final class DescribeCollectionOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'describe_collection';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Describe a collection schema, field definitions, and validation rules',
            inputSchema: [
                'type' => 'object',
                'required' => ['collection'],
                'properties' => [
                    'collection' => [
                        'type' => 'string',
                        'description' => 'Collection handle to describe',
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
            throw new ForbiddenException('Authentication required to describe collection.');
        }

        $colHandle = (string) ($input['collection'] ?? '');
        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('collection', $colHandle));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to describe collection '{$colHandle}': {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $handle = (string) ($input['collection'] ?? '');
        if (!$this->registry->has($handle)) {
            throw new ValidationException("Collection '{$handle}' not found.");
        }

        $col = $this->registry->get($handle);
        $rawFields = (array) ($col['fields'] ?? []);
        $fields = [];
        foreach ($rawFields as $fieldHandle => $fieldDef) {
            $defArray = is_array($fieldDef) ? $fieldDef : [];
            $fields[(string) $fieldHandle] = [
                'name' => $defArray['name'] ?? $fieldHandle,
                'type' => $defArray['type'] ?? 'string',
                'required' => (bool) ($defArray['required'] ?? false),
                'description' => (string) ($defArray['description'] ?? ''),
            ];
        }

        return Result::ok([
            'handle' => (string) ($col['handle'] ?? $handle),
            'name' => (string) ($col['label_singular'] ?? $col['name'] ?? $handle),
            'description' => (string) ($col['description'] ?? ''),
            'is_singleton' => ($col['kind'] ?? '') === 'single',
            'fields' => $fields,
            'statuses' => ['draft', 'published', 'scheduled', 'archived'],
            'content_trust' => 'untrusted',
        ]);
    }
}
