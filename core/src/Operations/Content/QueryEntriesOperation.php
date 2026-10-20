<?php

declare(strict_types=1);

namespace BsCms\Operations\Content;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\EntryRepository;
use BsCms\Content\Presenters\EntryPresenter;
use BsCms\Content\Query\FilterClause;
use BsCms\Content\Query\QueryOptions;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\IdentifierValidator;

final class QueryEntriesOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'query_entries';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Query entries from a collection with optional filters, pagination, and sorting',
            inputSchema: [
                'type' => 'object',
                'required' => ['collection'],
                'properties' => [
                    'collection' => [
                        'type' => 'string',
                        'description' => 'Collection handle to query',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'default' => 20,
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'published', 'scheduled', 'archived'],
                    ],
                    'sort' => [
                        'type' => 'string',
                    ],
                    'filter' => [
                        'type' => 'object',
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
            throw new ForbiddenException('Authentication required to query entries.');
        }

        $col = (string) ($input['collection'] ?? '');
        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('collection', $col));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to query collection '{$col}': {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $handle = (string) ($input['collection'] ?? '');
        if (!$this->registry->has($handle)) {
            throw new ValidationException("Collection '{$handle}' not found.");
        }

        $collection = $this->registry->get($handle);
        $limit = max(1, min(100, (int) ($input['limit'] ?? 20)));
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $status = isset($input['status']) && is_string($input['status']) ? $input['status'] : null;

        $filters = (array) ($input['filter'] ?? []);
        if ($status !== null) {
            $filters['status'] = $status;
        }

        $page = (int) floor($offset / $limit) + 1;
        $filterClauses = [];
        foreach ($filters as $fKey => $fVal) {
            $isSystem = in_array((string) $fKey, IdentifierValidator::SYSTEM_COLUMNS, true);
            if (is_array($fVal)) {
                foreach ($fVal as $op => $val) {
                    $filterClauses[] = new FilterClause((string) $fKey, (string) $op, $val, $isSystem);
                }
            } else {
                $filterClauses[] = new FilterClause((string) $fKey, FilterClause::OP_EQ, $fVal, $isSystem);
            }
        }

        $sortClauses = [];
        $rawSort = isset($input['sort']) && is_string($input['sort']) ? $input['sort'] : '-created_at';
        $parts = array_filter(array_map('trim', explode(',', $rawSort)));
        foreach ($parts as $part) {
            $dir = 'asc';
            if (str_starts_with($part, '-')) {
                $dir = 'desc';
                $part = substr($part, 1);
            } elseif (str_contains($part, ':')) {
                [$f, $d] = explode(':', $part, 2);
                $part = $f;
                $dir = strtolower($d) === 'desc' ? 'desc' : 'asc';
            }
            $isSystem = in_array(strtolower($part), IdentifierValidator::SYSTEM_COLUMNS, true);
            $sortClauses[] = ['field' => $part, 'direction' => $dir, 'is_system' => $isSystem];
        }

        $options = new QueryOptions(
            filters: $filterClauses,
            sort: $sortClauses,
            page: $page,
            size: $limit
        );

        $queryResult = $this->repository->query(
            collectionHandle: $handle,
            options: $options
        );

        $items = [];
        $actor = $ctx->actor ?? \BsCms\Auth\Actor::anonymous();
        $entries = $queryResult['data'];
        foreach ($entries as $entry) {
            $items[] = EntryPresenter::forAuthenticated(
                $entry,
                $collection,
                $actor,
                $this->authorizer,
                false
            );
        }

        $total = $queryResult['meta']['pagination']['total'];

        return Result::ok([
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'content_trust' => 'untrusted',
        ]);
    }
}
