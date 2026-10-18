<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Http;

use BsCms\Http\Kernel;
use BsCms\Http\Middleware\BodyLimitMiddleware;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\HttpsEnforcerMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\MaintenanceModeMiddleware;
use BsCms\Http\Middleware\RateLimitMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SecurityHeadersMiddleware;
use BsCms\Kernel\Application;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use BsCms\Security\RateLimiter;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class BudgetTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootDir = dirname(__DIR__, 2);
        RateLimiter::clear();
    }

    public function testHealthAndApiBudgetsMeetBaselineTargets(): void
    {
        $app = new Application($this->rootDir);
        $routes = new RouteCollection();
        $router = new Router($routes);
        $kernel = new Kernel($router);

        $kernel->addMiddleware(new ErrorHandlerMiddleware($app->getEnvironment()));
        $kernel->addMiddleware(new RequestIdMiddleware());
        $kernel->addMiddleware(new SecurityHeadersMiddleware());
        $kernel->addMiddleware(new HttpsEnforcerMiddleware($app->getEnvironment()));
        $kernel->addMiddleware(new MaintenanceModeMiddleware($this->rootDir . '/storage'));
        $kernel->addMiddleware(new BodyLimitMiddleware(2 * 1024 * 1024));
        $kernel->addMiddleware(new JsonBodyMiddleware());
        $kernel->addMiddleware(new RateLimitMiddleware(new RateLimiter(60, 60)));

        // 1. Health check route through full pipeline
        $healthRequest = new ServerRequest('GET', 'https://localhost/health');
        $healthResponse = $kernel->handle($healthRequest);

        $this->assertSame(200, $healthResponse->getStatusCode());
        $this->assertSame('no-store', $healthResponse->getHeaderLine('Cache-Control'));

        $startMemory = memory_get_usage(false);

        // 2. Real API route (404 through FastRoute)
        $apiRequest = new ServerRequest('GET', 'https://localhost/api/v1/nope');
        $apiResponse = $kernel->handle($apiRequest);

        $this->assertSame(404, $apiResponse->getStatusCode());
        $this->assertSame('no-store', $apiResponse->getHeaderLine('Cache-Control'));

        // Measure memory usage
        $deltaMb = (memory_get_usage(false) - $startMemory) / 1048576;
        $systemMb = memory_get_peak_usage(true) / 1048576;

        // Actual request dynamic heap delta must be under 2 MB
        $this->assertLessThan(2.0, $deltaMb, "Request memory delta ({$deltaMb} MB) exceeds 2.0 MB budget");

        // Total system allocated memory must stay well under the 32 MB global budget
        $this->assertLessThan(32.0, $systemMb, "System memory ({$systemMb} MB) exceeds 32.0 MB global budget");
    }

    public function testColdBootTimeBudget(): void
    {
        $times = [];
        // Warm up autoloader once
        $app = new Application($this->rootDir);
        $routes = new RouteCollection();
        $router = new Router($routes);
        $_warmKernel = new Kernel($router);
        unset($_warmKernel);

        for ($i = 0; $i < 30; $i++) {
            $start = microtime(true);
            $app = new Application($this->rootDir);
            $routes = new RouteCollection();
            $router = new Router($routes);
            $kernel = new Kernel($router);
            $durationMs = (microtime(true) - $start) * 1000;
            $times[] = $durationMs;
        }

        sort($times);
        $p50 = $times[15];
        $p95 = $times[28];

        // Cold boot budget < 15 ms (p50) on dev machine, allow up to 35 ms in shared CI environments
        $isCi = getenv('CI') !== false;
        $maxP50 = $isCi ? 35.0 : 15.0;
        $maxP95 = $isCi ? 60.0 : 25.0;

        $this->assertLessThan($maxP50, $p50, "p50 boot time ({$p50} ms) exceeds {$maxP50} ms budget");
        $this->assertLessThan($maxP95, $p95, "p95 boot time ({$p95} ms) exceeds {$maxP95} ms budget");
    }

    public function testArgon2idPasswordHashAndVerifyLatencyBudget(): void
    {
        $hasher = new \BsCms\Auth\PasswordHasher();
        $times = [];
        for ($i = 0; $i < 5; $i++) {
            $start = microtime(true);
            $hash = $hasher->hash('PassphraseBudgetTest2026!');
            $hasher->verify('PassphraseBudgetTest2026!', $hash);
            $times[] = (microtime(true) - $start) * 1000;
        }

        sort($times);
        $p95 = $times[4];

        // Login budget per spec 2.10: login request < 300 ms p95 including Argon2id
        $this->assertLessThan(300.0, $p95, "Argon2id hash+verify p95 ({$p95} ms) exceeds 300 ms budget");
    }
}
