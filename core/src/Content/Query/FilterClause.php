<?php

declare(strict_types=1);

namespace BsCms\Content\Query;

final class FilterClause
{
    public const OP_EQ = 'eq';
    public const OP_NE = 'ne';
    public const OP_GT = 'gt';
    public const OP_GTE = 'gte';
    public const OP_LT = 'lt';
    public const OP_LTE = 'lte';
    public const OP_IN = 'in';
    public const OP_NIN = 'nin';
    public const OP_LIKE = 'like';
    public const OP_NULL = 'null';
    public const OP_NNULL = 'nnull';
    public const OP_ALL = 'all';

    public const ALLOWED_OPS = [
        self::OP_EQ,
        self::OP_NE,
        self::OP_GT,
        self::OP_GTE,
        self::OP_LT,
        self::OP_LTE,
        self::OP_IN,
        self::OP_NIN,
        self::OP_LIKE,
        self::OP_NULL,
        self::OP_NNULL,
        self::OP_ALL,
    ];

    /**
     * @param mixed $value
     */
    public function __construct(
        public readonly string $field,
        public readonly string $operator,
        public readonly mixed $value,
        public readonly bool $isSystemColumn = false
    ) {
    }
}
