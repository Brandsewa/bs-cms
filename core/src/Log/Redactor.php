<?php

declare(strict_types=1);

namespace BsCms\Log;

/**
 * Redactor scrubs sensitive credentials, tokens, cookies, and keys from data structures before logging.
 */
final class Redactor
{
    private const SENSITIVE_PATTERN = '/(?:password|secret|token|authorization|cookie|recovery_code|key)/i';
    public const REDACTED_PLACEHOLDER = '[REDACTED]';

    /**
     * Recursively redact sensitive values from arrays, scalars, or objects.
     */
    public static function redact(mixed $data): mixed
    {
        if (is_array($data)) {
            $cleaned = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && preg_match(self::SENSITIVE_PATTERN, $key)) {
                    $cleaned[$key] = self::REDACTED_PLACEHOLDER;
                } else {
                    $cleaned[$key] = self::redact($value);
                }
            }
            return $cleaned;
        }

        if (is_string($data)) {
            // Redact Bearer tokens in strings
            $data = preg_replace('/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i', '$1' . self::REDACTED_PLACEHOLDER, $data) ?? $data;
            // Redact basic auth in strings
            $data = preg_replace('/(Basic\s+)[A-Za-z0-9+\/]+=*/i', '$1' . self::REDACTED_PLACEHOLDER, $data) ?? $data;
            return $data;
        }

        return $data;
    }
}
