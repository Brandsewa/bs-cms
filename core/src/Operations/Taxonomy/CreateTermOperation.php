<?php

declare(strict_types=1);

namespace BsCms\Operations\Taxonomy;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Taxonomy\TaxonomyRepository;
use BsCms\Taxonomy\TermPresenter;
use BsCms\Taxonomy\TermRepository;

final class CreateTermOperation implements Operation
{
    public function __construct(
        private readonly TaxonomyRepository $taxonomies,
        private readonly TermRepository $terms,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'create_term';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Create a new term in a taxonomy',
            inputSchema: [
                'type' => 'object',
                'required' => ['taxonomy', 'name'],
                'properties' => [
                    'taxonomy' => [
                        'type' => 'string',
                        'description' => 'Taxonomy handle or ID',
                    ],
                    'name' => [
                        'type' => 'string',
                        'maxLength' => 100,
                        'description' => 'Term name',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'maxLength' => 100,
                        'description' => 'Optional slug',
                    ],
                    'parent_id' => [
                        'type' => 'string',
                        'description' => 'Optional parent term ID for hierarchical taxonomy',
                    ],
                    'description' => [
                        'type' => 'string',
                        'maxLength' => 500,
                        'description' => 'Optional description',
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
            throw new ForbiddenException('Authentication required to create term.');
        }

        $tax = (string) ($input['taxonomy'] ?? '');
        $decision = $this->authorizer->can($ctx->actor, 'create', new Subject('term', $tax));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to create term in '{$tax}': {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $taxInput = (string) ($input['taxonomy'] ?? '');
        $taxonomy = $this->taxonomies->findByHandle($taxInput) ?? $this->taxonomies->findById($taxInput);
        if ($taxonomy === null) {
            throw new ValidationException("Taxonomy '{$taxInput}' not found.");
        }

        $taxonomyId = (string) $taxonomy['id'];
        $created = $this->terms->create($taxonomyId, [
            'name' => (string) ($input['name'] ?? ''),
            'slug' => isset($input['slug']) && is_string($input['slug']) ? $input['slug'] : null,
            'parent_id' => isset($input['parent_id']) && is_string($input['parent_id']) ? $input['parent_id'] : null,
            'description' => isset($input['description']) && is_string($input['description']) ? $input['description'] : null,
        ]);

        return Result::created([
            'term' => TermPresenter::forPublic($created),
            'content_trust' => 'untrusted',
        ]);
    }
}
