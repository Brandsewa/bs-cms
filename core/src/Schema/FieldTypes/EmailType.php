<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class EmailType extends BaseFieldType
{
    public function name(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email Address';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'string',
            'format' => 'email',
            'maxLength' => 190,
        ];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_scalar($value) ? strtolower(trim((string) $value)) : null;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return ['Must be a valid email address'];
        }
        if (strlen($value) > 190) {
            return ['Email must not exceed 190 characters'];
        }
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('VARCHAR(190)');
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'in', 'nin', 'contains', 'starts_with', 'null'];
    }

    public function isSortable(): bool
    {
        return true;
    }
}
