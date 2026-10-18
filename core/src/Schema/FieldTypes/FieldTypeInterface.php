<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

interface FieldTypeInterface
{
    /**
     * Unique identifier for this field type, e.g. 'text', 'number', 'richtext'.
     */
    public function name(): string;

    /**
     * Human readable label.
     */
    public function label(): string;

    /**
     * Validate the field's configuration schema when creating or altering the field definition.
     * Returns an array of error messages keyed by config property, or empty array if valid.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public function validateConfig(array $config): array;

    /**
     * Generate JSON Schema for the field's value input.
     * Used for API input validation, OpenAPI specifications, and MCP tool schemas.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function jsonSchema(array $config): array;

    /**
     * Normalize the input value before validation/storage (trim strings, cast types, etc.).
     *
     * @param array<string, mixed> $config
     */
    public function normalize(mixed $value, array $config): mixed;

    /**
     * Validate a field value against its configuration and context.
     * Returns list of validation error messages, or empty array if valid.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public function validate(mixed $value, array $config, ValidationContext $context): array;

    /**
     * Storage descriptor: column definition or JSON virtual/extracted storage.
     *
     * @param array<string, mixed> $config
     */
    public function storage(array $config): StorageDescriptor;

    /**
     * Transform internal/stored value to external API presentation.
     *
     * @param array<string, mixed> $config
     */
    public function toApi(mixed $value, array $config, PopulateContext $context): mixed;

    /**
     * Supported query filter operators: eq, ne, in, nin, lt, lte, gt, gte, between, contains, starts_with, null.
     *
     * @return list<string>
     */
    public function filterOperators(): array;

    /**
     * Whether this field type can be sorted on.
     */
    public function isSortable(): bool;
}
