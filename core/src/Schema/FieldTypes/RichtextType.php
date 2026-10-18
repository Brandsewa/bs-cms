<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

use BsCms\Document\DocumentConverter;
use BsCms\Document\DocumentSchema;
use BsCms\Document\DocumentValidator;

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
        $errors = [];
        $profile = $config['profile'] ?? DocumentSchema::PROFILE_ARTICLE;
        if (!in_array($profile, [DocumentSchema::PROFILE_ARTICLE, DocumentSchema::PROFILE_MINIMAL], true)) {
            $errors['profile'] = "The 'profile' must be either 'article' or 'minimal'.";
        }

        if (isset($config['allowed_nodes']) && !is_array($config['allowed_nodes'])) {
            $errors['allowed_nodes'] = "The 'allowed_nodes' setting must be an array of node names.";
        }

        return $errors;
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $profile = (string) ($config['profile'] ?? DocumentSchema::PROFILE_ARTICLE);
        /** @var list<string>|null $allowedNodes */
        $allowedNodes = isset($config['allowed_nodes']) && is_array($config['allowed_nodes'])
            ? array_values(array_filter(array_map('strval', $config['allowed_nodes'])))
            : null;

        $docValidator = new DocumentValidator();
        $docConverter = new DocumentConverter();

        if (is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded) && ($decoded['type'] ?? '') === 'doc') {
                    return $docValidator->validate($decoded, $profile, $allowedNodes);
                }
            }

            // Treat as Markdown
            $doc = $docConverter->markdownToDocument($value, $profile);
            return $docValidator->validate($doc, $profile, $allowedNodes);
        }

        if (is_array($value)) {
            return $docValidator->validate($value, $profile, $allowedNodes);
        }

        return ["Field '{$context->field['handle']}' must be a document object or markdown string."];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $profile = (string) ($config['profile'] ?? DocumentSchema::PROFILE_ARTICLE);
        $docConverter = new DocumentConverter();

        if (is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded) && ($decoded['type'] ?? '') === 'doc') {
                    $decoded['schema_version'] = DocumentSchema::SCHEMA_VERSION;
                    return $decoded;
                }
            }

            $doc = $docConverter->markdownToDocument($value, $profile);
            $doc['schema_version'] = DocumentSchema::SCHEMA_VERSION;
            return $doc;
        }

        if (is_array($value)) {
            $value['schema_version'] = DocumentSchema::SCHEMA_VERSION;
            return $value;
        }

        return null;
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::json();
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => ['object', 'string', 'null'],
        ];
    }
}
