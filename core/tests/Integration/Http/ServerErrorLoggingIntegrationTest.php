<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Http;

use BsCms\Http\Kernel;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Kernel\Environment;
use BsCms\Log\FileLogger;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ServerErrorLoggingIntegrationTest extends TestCase
{
    private string $tempLogDir;
    private string $appLogPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempLogDir = sys_get_temp_dir() . '/bs_cms_err_test_' . bin2hex(random_bytes(6));
        @mkdir($this->tempLogDir, 0777, true);
        $this->appLogPath = $this->tempLogDir . '/app.log';
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempLogDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tempLogDir);
        parent::tearDown();
    }

    public function testForced500IsLoggedWithRequestIdAndDoesNotLeakSqlOrInternals(): void
    {
        $routes = new RouteCollection();
        $forcedRequestId = 'req-forced-500-test-999';

        $routes->get('/api/v1/test-force-500', function () {
            throw new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'secret_pw' in 'field list'");
        });

        $router = new Router($routes);
        $kernel = new Kernel($router);

        $env = new Environment('production', false); // production environment with debug=false
        $logger = new FileLogger($this->appLogPath);

        $kernel->addMiddleware(new RequestIdMiddleware());
        $kernel->addMiddleware(new ErrorHandlerMiddleware($env, $logger));

        $request = new ServerRequest('GET', '/api/v1/test-force-500', [
            'X-Request-ID' => $forcedRequestId,
        ]);

        $response = $kernel->handle($request);

        // 1. Response assertions
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $bodyStr = (string) $response->getBody();
        $body = json_decode($bodyStr, true);

        $this->assertIsArray($body);
        $this->assertSame(500, $body['status']);
        $this->assertSame($forcedRequestId, $body['instance']);
        $this->assertSame('Internal Server Error', $body['title']);

        // Assert NO SQL or internal database exception details leaked to client
        $this->assertStringNotContainsString('SQLSTATE', $bodyStr);
        $this->assertStringNotContainsString('secret_pw', $bodyStr);
        $this->assertStringNotContainsString('PDOException', $bodyStr);
        $this->assertStringNotContainsString('Unknown column', $bodyStr);

        // 2. Log file assertions: ensure log contains exception trace with request ID
        $this->assertFileExists($this->appLogPath);
        $logContent = file_get_contents($this->appLogPath);
        $this->assertIsString($logContent);
        $this->assertNotEmpty($logContent);

        $this->assertStringContainsString($forcedRequestId, $logContent);
        $this->assertStringContainsString('PDOException', $logContent);
        $this->assertStringContainsString('SQLSTATE[42S22]', $logContent);
    }
}
