<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class MultiEnumType extends BaseFieldType
{
    public function name(): string
    {
        return 'multi_enum';
    }

    public function label(): string
    {
        return 'Multi-Select (Multi Enum)';
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (!isset($config['options']) || !is_array($config['options']) || empty($config['options'])) {
            $errors['options'] = 'options must be a non-empty list of option values or objects';
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function allowedValues(array $config): array
    {
        $values = [];
        foreach ($config['options'] ?? [] as $opt) {
            if (is_array($opt) && isset($opt['value'])) {
                $values[] = (string) $opt['value'];
            } elseif (is_scalar($opt)) {
                $values[] = (string) $opt;
            }
        }
        return $values;
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'enum' => $this->allowedValues($config),
            ],
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
            } else {
                $value = array_map('trim', explode(',', $value));
            }
        }
        if (!is_array($value)) {
            return [];
        }
        $normalized = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $normalized[] = (string) $item;
            }
        }
        return array_values(array_unique($normalized));
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || empty($value)) {
            return [];
        }
        if (!is_array($value)) {
            return ['Must be an array of selected options'];
        }
        $allowed = $this->allowedValues($config);
        $errors = [];
        foreach ($value as $v) {
            if (!in_array((string) $v, $allowed, true)) {
                $errors[] = sprintf('Invalid option: %s', (string) $v);
            }
        }
        return $errors;
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::json();
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
