<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class BlocksType extends BaseFieldType
{
    public function name(): string
    {
        return 'blocks';
    }

    public function label(): string
    {
        return 'Blocks Editor';
    }

    public function validateConfig(array $config): array
    {
        return [
            'type' => "The 'blocks' field type is available in Phase 4.",
        ];
    }

    public function jsonSchema(array $config): array
    {
        return ['type' => 'array'];
    }
}
