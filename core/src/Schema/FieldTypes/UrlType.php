<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class UrlType extends BaseFieldType
{
    public function name(): string
    {
        return 'url';
    }

    public function label(): string
    {
        return 'URL';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'string',
            'format' => 'uri',
            'maxLength' => 2048,
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
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return ['Must be a valid URL'];
        }
        if (strlen($value) > 2048) {
            return ['URL must not exceed 2048 characters'];
        }
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('VARCHAR(2048)');
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'contains', 'starts_with', 'null'];
    }

    public function isSortable(): bool
    {
        return false;
    }
}
