<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class GroupType extends BaseFieldType
{
    public function name(): string
    {
        return 'group';
    }

    public function label(): string
    {
        return 'Group (Nested Section)';
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (!isset($config['fields']) || !is_array($config['fields']) || empty($config['fields'])) {
            $errors['fields'] = 'fields must be a non-empty array of nested field definitions';
        }
        return $errors;
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'object',
        ];
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
        return is_array($value) ? $value : [];
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            return ['Must be a key-value object'];
        }
        return [];
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
