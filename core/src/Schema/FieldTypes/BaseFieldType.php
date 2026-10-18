<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

abstract class BaseFieldType implements FieldTypeInterface
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public function validateConfig(array $config): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function normalize(mixed $value, array $config): mixed
    {
        return $value;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::json();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function toApi(mixed $value, array $config, PopulateContext $context): mixed
    {
        return $value;
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
