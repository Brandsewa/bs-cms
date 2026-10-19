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
        return [];
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                return ['Blocks data must be a valid JSON array or object array.'];
            }
            $value = $decoded;
        }

        if (!is_array($value)) {
            return ['Blocks data must be an array of blocks.'];
        }

        return [];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string'],
                    'data' => ['type' => 'object'],
                ],
                'required' => ['type'],
            ],
        ];
    }
}
