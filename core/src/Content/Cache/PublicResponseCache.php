<?php

declare(strict_types=1);

namespace BsCms\Content\Cache;

final class PublicResponseCache
{
    private string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0750, true);
        }
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string, etag: string}|null
     */
    public function get(string $collectionHandle, string $key): ?array
    {
        $file = $this->filePath($collectionHandle, $key);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            @unlink($file);
            return null;
        }

        if (isset($data['expires_at']) && is_numeric($data['expires_at']) && (int) $data['expires_at'] < time()) {
            @unlink($file);
            return null;
        }

        return [
            'status' => isset($data['status']) ? (int) $data['status'] : 200,
            'headers' => isset($data['headers']) && is_array($data['headers']) ? $data['headers'] : [],
            'body' => isset($data['body']) ? (string) $data['body'] : '',
            'etag' => isset($data['etag']) ? (string) $data['etag'] : '',
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    public function set(
        string $collectionHandle,
        string $key,
        int $status,
        array $headers,
        string $body,
        string $etag,
        int $ttl = 60
    ): void {
        $file = $this->filePath($collectionHandle, $key);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $payload = [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'etag' => $etag,
            'expires_at' => time() + $ttl,
        ];

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);
        @rename($tmp, $file);
    }

    /**
     * Invalidate all cached responses for a collection (tag-based invalidation: collection:{handle}).
     */
    public function invalidateCollection(string $collectionHandle): void
    {
        $dir = $this->baseDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $collectionHandle);
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') {
                    @unlink($dir . '/' . $item);
                }
            }
        }
        @rmdir($dir);
    }

    /**
     * Clear all public cache.
     */
    public function clear(): void
    {
        if (!is_dir($this->baseDir)) {
            return;
        }

        $this->deleteDirectory($this->baseDir, false);
    }

    private function filePath(string $collectionHandle, string $key): string
    {
        $safeCollection = preg_replace('/[^a-zA-Z0-9_-]/', '_', $collectionHandle);
        $hash = hash('sha256', $key);

        return "{$this->baseDir}/{$safeCollection}/{$hash}.json";
    }

    private function deleteDirectory(string $dir, bool $removeSelf = true): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path, true);
            } else {
                @unlink($path);
            }
        }

        if ($removeSelf) {
            @rmdir($dir);
        }
    }
}
