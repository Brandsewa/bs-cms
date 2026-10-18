<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class MediaType extends BaseFieldType
{
    private const ULID_REGEX = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i';

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
        $errors = [];
        if (isset($config['allowed_types']) && !is_array($config['allowed_types'])) {
            $errors['allowed_types'] = "The 'allowed_types' setting must be an array of media types (e.g. image, video, audio, document).";
        }

        if (isset($config['multiple']) && !is_bool($config['multiple'])) {
            $errors['multiple'] = "The 'multiple' setting must be a boolean.";
        }

        return $errors;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $multiple = !empty($config['multiple']);
        $handle = $context->field['handle'] ?? 'media';

        if ($multiple) {
            if (!is_array($value)) {
                return ["Field '{$handle}' must be an array of media IDs (ULIDs)."];
            }

            foreach ($value as $idx => $item) {
                if (!is_string($item) || !preg_match(self::ULID_REGEX, trim($item))) {
                    return ["Media ID at index {$idx} in field '{$handle}' is not a valid 26-character ULID."];
                }
            }
        } else {
            if (!is_string($value) || !preg_match(self::ULID_REGEX, trim($value))) {
                return ["Field '{$handle}' must be a valid 26-character media ULID."];
            }
        }

        return [];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $multiple = !empty($config['multiple']);
        if ($multiple) {
            $items = is_array($value) ? $value : [$value];
            return array_values(array_map('strtoupper', array_map('trim', array_map('strval', $items))));
        }

        return strtoupper(trim((string) $value));
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::json();
    }

    public function jsonSchema(array $config): array
    {
        if (!empty($config['multiple'])) {
            return [
                'type' => ['array', 'null'],
                'items' => ['type' => 'string'],
            ];
        }

        return [
            'type' => ['string', 'null'],
        ];
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'in', 'nin', 'null'];
    }
}
