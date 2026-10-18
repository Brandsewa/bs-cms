<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class SeoType extends BaseFieldType
{
    public function name(): string
    {
        return 'seo';
    }

    public function label(): string
    {
        return 'SEO Metadata';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => ['string', 'null']],
                'description' => ['type' => ['string', 'null']],
                'canonical' => ['type' => ['string', 'null']],
                'noindex' => ['type' => 'boolean'],
                'nofollow' => ['type' => 'boolean'],
                'og_title' => ['type' => ['string', 'null']],
                'og_description' => ['type' => ['string', 'null']],
                'og_image_media_id' => ['type' => ['string', 'null']],
                'twitter_card' => ['type' => 'string', 'enum' => ['summary', 'summary_large_image']],
                'max_snippet' => ['type' => ['integer', 'null']],
                'nosnippet' => ['type' => 'boolean'],
                'max_image_preview' => ['type' => 'string', 'enum' => ['none', 'standard', 'large']],
                'schema_overrides' => ['type' => 'object'],
                'focus_topic' => ['type' => ['string', 'null']],
                'answer_summary' => ['type' => ['string', 'null']],
                'breadcrumb_title' => ['type' => ['string', 'null']],
            ],
        ];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (!is_array($value)) {
            return null;
        }

        return [
            'title' => isset($value['title']) && is_scalar($value['title']) ? trim((string) $value['title']) : null,
            'description' => isset($value['description']) && is_scalar($value['description']) ? trim((string) $value['description']) : null,
            'canonical' => isset($value['canonical']) && is_scalar($value['canonical']) ? trim((string) $value['canonical']) : null,
            'noindex' => (bool) ($value['noindex'] ?? false),
            'nofollow' => (bool) ($value['nofollow'] ?? false),
            'og_title' => isset($value['og_title']) && is_scalar($value['og_title']) ? trim((string) $value['og_title']) : null,
            'og_description' => isset($value['og_description']) && is_scalar($value['og_description']) ? trim((string) $value['og_description']) : null,
            'og_image_media_id' => isset($value['og_image_media_id']) && is_scalar($value['og_image_media_id']) ? trim((string) $value['og_image_media_id']) : null,
            'twitter_card' => in_array($value['twitter_card'] ?? '', ['summary', 'summary_large_image'], true) ? $value['twitter_card'] : 'summary_large_image',
            'max_snippet' => isset($value['max_snippet']) && is_numeric($value['max_snippet']) ? (int) $value['max_snippet'] : null,
            'nosnippet' => (bool) ($value['nosnippet'] ?? false),
            'max_image_preview' => in_array($value['max_image_preview'] ?? '', ['none', 'standard', 'large'], true) ? $value['max_image_preview'] : 'large',
            'schema_overrides' => isset($value['schema_overrides']) && is_array($value['schema_overrides']) ? $value['schema_overrides'] : [],
            'focus_topic' => isset($value['focus_topic']) && is_scalar($value['focus_topic']) ? trim((string) $value['focus_topic']) : null,
            'answer_summary' => isset($value['answer_summary']) && is_scalar($value['answer_summary']) ? trim((string) $value['answer_summary']) : null,
            'breadcrumb_title' => isset($value['breadcrumb_title']) && is_scalar($value['breadcrumb_title']) ? trim((string) $value['breadcrumb_title']) : null,
        ];
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            return ['Must be an object with SEO properties'];
        }
        $errors = [];
        if (!empty($value['canonical']) && filter_var($value['canonical'], FILTER_VALIDATE_URL) === false) {
            $errors[] = 'canonical must be a valid URL';
        }
        return $errors;
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('JSON');
    }

    public function filterOperators(): array
    {
        return ['null'];
    }

    public function isSortable(): bool
    {
        return false;
    }
}
