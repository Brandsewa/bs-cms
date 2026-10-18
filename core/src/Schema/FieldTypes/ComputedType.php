<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class ComputedType extends BaseFieldType
{
    public function name(): string
    {
        return 'computed';
    }

    public function label(): string
    {
        return 'Computed (Derived / Read-only)';
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (!isset($config['compute']) || !is_string($config['compute'])) {
            $errors['compute'] = 'compute expression or identifier is required';
        }
        return $errors;
    }

    public function jsonSchema(array $config): array
    {
        $returnType = $config['return_type'] ?? 'string';
        $type = match ($returnType) {
            'number', 'int', 'integer' => 'integer',
            'float', 'decimal' => 'number',
            'boolean', 'bool' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            default => 'string',
        };
        return ['type' => $type, 'readOnly' => true];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        return null; // Computed fields are derived, not written by client
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        if (isset($config['virtual_ddl']) && is_string($config['virtual_ddl'])) {
            return new StorageDescriptor(
                StorageDescriptor::MODE_COLUMN,
                $config['column_type'] ?? 'INT',
                $config['virtual_ddl']
            );
        }
        return StorageDescriptor::none();
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
