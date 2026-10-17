<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Kernel;

use BsCms\Kernel\Application;
use BsCms\Kernel\Config;
use BsCms\Kernel\Container;
use BsCms\Kernel\Environment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ContainerAndConfigTest extends TestCase
{
    public function testContainerSingletonResolution(): void
    {
        $container = new Container();
        $counter = 0;

        $container->singleton('test_service', function () use (&$counter) {
            ++$counter;
            $obj = new stdClass();
            $obj->count = $counter;
            return $obj;
        });

        $this->assertTrue($container->has('test_service'));

        /** @var stdClass $first */
        $first = $container->get('test_service');
        /** @var stdClass $second */
        $second = $container->get('test_service');

        $this->assertSame(1, $first->count);
        $this->assertSame($first, $second);
    }

    public function testContainerTransientResolution(): void
    {
        $container = new Container();
        $counter = 0;

        $container->bind('transient_service', function () use (&$counter) {
            ++$counter;
            $obj = new stdClass();
            $obj->count = $counter;
            return $obj;
        });

        /** @var stdClass $first */
        $first = $container->get('transient_service');
        /** @var stdClass $second */
        $second = $container->get('transient_service');

        $this->assertSame(1, $first->count);
        $this->assertSame(2, $second->count);
        $this->assertNotSame($first, $second);
    }

    public function testContainerDetectsCircularDependency(): void
    {
        $container = new Container();

        $container->singleton('service_a', fn (Container $c) => $c->get('service_b'));
        $container->singleton('service_b', fn (Container $c) => $c->get('service_a'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular dependency');
        $container->get('service_a');
    }

    public function testContainerThrowsOnUnregisteredService(): void
    {
        $container = new Container();

        $this->expectException(InvalidArgumentException::class);
        $container->get('missing_service');
    }

    public function testEnvironmentProductionGuardDisallowsDebug(): void
    {
        $prod = new Environment('production', true); // explicitly requesting debug true
        $this->assertTrue($prod->isProduction());
        $this->assertFalse($prod->isDebug()); // invariant: must be false in production

        $dev = new Environment('development', true);
        $this->assertTrue($dev->isDevelopment());
        $this->assertTrue($dev->isDebug());

        $staging = new Environment('staging', false);
        $this->assertTrue($staging->isStaging());
        $this->assertFalse($staging->isDebug());
    }

    public function testConfigCascadeAndCompilation(): void
    {
        $tempDir = sys_get_temp_dir() . '/bs_test_config_' . bin2hex(random_bytes(6));
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/defaults', 0777, true);
        mkdir($tempDir . '/storage', 0777, true);

        try {
            // 1. Defaults
            file_put_contents(
                $tempDir . '/defaults/app.php',
                "<?php return ['name' => 'Default Name', 'url' => 'http://default.com', 'debug' => false];"
            );

            // 2. Storage overrides
            file_put_contents(
                $tempDir . '/storage/config.php',
                "<?php return ['app' => ['url' => 'http://custom.com']];"
            );

            $config = Config::load($tempDir . '/defaults', $tempDir . '/storage/config.php');

            $this->assertSame('Default Name', $config->get('app.name'));
            $this->assertSame('http://custom.com', $config->get('app.url')); // overridden
            $this->assertFalse($config->get('app.debug'));

            // 3. Compile config
            $compiledCode = $config->compile();
            $this->assertStringContainsString('Auto-compiled BS CMS configuration cache', $compiledCode);
            file_put_contents($tempDir . '/storage/cache_config.php', $compiledCode);

            // 4. Load from compiled cache
            $cachedConfig = Config::load(
                $tempDir . '/defaults',
                $tempDir . '/storage/config.php',
                $tempDir . '/storage/cache_config.php'
            );
            $this->assertSame('http://custom.com', $cachedConfig->get('app.url'));
        } finally {
            @unlink($tempDir . '/defaults/app.php');
            @unlink($tempDir . '/storage/config.php');
            @unlink($tempDir . '/storage/cache_config.php');
            @rmdir($tempDir . '/defaults');
            @rmdir($tempDir . '/storage');
            @rmdir($tempDir);
        }
    }

    public function testApplicationCreationAndPaths(): void
    {
        $app = Application::create(dirname(__DIR__, 2));
        $this->assertFalse($app->isInstalled());
        $this->assertSame('0.1.0', Application::VERSION);
        $this->assertStringEndsWith('/config', $app->configPath());
        $this->assertStringEndsWith('/storage', $app->storagePath());
        $this->assertStringEndsWith('/public', $app->publicPath());
    }
}
