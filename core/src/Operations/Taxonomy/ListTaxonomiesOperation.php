<?php

declare(strict_types=1);

namespace BsCms\Operations\Taxonomy;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Taxonomy\TaxonomyRepository;

final class ListTaxonomiesOperation implements Operation
{
    public function __construct(
        private readonly TaxonomyRepository $taxonomies,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'list_taxonomies';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'List all taxonomies',
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
            throw new ForbiddenException('Authentication required to list taxonomies.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('taxonomy', '*'));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to list taxonomies: {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $all = $this->taxonomies->all();
        $items = [];

        foreach ($all as $tax) {
            $items[] = [
                'id' => (string) $tax['id'],
                'handle' => (string) $tax['handle'],
                'name' => (string) $tax['name'],
                'hierarchical' => (bool) ($tax['hierarchical'] ?? false),
                'description' => (string) ($tax['description'] ?? ''),
            ];
        }

        return Result::ok([
            'taxonomies' => $items,
            'total' => count($items),
            'content_trust' => 'untrusted',
        ]);
    }
}
