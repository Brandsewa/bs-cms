<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class MarkdownType extends BaseFieldType
{
    public function name(): string
    {
        return 'markdown';
    }

    public function label(): string
    {
        return 'Markdown Content';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return ['type' => 'string'];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null) {
            return null;
        }
        return is_scalar($value) ? (string) $value : '';
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            return ['Must be a string'];
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
