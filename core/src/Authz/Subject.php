<?php

declare(strict_types=1);

namespace BsCms\Authz;

/**
 * Subject value object representing the resource type and key targeted by an action.
 */
final class Subject
{
    public function __construct(
        public readonly string $type,
        public readonly string $key = '*'
    ) {
    }

    public static function collection(string $key = '*'): self
    {
        return new self('collection', $key);
    }

    public static function taxonomy(string $key = '*'): self
    {
        return new self('taxonomy', $key);
    }

    public static function media(string $key = '*'): self
    {
        return new self('media', $key);
    }

    public static function users(string $key = '*'): self
    {
        return new self('users', $key);
    }

    public static function roles(string $key = '*'): self
    {
        return new self('roles', $key);
    }

    public static function settings(string $key = '*'): self
    {
        return new self('settings', $key);
    }

    public static function schema(string $key = '*'): self
    {
        return new self('schema', $key);
    }

    public static function audit(string $key = '*'): self
    {
        return new self('audit', $key);
    }

    public static function system(string $key = '*'): self
    {
        return new self('system', $key);
    }
}
