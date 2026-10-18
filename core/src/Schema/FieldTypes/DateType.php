<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class DateType extends BaseFieldType
{
    public function name(): string
    {
        return 'date';
    }

    public function label(): string
    {
        return 'Date';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'string',
            'format' => 'date',
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
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return ['Must be a valid date in YYYY-MM-DD format'];
        }
        [$year, $month, $day] = explode('-', $value);
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return ['Must be a valid calendar date'];
        }
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('DATE');
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'in', 'nin', 'lt', 'lte', 'gt', 'gte', 'between', 'null'];
    }

    public function isSortable(): bool
    {
        return true;
    }
}
