<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class TimeType extends BaseFieldType
{
    public function name(): string
    {
        return 'time';
    }

    public function label(): string
    {
        return 'Time';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'string',
            'pattern' => '^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$',
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
        if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value)) {
            return ['Must be a valid time in HH:MM or HH:MM:SS format'];
        }
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('TIME');
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'null'];
    }

    public function isSortable(): bool
    {
        return true;
    }
}
