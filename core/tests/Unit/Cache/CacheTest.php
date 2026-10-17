<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Cache;

use BsCms\Cache\FileCache;
use BsCms\Cache\PhpArrayCache;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $tempCacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempCacheDir = sys_get_temp_dir() . '/bs_cms_test_cache_' . bin2hex(random_bytes(6));
        if (!is_dir($this->tempCacheDir)) {
            mkdir($this->tempCacheDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->tempCacheDir);
        parent::tearDown();
    }

    private function deleteRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = glob($dir . '/*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->deleteRecursive($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }

    public function testFileCacheSetGetAndHas(): void
    {
        $cache = new FileCache($this->tempCacheDir);

        $this->assertFalse($cache->has('key1'));
        $this->assertSame('default', $cache->get('key1', 'default'));

        $this->assertTrue($cache->set('key1', ['data' => 123, 'str' => 'hello']));
        $this->assertTrue($cache->has('key1'));

        $val = $cache->get('key1');
        $this->assertIsArray($val);
        $this->assertSame(123, $val['data']);
        $this->assertSame('hello', $val['str']);

        $this->assertTrue($cache->delete('key1'));
        $this->assertFalse($cache->has('key1'));
    }

    public function testFileCacheExpiration(): void
    {
        $cache = new FileCache($this->tempCacheDir);

        // Set with TTL = 1 second
        $cache->set('ephemeral', 'temp_val', 1);
        $this->assertSame('temp_val', $cache->get('ephemeral'));

        // Sleep 2 seconds to ensure expiration
        sleep(2);

        $this->assertNull($cache->get('ephemeral'));
        $this->assertFalse($cache->has('ephemeral'));
    }

    public function testFileCacheRemember(): void
    {
        $cache = new FileCache($this->tempCacheDir);

        $callCount = 0;
        $factory = function () use (&$callCount): string {
            ++$callCount;
            return 'computed_result';
        };

        $val1 = $cache->remember('compute_key', 60, $factory);
        $this->assertSame('computed_result', $val1);
        $this->assertSame(1, $callCount);

        // Second fetch hits cache
        $val2 = $cache->remember('compute_key', 60, $factory);
        $this->assertSame('computed_result', $val2);
        $this->assertSame(1, $callCount);
    }

    public function testPhpArrayCacheStoresAndLoadsValidPhpArray(): void
    {
        $arrayCache = new PhpArrayCache($this->tempCacheDir);

        $configData = [
            'app' => [
                'name' => 'BS CMS',
                'debug' => false,
            ],
            'database' => [
                'host' => '127.0.0.1',
                'port' => 3306,
            ],
        ];

        $this->assertTrue($arrayCache->set('config', $configData));
        $this->assertTrue($arrayCache->has('config'));

        $loaded = $arrayCache->get('config');
        $this->assertIsArray($loaded);
        $this->assertSame('BS CMS', $loaded['app']['name']);
        $this->assertSame(3306, $loaded['database']['port']);

        $this->assertTrue($arrayCache->delete('config'));
        $this->assertFalse($arrayCache->has('config'));
        $this->assertNull($arrayCache->get('config'));
    }
}
