<?php

declare(strict_types=1);

namespace BsCms\Cache;

use BsCms\Support\Clock;
use Closure;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * FileCache: atomic write via temp file + rename, two-level sharded directory structure,
 * and unix timestamp expiration header. Default production driver for shared hosting.
 */
final class FileCache implements CacheInterface
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim(str_replace('\\', '/', $directory), '/');
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0750, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->pathForKey($key);
        if (!is_file($path)) {
            return $default;
        }

        $content = @file_get_contents($path);
        if ($content === false || strlen($content) < 10) {
            return $default;
        }

        // Header format: 10 digits unix timestamp + "\n" + serialized payload
        $expireAt = (int) substr($content, 0, 10);
        $now = Clock::timestamp();

        if ($expireAt !== 0 && $expireAt < $now) {
            // Expired: delete and return default
            @unlink($path);
            return $default;
        }

        $payload = substr($content, 11);
        $data = @unserialize($payload);

        return $data === false && $payload !== serialize(false) ? $default : $data;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $path = $this->pathForKey($key);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $expireAt = ($ttl === null || $ttl <= 0) ? 0 : (Clock::timestamp() + $ttl);
        $header = sprintf('%010d' . "\n", $expireAt);
        $payload = $header . serialize($value);

        // Atomic write: write to unique temporary file then rename
        $tempFile = $dir . '/' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tempFile, $payload, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tempFile, $path)) {
            @unlink($tempFile);
            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $path = $this->pathForKey($key);
        if (is_file($path)) {
            return @unlink($path);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function remember(string $key, ?int $ttl, Closure $callback): mixed
    {
        $value = $this->get($key, $this);
        if ($value !== $this) {
            return $value;
        }

        $fresh = $callback();
        $this->set($key, $fresh, $ttl);
        return $fresh;
    }

    public function clearPrefix(string $prefix): int
    {
        $deleted = 0;
        $hashPrefix = hash('sha256', $prefix);
        // Because of sha256 hashing, prefix matching requires inspecting keys or clearing matching directories
        // If prefix ends with wildcards or exact namespace, we iterate cache files
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'cache') {
                // If prefix is empty or general clear
                if ($prefix === '' || str_contains($item->getFilename(), $prefix)) {
                    if (@unlink($item->getPathname())) {
                        ++$deleted;
                    }
                }
            }
        }

        return $deleted;
    }

    public function clear(): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        return true;
    }

    /**
     * Compute two-level sharded directory path for key:
     * e.g., sha256(key) = abcd1234... -> directory/ab/cd/abcd1234....cache
     */
    private function pathForKey(string $key): string
    {
        $hash = hash('sha256', $key);
        $shard1 = substr($hash, 0, 2);
        $shard2 = substr($hash, 2, 2);

        return "{$this->directory}/{$shard1}/{$shard2}/{$hash}.cache";
    }
}
