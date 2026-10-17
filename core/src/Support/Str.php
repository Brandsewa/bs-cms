<?php

declare(strict_types=1);

namespace BsCms\Support;

/**
 * String utilities for formatting, slug generation, and ASCII checks.
 */
final class Str
{
    /**
     * Generate a URL-friendly slug from string.
     */
    public static function slug(string $title, string $separator = '-'): string
    {
        // Convert to lowercase
        $slug = mb_strtolower($title, 'UTF-8');
        // Replace non-alphanumeric characters with separator
        $slug = preg_replace('/[^\p{L}\p{Nd}]+/u', $separator, $slug) ?? '';
        // Trim separators
        $slug = trim($slug, $separator);

        return $slug !== '' ? $slug : 'item';
    }

    /**
     * Check if a string is pure ASCII.
     */
    public static function isAscii(string $value): bool
    {
        return mb_check_encoding($value, 'ASCII');
    }

    /**
     * Validate an identifier matching docs/01-data-model.md: ^[a-z][a-z0-9_]{1,40}$
     */
    public static function isValidIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{1,40}$/', $name);
    }

    /**
     * Validate a database table/column identifier: ^[a-z_][a-z0-9_]*$
     */
    public static function isValidDbIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[a-z_][a-z0-9_]*$/i', $name);
    }

    /**
     * Check whether a string starts with a given substring.
     */
    public static function startsWith(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }

    /**
     * Check whether a string ends with a given substring.
     */
    public static function endsWith(string $haystack, string $needle): bool
    {
        return str_ends_with($haystack, $needle);
    }
}
