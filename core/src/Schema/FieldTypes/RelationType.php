<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class RelationType extends BaseFieldType
{
    public function name(): string
    {
        return 'relation';
    }

    public function label(): string
    {
        return 'Relation (Linked Entries)';
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (!isset($config['target_collection']) || !is_string($config['target_collection']) || trim($config['target_collection']) === '') {
            $errors['target_collection'] = 'target_collection is required and must be a non-empty string';
        }
        $cardinality = $config['cardinality'] ?? 'one_to_many';
        $allowedCardinalities = ['one_to_one', 'one_to_many', 'many_to_one', 'many_to_many'];
        if (!in_array($cardinality, $allowedCardinalities, true)) {
            $errors['cardinality'] = sprintf('cardinality must be one of: %s', implode(', ', $allowedCardinalities));
        }
        return $errors;
    }

    public function jsonSchema(array $config): array
    {
        $cardinality = $config['cardinality'] ?? 'one_to_many';
        $isSingle = in_array($cardinality, ['one_to_one', 'many_to_one'], true);

        if ($isSingle) {
            return [
                'type' => 'string',
                'pattern' => '^[0-7][0-9A-HJKMNP-TV-Z]{25}$',
            ];
        }

        return [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'pattern' => '^[0-7][0-9A-HJKMNP-TV-Z]{25}$',
            ],
        ];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            $cardinality = $config['cardinality'] ?? 'one_to_many';
            $isSingle = in_array($cardinality, ['one_to_one', 'many_to_one'], true);
            return $isSingle ? null : [];
        }

        $cardinality = $config['cardinality'] ?? 'one_to_many';
        $isSingle = in_array($cardinality, ['one_to_one', 'many_to_one'], true);

        if ($isSingle) {
            if (is_array($value)) {
                $first = reset($value);
                return is_scalar($first) ? trim((string) $first) : null;
            }
            return is_scalar($value) ? trim((string) $value) : null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = array_map('trim', explode(',', $value));
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $normalized[] = trim((string) $item);
            }
        }
        return array_values(array_unique($normalized));
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === [] || $value === '') {
            return [];
        }

        $cardinality = $config['cardinality'] ?? 'one_to_many';
        $isSingle = in_array($cardinality, ['one_to_one', 'many_to_one'], true);

        if ($isSingle) {
            if (!is_string($value) || !preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value)) {
                return ['Must be a valid target entry ULID'];
            }
            return [];
        }

        if (!is_array($value)) {
            return ['Must be an array of entry ULIDs'];
        }

        foreach ($value as $id) {
            if (!is_string($id) || !preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $id)) {
                return [sprintf('Invalid entry ULID: %s', (string) $id)];
            }
        }

        return [];
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::relation();
    }

    public function toApi(mixed $value, array $config, PopulateContext $context): mixed
    {
        $handle = $context->field['handle'] ?? '';
        if ($context->isRequested($handle) && isset($context->populatedData[$handle])) {
            return $context->populatedData[$handle];
        }

        return $value;
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'in', 'nin', 'null'];
    }

    public function isSortable(): bool
    {
        return false;
    }
}
