<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

use BsCms\Blocks\BlockRegistry;

final class BlocksType extends BaseFieldType
{
    private const MAX_BLOCK_SIZE_BYTES = 65536; // 64 KB per block
    private const MAX_TOTAL_SIZE_BYTES = 524288; // 512 KB total blocks payload

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

        $rawEncoded = is_string($value) ? $value : json_encode($value);
        if ($rawEncoded !== false && strlen($rawEncoded) > self::MAX_TOTAL_SIZE_BYTES) {
            return ['Blocks payload exceeds maximum size limit of 512 KB.'];
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

        // Each element in blocks must be an array with 'type' and 'data'
        $registry = BlockRegistry::getInstance();
        $errors = [];

        foreach ($value as $index => $block) {
            if (!is_array($block)) {
                $errors[] = "Block at index {$index} must be an object/array.";
                continue;
            }

            $blockJson = json_encode($block);
            if ($blockJson !== false && strlen($blockJson) > self::MAX_BLOCK_SIZE_BYTES) {
                $errors[] = "Block at index {$index} exceeds maximum size limit of 64 KB.";
                continue;
            }

            $type = $block['type'] ?? null;
            if (!is_string($type) || trim($type) === '') {
                $errors[] = "Block at index {$index} is missing a required 'type' string.";
                continue;
            }

            if (isset($block['data']) && !is_array($block['data'])) {
                $errors[] = "Block at index {$index} ('{$type}') 'data' must be an object/array.";
            }

            if ($registry !== null) {
                if (!$registry->has($type)) {
                    $errors[] = "Block type '{$type}' at index {$index} is not registered in BlockRegistry.";
                }
            } else {
                // If registry instance not initialized, check baseline known blocks or resources/blocks directly
                $baselineBlocks = ['hero', 'callout', 'faq', 'text', 'features'];
                $blockResourcePath = dirname(__DIR__, 3) . '/resources/blocks/' . $type . '.json';
                if (!in_array($type, $baselineBlocks, true) && !file_exists($blockResourcePath)) {
                    $errors[] = "Block type '{$type}' at index {$index} is not recognized.";
                }
            }
        }

        return $errors;
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
