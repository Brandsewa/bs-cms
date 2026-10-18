<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class FaqType extends BaseFieldType
{
    public function name(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        return 'FAQ (Questions & Answers)';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'required' => ['question', 'answer'],
                'properties' => [
                    'question' => ['type' => 'string'],
                    'answer' => ['type' => 'string'],
                ],
            ],
        ];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (!is_array($value)) {
            return [];
        }
        $normalized = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $normalized[] = [
                    'question' => trim((string) ($item['question'] ?? $item['q'] ?? '')),
                    'answer' => trim((string) ($item['answer'] ?? $item['a'] ?? '')),
                ];
            }
        }
        return $normalized;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            return ['Must be an array of FAQ items'];
        }
        $errors = [];
        foreach ($value as $idx => $item) {
            if (!is_array($item)) {
                $errors[] = sprintf('FAQ item #%d must be an object', $idx + 1);
                continue;
            }
            if (empty($item['question'])) {
                $errors[] = sprintf('FAQ item #%d requires a question', $idx + 1);
            }
            if (empty($item['answer'])) {
                $errors[] = sprintf('FAQ item #%d requires an answer', $idx + 1);
            }
        }
        return $errors;
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::json();
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
