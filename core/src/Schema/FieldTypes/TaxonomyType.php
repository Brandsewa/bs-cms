<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class TaxonomyType extends BaseFieldType
{
    public function name(): string
    {
        return 'taxonomy';
    }

    public function label(): string
    {
        return 'Taxonomy Term(s)';
    }

    public function validateConfig(array $config): array
    {
        return [
            'type' => "The 'taxonomy' field type is available in Phase 4.",
        ];
    }

    public function jsonSchema(array $config): array
    {
        return ['type' => ['string', 'array']];
    }
}
