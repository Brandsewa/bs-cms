<?php

declare(strict_types=1);

namespace BsCms\Cache;

use Closure;

/**
 * CacheInterface defines the common caching contract across all drivers in BS CMS.
 */
interface CacheInterface
{
    /**
     * Retrieve an item from the cache.
     *
     * @param mixed $default Returned if key does not exist or has expired.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache with a TTL in seconds.
     *
     * @param int|null $ttl Time to live in seconds (null = forever / driver default max).
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Delete an item from the cache.
     */
    public function delete(string $key): bool;

    /**
     * Determine if an item exists in the cache and has not expired.
     */
    public function has(string $key): bool;

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function remember(string $key, ?int $ttl, Closure $callback): mixed;

    /**
     * Remove all cache entries matching a given key prefix.
     */
    public function clearPrefix(string $prefix): int;

    /**
     * Wipe entire cache.
     */
    public function clear(): bool;
}
