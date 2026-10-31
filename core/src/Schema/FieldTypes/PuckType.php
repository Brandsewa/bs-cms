<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

/**
 * Puck visual-editor field type (PuckData).
 *
 * Value shape:
 *   {
 *     "content": [ { "type": "<ComponentName>", "props": { "id": "...", ... } } ],
 *     "root":    { "props": {} },          // optional
 *     "zones":   { "<zone>": [ ...items ] } // optional
 *   }
 *
 * Component type names are code-side concerns (registered in the editor bundle),
 * so PHP only validates structure — unknown component types are accepted.
 */
final class PuckType extends BaseFieldType
{
    private const MAX_TOTAL_SIZE_BYTES = 524288; // 512 KB total PuckData payload

    public function name(): string
    {
        return 'puck';
    }

    public function label(): string
    {
        return 'Page Content';
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
            return ['Puck payload exceeds maximum size limit of 512 KB.'];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                return ['Puck data must be a valid JSON object.'];
            }
            $value = $decoded;
        }

        if (!is_array($value)) {
            return ['Puck data must be an object with a "content" array.'];
        }

        $errors = [];

        $content = $value['content'] ?? null;
        if (!is_array($content)) {
            $errors[] = 'Puck data must contain a "content" array.';
        } else {
            foreach (array_values($content) as $index => $item) {
                $itemErrors = $this->validateItem($item, $index);
                if ($itemErrors !== []) {
                    $errors = array_merge($errors, $itemErrors);
                }
            }
        }

        if (array_key_exists('root', $value) && $value['root'] !== null) {
            $root = $value['root'];
            if (!is_array($root)) {
                $errors[] = 'Puck data "root" must be an object.';
            } elseif (isset($root['props']) && !is_array($root['props'])) {
                $errors[] = 'Puck data "root.props" must be an object.';
            }
        }

        if (array_key_exists('zones', $value) && $value['zones'] !== null) {
            $zones = $value['zones'];
            if (!is_array($zones)) {
                $errors[] = 'Puck data "zones" must be an object of zone arrays.';
            } else {
                foreach ($zones as $zoneName => $items) {
                    if (!is_array($items)) {
                        $errors[] = "Puck zone \"{$zoneName}\" must be an array of components.";
                        continue;
                    }
                    foreach (array_values($items) as $index => $item) {
                        $itemErrors = $this->validateItem($item, $index, "zones.{$zoneName}");
                        if ($itemErrors !== []) {
                            $errors = array_merge($errors, $itemErrors);
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateItem(mixed $item, int $index, string $where = 'content'): array
    {
        if (!is_array($item)) {
            return ["Puck component at {$where}[{$index}] must be an object/array."];
        }

        $type = $item['type'] ?? null;
        if (!is_string($type) || trim($type) === '') {
            return ["Puck component at {$where}[{$index}] is missing a required non-empty 'type' string."];
        }

        if (isset($item['props']) && !is_array($item['props'])) {
            return ["Puck component '{$type}' at {$where}[{$index}] 'props' must be an object/array."];
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
        $componentSchema = [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string'],
                'props' => ['type' => 'object'],
            ],
            'required' => ['type'],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'array',
                    'items' => $componentSchema,
                ],
                'root' => [
                    'type' => 'object',
                    'properties' => [
                        'props' => ['type' => 'object'],
                    ],
                ],
                'zones' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'array',
                        'items' => $componentSchema,
                    ],
                ],
            ],
            'required' => ['content'],
        ];
    }
}
