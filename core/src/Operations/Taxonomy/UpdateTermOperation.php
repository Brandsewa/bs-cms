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

final class UpdateTermOperation implements Operation
{
    public function __construct(
        private readonly TermRepository $terms,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'update_term';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Update term name, slug, or description',
            inputSchema: [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'Term ID (ULID)',
                    ],
                    'name' => [
                        'type' => 'string',
                        'maxLength' => 100,
                        'description' => 'Updated term name',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'maxLength' => 100,
                        'description' => 'Updated slug',
                    ],
                    'description' => [
                        'type' => 'string',
                        'maxLength' => 500,
                        'description' => 'Updated description',
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
            throw new ForbiddenException('Authentication required to update term.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'update', new Subject('term', '*'));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to update term: {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $id = (string) ($input['id'] ?? '');
        $term = $this->terms->findById($id);
        if ($term === null) {
            throw new ValidationException("Term '{$id}' not found.");
        }

        $data = [];
        if (isset($input['name']) && is_string($input['name'])) {
            $data['name'] = $input['name'];
        }
        if (isset($input['slug']) && is_string($input['slug'])) {
            $data['slug'] = $input['slug'];
        }
        if (isset($input['description']) && is_string($input['description'])) {
            $data['description'] = $input['description'];
        }

        $updated = $this->terms->update($id, $data);

        return Result::ok([
            'term' => TermPresenter::forPublic($updated),
            'content_trust' => 'untrusted',
        ]);
    }
}
