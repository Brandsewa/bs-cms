<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class UserType extends BaseFieldType
{
    public function name(): string
    {
        return 'user';
    }

    public function label(): string
    {
        return 'User Reference (Author/Owner)';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'string',
            'pattern' => '^[0-7][0-9A-HJKMNP-TV-Z]{25}$',
        ];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_scalar($value) ? trim((string) $value) : null;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value) || !preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value)) {
            return ['Must be a valid user ULID'];
        }
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('CHAR(26) CHARACTER SET ascii COLLATE ascii_bin');
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'in', 'nin', 'null'];
    }

    public function isSortable(): bool
    {
        return true;
    }
}
