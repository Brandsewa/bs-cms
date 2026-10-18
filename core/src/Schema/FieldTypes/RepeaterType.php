<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class RepeaterType extends BaseFieldType
{
    public function name(): string
    {
        return 'repeater';
    }

    public function label(): string
    {
        return 'Repeater (Array of Rows)';
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (!isset($config['fields']) || !is_array($config['fields']) || empty($config['fields'])) {
            $errors['fields'] = 'fields must be a non-empty array of sub-field definitions';
        }
        if (isset($config['min_rows']) && (!is_int($config['min_rows']) || $config['min_rows'] < 0)) {
            $errors['min_rows'] = 'min_rows must be a non-negative integer';
        }
        if (isset($config['max_rows']) && (!is_int($config['max_rows']) || $config['max_rows'] < 1)) {
            $errors['max_rows'] = 'max_rows must be a positive integer';
        }
        if (isset($config['min_rows'], $config['max_rows']) && $config['min_rows'] > $config['max_rows']) {
            $errors['min_rows'] = 'min_rows cannot be greater than max_rows';
        }
        return $errors;
    }

    public function jsonSchema(array $config): array
    {
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
            ],
        ];
        if (isset($config['min_rows'])) {
            $schema['minItems'] = $config['min_rows'];
        }
        if (isset($config['max_rows'])) {
            $schema['maxItems'] = $config['max_rows'];
        }
        return $schema;
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        return is_array($value) ? array_values($value) : [];
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === []) {
            if (isset($config['min_rows']) && $config['min_rows'] > 0) {
                return [sprintf('Must have at least %d items', $config['min_rows'])];
            }
            return [];
        }
        if (!is_array($value)) {
            return ['Must be an array of items'];
        }
        $count = count($value);
        $errors = [];
        if (isset($config['min_rows']) && $count < $config['min_rows']) {
            $errors[] = sprintf('Must have at least %d items', $config['min_rows']);
        }
        if (isset($config['max_rows']) && $count > $config['max_rows']) {
            $errors[] = sprintf('Must not exceed %d items', $config['max_rows']);
        }
        return $errors;
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::json();
    }

    public function filterOperators(): array
    {
        return ['null'];
    }

    public function isSortable(): bool
    {
        return false;
    }
}
