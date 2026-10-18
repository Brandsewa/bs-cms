<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class ColorType extends BaseFieldType
{
    public function name(): string
    {
        return 'color';
    }

    public function label(): string
    {
        return 'Color (Hex/RGB)';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'string',
            'pattern' => '^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$',
            'maxLength' => 16,
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
        if (!is_string($value) || !preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $value)) {
            return ['Must be a valid hex color code (e.g. #fff, #ffffff)'];
        }
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('VARCHAR(16)');
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'null'];
    }

    public function isSortable(): bool
    {
        return false;
    }
}
