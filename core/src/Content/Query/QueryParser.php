<?php

declare(strict_types=1);

namespace BsCms\Content\Query;

use BsCms\Schema\IdentifierValidator;
use Psr\Http\Message\ServerRequestInterface;

final class QueryParser
{
    private const IDENTIFIER_REGEX = '/^[a-zA-Z0-9_]+$/';

    /**
     * @param array<string, mixed> $collection
     * @throws QueryValidationException
     */
    public function parse(ServerRequestInterface $request, array $collection): QueryOptions
    {
        $params = $request->getQueryParams();

        $collectionFields = (array) ($collection['fields'] ?? []);
        $systemColumns = IdentifierValidator::SYSTEM_COLUMNS;

        // 1. Parse filters
        $filters = $this->parseFilters($params, $collectionFields, $systemColumns);

        // 2. Parse sorting
        $sort = $this->parseSort($params, $collectionFields, $systemColumns, (string) ($collection['default_sort'] ?? 'id'));

        // 3. Parse search
        $search = isset($params['search']) && is_string($params['search']) ? trim($params['search']) : null;
        if ($search === '') {
            $search = null;
        }

        // 4. Parse sparse fieldsets (fields=)
        $fields = $this->parseFields($params, $collectionFields, $systemColumns);

        // 5. Parse pagination
        [$page, $size, $cursor] = $this->parsePagination($params);

        // 6. Parse populates (populate=)
        $populate = $this->parsePopulate($params, $collectionFields);

        // 7. Trashed / soft delete filter
        $includeTrashed = false;
        if (isset($params['trashed']) && ($params['trashed'] === '1' || $params['trashed'] === 'true')) {
            $includeTrashed = true;
        }

        return new QueryOptions(
            filters: $filters,
            sort: $sort,
            search: $search,
            fields: $fields,
            page: $page,
            size: $size,
            cursor: $cursor,
            populate: $populate,
            includeTrashed: $includeTrashed
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $collectionFields
     * @param list<string> $systemColumns
     * @return list<FilterClause>
     */
    private function parseFilters(array $params, array $collectionFields, array $systemColumns): array
    {
        $filters = [];
        $rawFilters = $params['filter'] ?? null;

        if (!is_array($rawFilters)) {
            return [];
        }

        foreach ($rawFilters as $rawField => $clause) {
            $field = (string) $rawField;

            // Injection defense on field identifier
            if (!preg_match(self::IDENTIFIER_REGEX, $field)) {
                throw new QueryValidationException("Invalid filter field name '{$field}'.", [
                    'filter' => ["Invalid filter field name '{$field}'."],
                ]);
            }

            $isSystem = in_array(strtolower($field), $systemColumns, true);
            $customDef = $collectionFields[$field] ?? null;

            if (!$isSystem && $customDef === null) {
                throw new QueryValidationException("Field '{$field}' does not exist on this collection.", [
                    'filter' => ["Field '{$field}' does not exist on this collection."],
                ]);
            }

            // Index requirement rule:
            // "All dynamic filter clauses require an index or are rejected with a helpful 422: 'Field X is not indexed; add an index before filtering on it.'"
            $isIndexed = false;
            if ($isSystem) {
                // System columns that are indexed or primary
                $indexedSystem = [
                    'id', 'slug', 'status', 'locale', 'parent_id', 'position',
                    'author_id', 'published_at', 'scheduled_at', 'created_at', 'updated_at',
                ];
                $isIndexed = in_array(strtolower($field), $indexedSystem, true);
            } else {
                $isIndexed = !empty($customDef['is_filterable']) || !empty($customDef['is_unique']) || !empty($customDef['is_sortable']);
            }

            if (!$isIndexed) {
                throw new QueryValidationException("Field '{$field}' is not indexed; add an index before filtering on it.", [
                    'filter' => ["Field '{$field}' is not indexed; add an index before filtering on it."],
                ]);
            }

            // Clause may be [operator => value] or direct scalar value (implied eq)
            if (is_array($clause)) {
                foreach ($clause as $op => $val) {
                    $opStr = strtolower((string) $op);
                    if (!in_array($opStr, FilterClause::ALLOWED_OPS, true)) {
                        throw new QueryValidationException("Operator '{$opStr}' is not supported.", [
                            'filter' => ["Operator '{$opStr}' is not supported."],
                        ]);
                    }

                    $filters[] = new FilterClause($field, $opStr, $val, $isSystem);
                }
            } else {
                $filters[] = new FilterClause($field, FilterClause::OP_EQ, $clause, $isSystem);
            }
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $collectionFields
     * @param list<string> $systemColumns
     * @return list<array{field: string, direction: 'asc'|'desc', is_system: bool}>
     */
    private function parseSort(array $params, array $collectionFields, array $systemColumns, string $defaultSort): array
    {
        $rawSort = isset($params['sort']) && is_string($params['sort']) ? trim($params['sort']) : '';
        if ($rawSort === '') {
            $rawSort = $defaultSort !== '' ? $defaultSort : 'id';
        }

        $parts = array_filter(array_map('trim', explode(',', $rawSort)));
        $sorts = [];

        foreach ($parts as $part) {
            $direction = 'asc';
            if (str_starts_with($part, '-')) {
                $direction = 'desc';
                $part = substr($part, 1);
            } elseif (str_starts_with($part, '+')) {
                $part = substr($part, 1);
            }

            if (!preg_match(self::IDENTIFIER_REGEX, $part)) {
                throw new QueryValidationException("Invalid sort field name '{$part}'.", [
                    'sort' => ["Invalid sort field name '{$part}'."],
                ]);
            }

            $isSystem = in_array(strtolower($part), $systemColumns, true);
            $customDef = $collectionFields[$part] ?? null;

            if (!$isSystem && $customDef === null) {
                throw new QueryValidationException("Field '{$part}' does not exist for sorting.", [
                    'sort' => ["Field '{$part}' does not exist for sorting."],
                ]);
            }

            $sorts[] = [
                'field' => $part,
                'direction' => $direction,
                'is_system' => $isSystem,
            ];
        }

        return $sorts;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $collectionFields
     * @param list<string> $systemColumns
     * @return list<string>|null
     */
    private function parseFields(array $params, array $collectionFields, array $systemColumns): ?array
    {
        $rawFields = $params['fields'] ?? null;
        if (!is_string($rawFields) || trim($rawFields) === '') {
            return null;
        }

        $parts = array_filter(array_map('trim', explode(',', $rawFields)));
        $validFields = [];

        foreach ($parts as $part) {
            if (!preg_match(self::IDENTIFIER_REGEX, $part)) {
                throw new QueryValidationException("Invalid field selection '{$part}'.", [
                    'fields' => ["Invalid field selection '{$part}'."],
                ]);
            }

            $isSystem = in_array(strtolower($part), $systemColumns, true);
            $customDef = $collectionFields[$part] ?? null;

            if (!$isSystem && $customDef === null) {
                throw new QueryValidationException("Field '{$part}' does not exist in collection schema.", [
                    'fields' => ["Field '{$part}' does not exist in collection schema."],
                ]);
            }

            $validFields[] = $part;
        }

        return array_values(array_unique($validFields));
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: int, 1: int, 2: ?string}
     */
    private function parsePagination(array $params): array
    {
        $page = 1;
        $size = 25;
        $cursor = null;

        if (isset($params['page']) && is_array($params['page'])) {
            if (isset($params['page']['number'])) {
                $page = max(1, (int) $params['page']['number']);
            }
            if (isset($params['page']['size'])) {
                $size = min(100, max(1, (int) $params['page']['size']));
            }
            if (isset($params['page']['after']) && is_string($params['page']['after'])) {
                $cursor = trim($params['page']['after']);
            }
        } else {
            if (isset($params['page']) && is_numeric($params['page'])) {
                $page = max(1, (int) $params['page']);
            }
            if (isset($params['limit']) && is_numeric($params['limit'])) {
                $size = min(100, max(1, (int) $params['limit']));
            }
        }

        return [$page, $size, $cursor];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $collectionFields
     * @return list<string>
     */
    private function parsePopulate(array $params, array $collectionFields): array
    {
        $rawPopulate = $params['populate'] ?? null;
        if (!is_string($rawPopulate) || trim($rawPopulate) === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', $rawPopulate)));
        $valid = [];

        foreach ($parts as $part) {
            if (!preg_match(self::IDENTIFIER_REGEX, $part)) {
                throw new QueryValidationException("Invalid populate relation name '{$part}'.", [
                    'populate' => ["Invalid populate relation name '{$part}'."],
                ]);
            }

            $fieldDef = $collectionFields[$part] ?? null;
            if ($fieldDef === null || ($fieldDef['type'] ?? '') !== 'relation') {
                throw new QueryValidationException("Field '{$part}' cannot be populated; must be a relation field.", [
                    'populate' => ["Field '{$part}' cannot be populated; must be a relation field."],
                ]);
            }

            $valid[] = $part;
        }

        // Limit to at most 6 relations to prevent N+1 depth explosion
        $unique = array_values(array_unique($valid));
        return array_slice($unique, 0, 6);
    }
}
