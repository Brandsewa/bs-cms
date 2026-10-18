<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Content;

use BsCms\Content\Cache\PublicResponseCache;
use PHPUnit\Framework\TestCase;

final class PublicResponseCacheTest extends TestCase
{
    private string $tempDir;
    private PublicResponseCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/bscms_cache_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0750, true);
        $this->cache = new PublicResponseCache($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testGetSetAndInvalidateCollection(): void
    {
        $body = (string) json_encode(['data' => ['id' => '123', 'title' => 'Hello']]);
        $etag = '"etag123"';

        $this->assertNull($this->cache->get('posts', 'list_key'));

        $this->cache->set('posts', 'list_key', 200, ['Content-Type' => 'application/json'], $body, $etag, 60);

        $cached = $this->cache->get('posts', 'list_key');
        $this->assertNotNull($cached);
        $this->assertSame(200, $cached['status']);
        $this->assertSame($body, $cached['body']);
        $this->assertSame($etag, $cached['etag']);

        // Set cache for another collection
        $this->cache->set('pages', 'page_key', 200, [], '{"page":1}', '"page_etag"', 60);
        $this->assertNotNull($this->cache->get('pages', 'page_key'));

        // Invalidate posts collection only
        $this->cache->invalidateCollection('posts');

        $this->assertNull($this->cache->get('posts', 'list_key'));
        // Pages collection remains cached
        $this->assertNotNull($this->cache->get('pages', 'page_key'));
    }

    public function testExpirationRemovesItem(): void
    {
        $this->cache->set('posts', 'expired_key', 200, [], '{}', '"etag"', -1);
        $this->assertNull($this->cache->get('posts', 'expired_key'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
