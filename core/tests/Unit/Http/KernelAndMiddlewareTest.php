<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Http;

use BsCms\Http\Kernel;
use BsCms\Http\Middleware\BodyLimitMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SecurityHeadersMiddleware;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class KernelAndMiddlewareTest extends TestCase
{
    private function createKernel(RouteCollection $routes): Kernel
    {
        $router = new Router($routes);
        $kernel = new Kernel($router);

        $kernel->addMiddleware(new RequestIdMiddleware());
        $kernel->addMiddleware(new SecurityHeadersMiddleware());
        $kernel->addMiddleware(new BodyLimitMiddleware(1024));
        $kernel->addMiddleware(new JsonBodyMiddleware());

        return $kernel;
    }

    public function testHealthEndpointReturnsOkWithSecurityHeaders(): void
    {
        $routes = new RouteCollection();
        $kernel = $this->createKernel($routes);

        $request = new ServerRequest('GET', '/health');
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->getHeaderLine('X-Request-ID'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));

        $body = (string) $response->getBody();
        $this->assertJsonStringEqualsJsonString(json_encode(['status' => 'ok']) ?: '', $body);
    }

    public function testReservedEndpointsReturn404(): void
    {
        $routes = new RouteCollection();
        $kernel = $this->createKernel($routes);

        foreach (['/mcp', '/oauth/token', '/.well-known/oauth-authorization-server'] as $path) {
            $request = new ServerRequest('GET', $path);
            $response = $kernel->handle($request);

            $this->assertSame(404, $response->getStatusCode());
            $this->assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        }
    }

    public function testAdminSpaFallback(): void
    {
        $routes = new RouteCollection();
        $kernel = $this->createKernel($routes);

        $request = new ServerRequest('GET', '/admin/dashboard');
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('BS CMS Admin', (string) $response->getBody());
    }

    public function testBodyLimitMiddlewareRejectsOversizedPayload(): void
    {
        $routes = new RouteCollection();
        $kernel = $this->createKernel($routes);

        $largePayload = str_repeat('A', 2048);
        $request = (new ServerRequest('POST', '/api/test'))
            ->withHeader('Content-Length', '2048');
        $request->getBody()->write($largePayload);

        $response = $kernel->handle($request);
        $this->assertSame(413, $response->getStatusCode());
    }
}
