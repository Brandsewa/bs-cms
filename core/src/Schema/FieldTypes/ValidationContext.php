<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class ValidationContext
{
    /**
     * @param array<string, mixed> $entry All field values of the entry being validated
     * @param array<string, mixed> $field The field definition (handle, config, required, etc.)
     * @param array<string, mixed> $collection The collection definition (handle, kind, etc.)
     * @param string|null $entryId The entry ULID if updating, null if creating
     */
    public function __construct(
        public readonly array $entry,
        public readonly array $field,
        public readonly array $collection,
        public readonly ?string $entryId = null,
        public readonly bool $isUpdate = false
    ) {
    }
}
