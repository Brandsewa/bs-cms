<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class NumberType extends BaseFieldType
{
    public function name(): string
    {
        return 'number';
    }

    public function label(): string
    {
        return 'Number';
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        $precision = $config['precision'] ?? 0;
        if (!is_int($precision) || $precision < 0 || $precision > 10) {
            $errors['precision'] = 'precision must be an integer between 0 and 10';
        }
        if (isset($config['min']) && !is_numeric($config['min'])) {
            $errors['min'] = 'min must be a numeric value';
        }
        if (isset($config['max']) && !is_numeric($config['max'])) {
            $errors['max'] = 'max must be a numeric value';
        }
        if (isset($config['min'], $config['max']) && is_numeric($config['min']) && is_numeric($config['max']) && $config['min'] > $config['max']) {
            $errors['min'] = 'min cannot be greater than max';
        }
        return $errors;
    }

    public function jsonSchema(array $config): array
    {
        $precision = $config['precision'] ?? 0;
        $schema = [
            'type' => $precision > 0 ? 'number' : 'integer',
        ];
        if (isset($config['min'])) {
            $schema['minimum'] = $config['min'];
        }
        if (isset($config['max'])) {
            $schema['maximum'] = $config['max'];
        }
        return $schema;
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $precision = $config['precision'] ?? 0;
        if (!is_numeric($value)) {
            return $value;
        }
        return $precision > 0 ? (float) $value : (int) $value;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_numeric($value)) {
            return ['Must be a numeric value'];
        }
        $num = (float) $value;
        $errors = [];
        if (isset($config['min']) && $num < $config['min']) {
            $errors[] = sprintf('Must be greater than or equal to %s', (string) $config['min']);
        }
        if (isset($config['max']) && $num > $config['max']) {
            $errors[] = sprintf('Must be less than or equal to %s', (string) $config['max']);
        }
        return $errors;
    }

    public function storage(array $config): StorageDescriptor
    {
        $precision = $config['precision'] ?? 0;
        if ($precision > 0) {
            $scale = is_int($precision) ? $precision : 2;
            return StorageDescriptor::column(sprintf('DECIMAL(12,%d)', $scale));
        }
        return StorageDescriptor::column('INT');
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
