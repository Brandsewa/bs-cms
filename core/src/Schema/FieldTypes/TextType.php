<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class TextType extends BaseFieldType
{
    public function name(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'Text (Single Line)';
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (isset($config['min_length']) && (!is_int($config['min_length']) || $config['min_length'] < 0)) {
            $errors['min_length'] = 'min_length must be a non-negative integer';
        }
        if (isset($config['max_length']) && (!is_int($config['max_length']) || $config['max_length'] < 1 || $config['max_length'] > 65535)) {
            $errors['max_length'] = 'max_length must be an integer between 1 and 65535';
        }
        if (isset($config['min_length'], $config['max_length']) && $config['min_length'] > $config['max_length']) {
            $errors['min_length'] = 'min_length cannot be greater than max_length';
        }
        if (isset($config['pattern']) && is_string($config['pattern'])) {
            if (@preg_match('/' . $config['pattern'] . '/', '') === false) {
                $errors['pattern'] = 'pattern must be a valid regular expression';
            }
        }
        return $errors;
    }

    public function jsonSchema(array $config): array
    {
        $schema = ['type' => 'string'];
        if (isset($config['min_length'])) {
            $schema['minLength'] = $config['min_length'];
        }
        if (isset($config['max_length'])) {
            $schema['maxLength'] = $config['max_length'];
        }
        if (isset($config['pattern'])) {
            $schema['pattern'] = $config['pattern'];
        }
        return $schema;
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null) {
            return null;
        }
        $val = is_scalar($value) ? (string) $value : '';
        if (($config['trim'] ?? true) === true) {
            $val = trim($val);
        }
        return $val;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            return ['Must be a string'];
        }
        $errors = [];
        $len = mb_strlen($value);
        if (isset($config['min_length']) && $len < $config['min_length']) {
            $errors[] = sprintf('Must be at least %d characters', $config['min_length']);
        }
        if (isset($config['max_length']) && $len > $config['max_length']) {
            $errors[] = sprintf('Must not exceed %d characters', $config['max_length']);
        }
        if (isset($config['pattern']) && is_string($config['pattern'])) {
            if (!preg_match('/' . $config['pattern'] . '/', $value)) {
                $errors[] = $config['pattern_error'] ?? 'Value does not match required format';
            }
        }
        return $errors;
    }

    public function storage(array $config): StorageDescriptor
    {
        $max = isset($config['max_length']) && is_int($config['max_length']) ? $config['max_length'] : 255;
        if ($max > 255) {
            return StorageDescriptor::column('TEXT');
        }
        return StorageDescriptor::column(sprintf('VARCHAR(%d)', $max));
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
