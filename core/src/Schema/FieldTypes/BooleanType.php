<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class BooleanType extends BaseFieldType
{
    public function name(): string
    {
        return 'boolean';
    }

    public function label(): string
    {
        return 'Boolean (True / False)';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return ['type' => 'boolean'];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        return (bool) $value;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_bool($value)) {
            return ['Must be a boolean'];
        }
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('TINYINT(1)');
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'null'];
    }

    public function isSortable(): bool
    {
        return true;
    }
}
