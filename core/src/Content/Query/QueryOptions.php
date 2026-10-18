<?php

declare(strict_types=1);

namespace BsCms\Content\Query;

final class QueryOptions
{
    /**
     * @param list<FilterClause> $filters
     * @param list<array{field: string, direction: 'asc'|'desc', is_system: bool}> $sort
     * @param list<string>|null $fields
     * @param list<string> $populate
     */
    public function __construct(
        public readonly array $filters = [],
        public readonly array $sort = [],
        public readonly ?string $search = null,
        public readonly ?array $fields = null,
        public readonly int $page = 1,
        public readonly int $size = 25,
        public readonly ?string $cursor = null,
        public readonly array $populate = [],
        public readonly bool $includeTrashed = false,
        public readonly ?string $ownerFilter = null,
        public readonly bool $includeDescendants = false
    ) {
    }
}
