<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class SlugType extends BaseFieldType
{
    public function name(): string
    {
        return 'slug';
    }

    public function label(): string
    {
        return 'URL Slug';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'string',
            'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
            'maxLength' => 191,
        ];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $str = is_scalar($value) ? (string) $value : '';
        $str = strtolower(trim($str));
        $str = (string) preg_replace('/[^a-z0-9-]+/', '-', $str);
        $str = (string) preg_replace('/-+/', '-', $str);
        return trim($str, '-');
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            return ['Must be a string'];
        }
        if (strlen($value) > 191) {
            return ['Slug must not exceed 191 characters'];
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            return ['Slug must contain only lowercase alphanumeric characters and single hyphens'];
        }
        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('VARCHAR(191)');
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'in', 'nin', 'starts_with', 'null'];
    }

    public function isSortable(): bool
    {
        return true;
    }
}
