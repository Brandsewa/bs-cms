<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class RichtextType extends BaseFieldType
{
    public function name(): string
    {
        return 'richtext';
    }

    public function label(): string
    {
        return 'Rich Text (Tiptap)';
    }

    public function validateConfig(array $config): array
    {
        return [
            'type' => "The 'richtext' field type is available in Phase 4.",
        ];
    }

    public function jsonSchema(array $config): array
    {
        return ['type' => 'object'];
    }
}
