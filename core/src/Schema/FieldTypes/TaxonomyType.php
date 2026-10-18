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
        $errors = [];
        $tax = $config['taxonomy'] ?? $config['taxonomy_id'] ?? null;
        if (!is_string($tax) || trim($tax) === '') {
            $errors['taxonomy'] = "The 'taxonomy' handle or ID is required in taxonomy field configuration.";
        }
        return $errors;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null) {
            return [];
        }

        $multiple = !empty($config['multiple']);
        if ($multiple) {
            if (!is_array($value)) {
                return ["Field '{$context->field['handle']}' must be an array of term IDs or slugs."];
            }
            foreach ($value as $idx => $item) {
                if (!is_string($item) && !is_int($item)) {
                    return ["Term at index {$idx} must be a string identifier or slug."];
                }
            }
        } else {
            if (is_array($value)) {
                foreach ($value as $idx => $item) {
                    if (!is_string($item) && !is_int($item)) {
                        return ["Term at index {$idx} must be a string identifier or slug."];
                    }
                }
            } elseif (!is_string($value) && !is_int($value)) {
                return ["Field '{$context->field['handle']}' must be a term ID, slug, or array of terms."];
            }
        }

        return [];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }

        return (string) $value;
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => ['string', 'array', 'null'],
            'items' => ['type' => 'string'],
        ];
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'in', 'nin', 'null', 'all'];
    }
}
