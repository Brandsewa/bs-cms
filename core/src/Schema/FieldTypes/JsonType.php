<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class JsonType extends BaseFieldType
{
    public function name(): string
    {
        return 'json';
    }

    public function label(): string
    {
        return 'JSON Object / Array';
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (isset($config['schema']) && !is_array($config['schema'])) {
            $errors['schema'] = 'schema must be a valid JSON Schema object';
        }
        return $errors;
    }

    public function jsonSchema(array $config): array
    {
        if (isset($config['schema']) && is_array($config['schema'])) {
            return $config['schema'];
        }
        return [
            'type' => ['object', 'array'],
        ];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $value;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            return ['Must be a valid JSON object or array'];
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
