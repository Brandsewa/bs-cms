<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class MediaType extends BaseFieldType
{
    public function name(): string
    {
        return 'media';
    }

    public function label(): string
    {
        return 'Media Asset';
    }

    public function validateConfig(array $config): array
    {
        return [
            'type' => "The 'media' field type is available in Phase 4.",
        ];
    }

    public function jsonSchema(array $config): array
    {
        return ['type' => ['string', 'array']];
    }
}
