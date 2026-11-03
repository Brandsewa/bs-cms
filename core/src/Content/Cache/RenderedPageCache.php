<?php

declare(strict_types=1);

namespace BsCms\Content\Cache;

/**
 * Full-page HTML cache for the on-the-fly site renderer (WordPress-style
 * publish model). Rendered pages are stored per URL path under
 * storage/cache/site/; any relevant content event clears the whole cache —
 * sites are small, so a full flush is cheap and always correct.
 */
final class RenderedPageCache
{
    private string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0750, true);
        }
    }

    /** @return array{status: int, body: string, content_type: string}|null */
    public function get(string $urlPath): ?array
    {
        $file = $this->filePath($urlPath);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['body'])) {
            @unlink($file);

            return null;
        }

        return [
            'status' => (int) ($data['status'] ?? 200),
            'body' => (string) $data['body'],
            'content_type' => (string) ($data['content_type'] ?? 'text/html; charset=UTF-8'),
        ];
    }

    public function set(string $urlPath, int $status, string $contentType, string $body): void
    {
        $file = $this->filePath($urlPath);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $payload = json_encode([
            'status' => $status,
            'content_type' => $contentType,
            'body' => $body,
        ], JSON_THROW_ON_ERROR);

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * Clear every cached page. Called on publish/unpublish/navigation/
     * settings/branding events — small sites re-render in milliseconds.
     */
    public function clear(): void
    {
        if (!is_dir($this->baseDir)) {
            return;
        }

        $items = @scandir($this->baseDir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $this->baseDir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function filePath(string $urlPath): string
    {
        return $this->baseDir . '/' . hash('sha256', $urlPath) . '.html';
    }
}
