<?php

declare(strict_types=1);

namespace BsCms\Schema;

use InvalidArgumentException;

final class IdentifierValidator
{
    /**
     * Reserved column handles that cannot be used as field handles in collection tables.
     */
    public const SYSTEM_COLUMNS = [
        'id',
        'slug',
        'title',
        'status',
        'locale',
        'parent_id',
        'position',
        'author_id',
        'published_at',
        'scheduled_at',
        'seo',
        'data',
        'search_text',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
        'deleted_at',
    ];

    /**
     * Reserved collection handles.
     */
    public const RESERVED_COLLECTIONS = [
        'users',
        'roles',
        'permissions',
        'sessions',
        'audit_log',
        'settings',
        'migrations',
        'rate_limits',
        'webhooks',
        'webhook_deliveries',
        'jobs',
        'media',
        'schema_changes',
        'collections',
        'fields',
        'taxonomies',
        'terms',
        'term_relationships',
        'entry_relations',
        'entry_versions',
        'saved_views',
        'public',
        'api',
        'admin',
        'auth',
        'system',
    ];

    public static function isValidHandle(string $handle): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{1,63}$/', $handle);
    }

    public static function validateCollectionHandle(string $handle): void
    {
        if (!self::isValidHandle($handle)) {
            throw new InvalidArgumentException(
                "Invalid collection handle '{$handle}'. Must start with a letter and contain only lowercase letters, digits, and underscores (max 64 chars)."
            );
        }

        if (in_array(strtolower($handle), self::RESERVED_COLLECTIONS, true)) {
            throw new InvalidArgumentException("Collection handle '{$handle}' is reserved and cannot be used.");
        }
    }

    public static function validateTaxonomyHandle(string $handle): void
    {
        if (!self::isValidHandle($handle)) {
            throw new InvalidArgumentException(
                "Invalid taxonomy handle '{$handle}'. Must start with a letter and contain only lowercase letters, digits, and underscores (max 64 chars)."
            );
        }

        if (in_array(strtolower($handle), self::RESERVED_COLLECTIONS, true)) {
            throw new InvalidArgumentException("Taxonomy handle '{$handle}' is reserved and cannot be used.");
        }
    }

    public static function validateFieldHandle(string $handle): void
    {
        if (!self::isValidHandle($handle)) {
            throw new InvalidArgumentException(
                "Invalid field handle '{$handle}'. Must start with a letter and contain only lowercase letters, digits, and underscores (max 64 chars)."
            );
        }

        if (in_array(strtolower($handle), self::SYSTEM_COLUMNS, true)) {
            throw new InvalidArgumentException("Field handle '{$handle}' conflicts with a system column and cannot be used.");
        }
    }

    public static function escapeIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException("SQL injection danger: invalid identifier '{$identifier}'");
        }
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
