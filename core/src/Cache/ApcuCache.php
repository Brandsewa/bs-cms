<?php

declare(strict_types=1);

namespace BsCms\Cache;

use Closure;

/**
 * ApcuCache: in-memory APCu driver for shared memory caching when loaded and enabled.
 */
final class ApcuCache implements CacheInterface
{
    private string $prefix;

    public function __construct(string $prefix = 'bs_')
    {
        $this->prefix = $prefix;
    }

    public static function isSupported(): bool
    {
        return extension_loaded('apcu') && (php_sapi_name() !== 'cli' || (bool) ini_get('apc.enable_cli'));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!self::isSupported()) {
            return $default;
        }

        $success = false;
        $val = apcu_fetch($this->prefix . $key, $success);

        return $success ? $val : $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!self::isSupported()) {
            return false;
        }

        return apcu_store($this->prefix . $key, $value, $ttl ?? 0);
    }

    public function delete(string $key): bool
    {
        if (!self::isSupported()) {
            return false;
        }

        return apcu_delete($this->prefix . $key);
    }

    public function has(string $key): bool
    {
        if (!self::isSupported()) {
            return false;
        }

        return apcu_exists($this->prefix . $key);
    }

    public function remember(string $key, ?int $ttl, Closure $callback): mixed
    {
        $val = $this->get($key, $this);
        if ($val !== $this) {
            return $val;
        }

        $fresh = $callback();
        $this->set($key, $fresh, $ttl);
        return $fresh;
    }

    public function clearPrefix(string $prefix): int
    {
        if (!self::isSupported()) {
            return 0;
        }

        $pattern = '/^' . preg_quote($this->prefix . $prefix, '/') . '/';
        $iterator = new \APCUIterator($pattern);
        $count = 0;

        foreach ($iterator as $item) {
            if (apcu_delete($item['key'])) {
                ++$count;
            }
        }

        return $count;
    }

    public function clear(): bool
    {
        if (!self::isSupported()) {
            return true;
        }

        return apcu_clear_cache();
    }
}
