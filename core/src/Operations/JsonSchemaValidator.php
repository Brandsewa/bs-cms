<?php

declare(strict_types=1);

namespace BsCms\Operations;

/**
 * Lightweight, zero-dependency JSON Schema validator.
 * Validates types, required fields, properties, items, enums, limits, and patterns.
 */
final class JsonSchemaValidator
{
    /**
     * Validate data against a JSON Schema.
     *
     * @param mixed $data
     * @param array<string, mixed> $schema
     * @param string $path
     * @return array<string, string> Array of path => error message
     */
    public function validate(mixed $data, array $schema, string $path = ''): array
    {
        $errors = [];

        if (empty($schema)) {
            return $errors;
        }

        // 1. Type check
        if (isset($schema['type'])) {
            $expectedType = $schema['type'];
            if (!$this->checkType($data, $expectedType)) {
                $p = $path !== '' ? $path : '$';
                $errors[$p] = "Expected type '{$expectedType}', got '" . gettype($data) . "'.";
                return $errors;
            }
        }

        // 2. Enum check
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if (!in_array($data, $schema['enum'], true)) {
                $p = $path !== '' ? $path : '$';
                $errors[$p] = "Value must be one of: " . implode(', ', array_map('strval', $schema['enum']));
                return $errors;
            }
        }

        // 3. String constraints
        if (is_string($data)) {
            $p = $path !== '' ? $path : '$';
            if (isset($schema['minLength']) && mb_strlen($data) < (int) $schema['minLength']) {
                $errors[$p] = "String length must be at least {$schema['minLength']}.";
            }
            if (isset($schema['maxLength']) && mb_strlen($data) > (int) $schema['maxLength']) {
                $errors[$p] = "String length cannot exceed {$schema['maxLength']}.";
            }
            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                if (@preg_match($schema['pattern'], $data) !== 1) {
                    $errors[$p] = "Value does not match pattern {$schema['pattern']}.";
                }
            }
        }

        // 4. Number constraints
        if (is_int($data) || is_float($data)) {
            $p = $path !== '' ? $path : '$';
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[$p] = "Value must be greater than or equal to {$schema['minimum']}.";
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[$p] = "Value must be less than or equal to {$schema['maximum']}.";
            }
        }

        // 5. Array constraints
        if (is_array($data) && array_is_list($data)) {
            $p = $path !== '' ? $path : '$';
            if (isset($schema['minItems']) && count($data) < (int) $schema['minItems']) {
                $errors[$p] = "Array must contain at least {$schema['minItems']} items.";
            }
            if (isset($schema['maxItems']) && count($data) > (int) $schema['maxItems']) {
                $errors[$p] = "Array cannot contain more than {$schema['maxItems']} items.";
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($data as $idx => $item) {
                    $itemPath = $path !== '' ? "{$path}[{$idx}]" : "[{$idx}]";
                    $itemErrors = $this->validate($item, $schema['items'], $itemPath);
                    $errors = array_merge($errors, $itemErrors);
                }
            }
        }

        // 6. Object constraints
        if (is_array($data) && (!array_is_list($data) || empty($data))) {
            $p = $path !== '' ? $path : '$';
            // Required properties
            if (isset($schema['required']) && is_array($schema['required'])) {
                foreach ($schema['required'] as $reqKey) {
                    if (!is_string($reqKey)) {
                        continue;
                    }
                    if (!array_key_exists($reqKey, $data)) {
                        $propPath = $path !== '' ? "{$path}.{$reqKey}" : $reqKey;
                        $errors[$propPath] = "Field is required.";
                    }
                }
            }

            // Properties
            if (isset($schema['properties']) && is_array($schema['properties'])) {
                foreach ($schema['properties'] as $propKey => $propSchema) {
                    if (!is_string($propKey) || !is_array($propSchema)) {
                        continue;
                    }
                    if (array_key_exists($propKey, $data)) {
                        $propPath = $path !== '' ? "{$path}.{$propKey}" : $propKey;
                        $propErrors = $this->validate($data[$propKey], $propSchema, $propPath);
                        $errors = array_merge($errors, $propErrors);
                    }
                }
            }

            // Additional properties check
            if (isset($schema['additionalProperties']) && $schema['additionalProperties'] === false) {
                $allowed = array_keys($schema['properties'] ?? []);
                foreach (array_keys($data) as $actualKey) {
                    if (!in_array((string) $actualKey, $allowed, true)) {
                        $propPath = $path !== '' ? "{$path}.{$actualKey}" : (string) $actualKey;
                        $errors[$propPath] = "Additional property '{$actualKey}' is not allowed.";
                    }
                }
            }
        }

        return $errors;
    }

    private function checkType(mixed $val, string $expected): bool
    {
        return match ($expected) {
            'string' => is_string($val),
            'integer' => is_int($val),
            'number' => is_int($val) || is_float($val),
            'boolean' => is_bool($val),
            'array' => is_array($val) && array_is_list($val),
            'object' => is_array($val) && (!array_is_list($val) || $val === []),
            'null' => $val === null,
            default => true,
        };
    }
}
