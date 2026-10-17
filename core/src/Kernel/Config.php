<?php

declare(strict_types=1);

namespace BsCms\Kernel;

use BsCms\Support\Arr;

/**
 * Three-tier configuration repository with plain PHP array cache support for OPcache.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $items = [];

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Load configuration from files, storage/config.php, and BS_* environment variables.
     *
     * @param string $configPath Directory containing default config/*.php
     * @param string|null $storageConfig Path to storage/config.php if present
     * @param string|null $cachedConfigFile Path to storage/cache/config.php if present
     * @return self
     */
    public static function load(
        string $configPath,
        ?string $storageConfig = null,
        ?string $cachedConfigFile = null
    ): self {
        // 1. If compiled cache exists and is readable, use it directly (OPcache-friendly)
        if ($cachedConfigFile !== null && is_file($cachedConfigFile)) {
            $cached = require $cachedConfigFile;
            if (is_array($cached)) {
                return new self($cached);
            }
        }

        // 2. Load defaults from config directory
        $items = [];
        if (is_dir($configPath)) {
            $files = glob(rtrim($configPath, '/\\') . '/*.php') ?: [];
            foreach ($files as $file) {
                $key = basename($file, '.php');
                $content = require $file;
                if (is_array($content)) {
                    $items[$key] = $content;
                }
            }
        }

        // 3. Merge installer storage/config.php overrides if present
        if ($storageConfig !== null && is_file($storageConfig)) {
            $storageItems = require $storageConfig;
            if (is_array($storageItems)) {
                $items = Arr::mergeRecursive($items, $storageItems);
            }
        }

        // 4. Merge BS_* environment variables
        $envOverrides = self::parseEnvOverrides();
        if (!empty($envOverrides)) {
            $items = Arr::mergeRecursive($items, $envOverrides);
        }

        // 5. If key_file is specified, load key from storage/keys/app.key
        if ($storageConfig !== null && !empty($items['app']['key_file']) && empty($items['app']['key'])) {
            $storageDir = dirname($storageConfig);
            $keyPath = $storageDir . '/' . ltrim((string) $items['app']['key_file'], '/');
            if (is_file($keyPath)) {
                $items['app']['key'] = trim((string) file_get_contents($keyPath));
            }
        }

        return new self($items);
    }

    /**
     * Get a configuration value using dot notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    /**
     * Set a configuration value at runtime using dot notation.
     */
    public function set(string $key, mixed $value): void
    {
        Arr::set($this->items, $key, $value);
    }

    /**
     * Check if a configuration key exists using dot notation.
     */
    public function has(string $key): bool
    {
        return Arr::has($this->items, $key);
    }

    /**
     * Return all configuration items.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Generate the plain PHP code representing the compiled configuration array.
     */
    public function compile(): string
    {
        $exported = var_export($this->items, true);

        return "<?php\n\ndeclare(strict_types=1);\n\n// Auto-compiled BS CMS configuration cache\nreturn {$exported};\n";
    }

    /**
     * Parse environment variables prefixed with BS_ into a nested array.
     * Maps BS_APP__DEBUG -> app.debug or BS_DATABASE__CONNECTION__HOST -> database.connection.host.
     * Single underscores within word boundaries stay lowercase; double underscores denote hierarchy.
     *
     * @return array<string, mixed>
     */
    public static function parseEnvOverrides(): array
    {
        $overrides = [];
        $env = getenv();

        foreach ($env as $name => $value) {
            if (!str_starts_with($name, 'BS_')) {
                continue;
            }

            $rawKey = substr($name, 3);
            if ($rawKey === '') {
                continue;
            }

            // Convert DOUBLE UNDERSCORE to DOT
            if (str_contains($rawKey, '__')) {
                $segments = explode('__', strtolower($rawKey));
            } else {
                // Single underscore fallback (e.g. BS_DEBUG -> debug, BS_APP_URL -> app.url)
                $firstUnderscore = strpos($rawKey, '_');
                if ($firstUnderscore !== false) {
                    $prefix = strtolower(substr($rawKey, 0, $firstUnderscore));
                    $suffix = strtolower(substr($rawKey, $firstUnderscore + 1));
                    $segments = [$prefix, $suffix];
                } else {
                    $segments = [strtolower($rawKey)];
                }
            }

            $dotKey = implode('.', $segments);
            Arr::set($overrides, $dotKey, self::castEnvValue($value));
        }

        return $overrides;
    }

    private static function castEnvValue(string $value): mixed
    {
        $lower = strtolower(trim($value));
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }
}
