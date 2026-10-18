<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class EnumType extends BaseFieldType
{
    public function name(): string
    {
        return 'enum';
    }

    public function label(): string
    {
        return 'Dropdown / Radio (Single Enum)';
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (!isset($config['options']) || !is_array($config['options']) || empty($config['options'])) {
            $errors['options'] = 'options must be a non-empty list of option values or objects';
        } else {
            foreach ($config['options'] as $idx => $opt) {
                if (is_array($opt)) {
                    if (!isset($opt['value']) || !is_scalar($opt['value'])) {
                        $errors["options.{$idx}.value"] = 'Each option must have a scalar value';
                    }
                } elseif (!is_scalar($opt)) {
                    $errors["options.{$idx}"] = 'Option must be a scalar or object with value';
                }
            }
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
            'type' => 'string',
            'enum' => $this->allowedValues($config),
        ];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_scalar($value) ? (string) $value : null;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $str = (string) $value;
        $allowed = $this->allowedValues($config);
        if (!in_array($str, $allowed, true)) {
            return [sprintf('Value must be one of: %s', implode(', ', $allowed))];
        }
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('VARCHAR(64)');
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'in', 'nin', 'null'];
    }

    public function isSortable(): bool
    {
        return true;
    }
}
