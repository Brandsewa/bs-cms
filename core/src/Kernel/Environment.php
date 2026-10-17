<?php

declare(strict_types=1);

namespace BsCms\Kernel;

/**
 * Environment representation and strict production safety guards.
 */
final class Environment
{
    public const PRODUCTION = 'production';
    public const STAGING = 'staging';
    public const TESTING = 'testing';
    public const DEVELOPMENT = 'development';

    private string $name;
    private bool $debug;

    public function __construct(string $name = self::PRODUCTION, bool $debug = false)
    {
        $normalized = strtolower(trim($name));
        $this->name = match ($normalized) {
            'prod', 'production' => self::PRODUCTION,
            'stage', 'staging' => self::STAGING,
            'test', 'testing' => self::TESTING,
            'dev', 'development', 'local' => self::DEVELOPMENT,
            default => self::PRODUCTION,
        };

        // Strict invariant: debug output is physically impossible in production
        if ($this->name === self::PRODUCTION) {
            $this->debug = false;
        } else {
            $this->debug = $debug;
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isProduction(): bool
    {
        return $this->name === self::PRODUCTION;
    }

    public function isStaging(): bool
    {
        return $this->name === self::STAGING;
    }

    public function isTesting(): bool
    {
        return $this->name === self::TESTING;
    }

    public function isDevelopment(): bool
    {
        return $this->name === self::DEVELOPMENT;
    }

    /**
     * Whether debug mode is active (strictly false in production).
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }
}
