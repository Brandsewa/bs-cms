<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class TextareaType extends BaseFieldType
{
    public function name(): string
    {
        return 'textarea';
    }

    public function label(): string
    {
        return 'Textarea (Multi-line)';
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (isset($config['max_length']) && (!is_int($config['max_length']) || $config['max_length'] < 1)) {
            $errors['max_length'] = 'max_length must be a positive integer';
        }
        return $errors;
    }

    public function jsonSchema(array $config): array
    {
        $schema = ['type' => 'string'];
        if (isset($config['max_length'])) {
            $schema['maxLength'] = $config['max_length'];
        }
        return $schema;
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null) {
            return null;
        }
        $val = is_scalar($value) ? (string) $value : '';
        return ($config['trim'] ?? true) === true ? trim($val) : $val;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            return ['Must be a string'];
        }
        if (isset($config['max_length']) && mb_strlen($value) > $config['max_length']) {
            return [sprintf('Must not exceed %d characters', $config['max_length'])];
        }
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('TEXT');
    }

    public function filterOperators(): array
    {
        return ['contains', 'null'];
    }

    public function isSortable(): bool
    {
        return false;
    }
}
