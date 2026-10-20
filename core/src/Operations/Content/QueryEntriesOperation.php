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
        $collectionFields = (array) ($collection['fields'] ?? []);
        $limit = max(1, min(100, (int) ($input['limit'] ?? 20)));
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $page = (int) floor($offset / $limit) + 1;
        $status = isset($input['status']) && is_string($input['status']) ? $input['status'] : null;

        $filters = (array) ($input['filter'] ?? []);
        if ($status !== null) {
            $filters['status'] = $status;
        }

        $indexedSystem = [
            'id', 'slug', 'status', 'locale', 'parent_id', 'position',
            'author_id', 'published_at', 'scheduled_at', 'created_at', 'updated_at',
        ];

        $filterClauses = [];
        foreach ($filters as $fKey => $fVal) {
            $field = (string) $fKey;

            // Identifier syntax validation (prevent hostile keys like "title'; DROP TABLE ...")
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
                throw new ValidationException("Invalid filter field name '{$field}'.");
            }

            $isSystem = in_array(strtolower($field), IdentifierValidator::SYSTEM_COLUMNS, true);
            $customDef = $collectionFields[$field] ?? null;

            if (!$isSystem && $customDef === null) {
                throw new ValidationException("Field '{$field}' does not exist on collection '{$handle}'.");
            }

            // Index requirement rule:
            $isIndexed = false;
            if ($isSystem) {
                $isIndexed = in_array(strtolower($field), $indexedSystem, true);
            } else {
                $isTaxonomyField = ($customDef['type'] ?? '') === 'taxonomy';
                $isIndexed = $isTaxonomyField || !empty($customDef['is_filterable']) || !empty($customDef['is_unique']) || !empty($customDef['is_sortable']);
            }

            if (!$isIndexed) {
                throw new ValidationException("Field '{$field}' is not indexed; add an index before filtering on it.");
            }

            if (is_array($fVal)) {
                foreach ($fVal as $op => $val) {
                    $opStr = strtolower((string) $op);
                    if (!in_array($opStr, FilterClause::ALLOWED_OPS, true)) {
                        throw new ValidationException("Filter operator '{$opStr}' is not supported.");
                    }
                    $filterClauses[] = new FilterClause($field, $opStr, $val, $isSystem);
                }
            } else {
                $filterClauses[] = new FilterClause($field, FilterClause::OP_EQ, $fVal, $isSystem);
            }
        }

        // Validate and parse sort keys
        $sortClauses = [];
        $rawSort = isset($input['sort']) && is_string($input['sort']) ? trim($input['sort']) : '-created_at';
        if ($rawSort === '') {
            $rawSort = '-created_at';
        }

        $systemSortable = [
            'id', 'slug', 'title', 'status', 'locale', 'parent_id', 'position',
            'author_id', 'published_at', 'scheduled_at', 'created_at', 'updated_at',
        ];
        $customSortable = [];
        foreach ($collectionFields as $fHandle => $fDef) {
            if (!empty($fDef['is_sortable'])) {
                $customSortable[] = (string) $fHandle;
            }
        }
        $sortableFields = array_values(array_unique(array_merge($systemSortable, $customSortable)));

        $parts = array_filter(array_map('trim', explode(',', $rawSort)));
        foreach ($parts as $part) {
            $dir = 'asc';
            if (str_starts_with($part, '-')) {
                $dir = 'desc';
                $part = substr($part, 1);
            } elseif (str_starts_with($part, '+')) {
                $part = substr($part, 1);
            } elseif (str_contains($part, ':')) {
                [$f, $d] = explode(':', $part, 2);
                $part = $f;
                $dir = strtolower($d) === 'desc' ? 'desc' : 'asc';
            }

            if (!preg_match('/^[a-zA-Z0-9_]+$/', $part)) {
                throw new ValidationException("Invalid sort field name '{$part}'.");
            }

            $isSortable = in_array(strtolower($part), $systemSortable, true)
                || (isset($collectionFields[$part]) && !empty($collectionFields[$part]['is_sortable']));

            if (!$isSortable) {
                $sortableList = implode(', ', $sortableFields);
                throw new ValidationException("Field '{$part}' is not sortable on collection '{$handle}'. Sortable fields: {$sortableList}.");
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
