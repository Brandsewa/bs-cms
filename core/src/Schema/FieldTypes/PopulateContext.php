<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class PopulateContext
{
    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $populatedData Keyed by field handle or relation target
     * @param list<string> $populateFields List of relations requested to be populated
     */
    public function __construct(
        public readonly array $field,
        public readonly array $populatedData = [],
        public readonly array $populateFields = [],
        public readonly int $depth = 0
    ) {
    }

    public function isRequested(string $handle): bool
    {
        return in_array($handle, $this->populateFields, true);
    }
}
