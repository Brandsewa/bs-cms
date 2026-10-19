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
use BsCms\Taxonomy\TermPresenter;
use BsCms\Taxonomy\TermRepository;

final class MoveTermOperation implements Operation
{
    public function __construct(
        private readonly TermRepository $terms,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'move_term';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Move a term to a different parent term in the hierarchy',
            inputSchema: [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'Term ID (ULID) to move',
                    ],
                    'parent_id' => [
                        'type' => 'string',
                        'description' => 'Target new parent term ID (or omit/null to make top-level)',
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
            throw new ForbiddenException('Authentication required to move term.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'update', new Subject('term', '*'));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to move term: {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $id = (string) ($input['id'] ?? '');
        $term = $this->terms->findById($id);
        if ($term === null) {
            throw new ValidationException("Term '{$id}' not found.");
        }

        $parentId = isset($input['parent_id']) && is_string($input['parent_id']) ? $input['parent_id'] : null;
        $moved = $this->terms->move($id, $parentId);

        return Result::ok([
            'term' => TermPresenter::forPublic($moved),
            'content_trust' => 'untrusted',
        ]);
    }
}
