<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class PhoneType extends BaseFieldType
{
    public function name(): string
    {
        return 'phone';
    }

    public function label(): string
    {
        return 'Phone Number';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'string',
            'maxLength' => 32,
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
        if (!is_string($value)) {
            return ['Must be a string'];
        }
        if (!preg_match('/^\+?[0-9\s().\/-]{7,32}$/', $value) || preg_match_all('/[0-9]/', $value) < 5) {
            return ['Must be a valid phone number'];
        }
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('VARCHAR(32)');
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'contains', 'null'];
    }

    public function isSortable(): bool
    {
        return false;
    }
}
