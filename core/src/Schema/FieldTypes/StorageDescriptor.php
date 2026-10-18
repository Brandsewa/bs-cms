<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class StorageDescriptor
{
    public const MODE_COLUMN = 'column';
    public const MODE_JSON = 'json';
    public const MODE_RELATION = 'relation';
    public const MODE_NONE = 'none';

    public function __construct(
        public readonly string $mode = self::MODE_JSON,
        public readonly ?string $columnType = null,
        public readonly ?string $virtualDdl = null
    ) {
    }

    public static function column(string $columnType): self
    {
        return new self(self::MODE_COLUMN, $columnType);
    }

    public static function json(): self
    {
        return new self(self::MODE_JSON);
    }

    public static function relation(): self
    {
        return new self(self::MODE_RELATION);
    }

    public static function none(): self
    {
        return new self(self::MODE_NONE);
    }
}
