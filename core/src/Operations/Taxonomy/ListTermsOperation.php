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

final class ListTermsOperation implements Operation
{
    public function __construct(
        private readonly TaxonomyRepository $taxonomies,
        private readonly TermRepository $terms,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'list_terms';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'List terms belonging to a taxonomy',
            inputSchema: [
                'type' => 'object',
                'required' => ['taxonomy'],
                'properties' => [
                    'taxonomy' => [
                        'type' => 'string',
                        'description' => 'Taxonomy handle or ID',
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
            throw new ForbiddenException('Authentication required to list terms.');
        }

        $tax = (string) ($input['taxonomy'] ?? '');
        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('term', $tax));
        if (!$decision->allowed) {
            $taxDecision = $this->authorizer->can($ctx->actor, 'read', new Subject('taxonomy', $tax));
            if (!$taxDecision->allowed) {
                throw new ForbiddenException("Insufficient permissions to list terms for '{$tax}': {$decision->reason}");
            }
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $taxInput = (string) ($input['taxonomy'] ?? '');
        $taxonomy = $this->taxonomies->findByHandle($taxInput) ?? $this->taxonomies->findById($taxInput);
        if ($taxonomy === null) {
            throw new ValidationException("Taxonomy '{$taxInput}' not found.");
        }

        $rawTerms = $this->terms->listTree((string) $taxonomy['id']);
        $items = [];
        foreach ($rawTerms as $term) {
            $items[] = TermPresenter::forPublic($term);
        }

        return Result::ok([
            'taxonomy' => (string) $taxonomy['handle'],
            'terms' => $items,
            'total' => count($items),
            'content_trust' => 'untrusted',
        ]);
    }
}
