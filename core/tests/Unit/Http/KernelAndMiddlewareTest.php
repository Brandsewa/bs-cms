<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Http;

use BsCms\Http\Kernel;
use BsCms\Http\Middleware\BodyLimitMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\RateLimitMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SecurityHeadersMiddleware;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class KernelAndMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \BsCms\Security\RateLimiter::clear();
    }

    private function createKernel(RouteCollection $routes): Kernel
    {
        $router = new Router($routes);
        $kernel = new Kernel($router);

        $kernel->addMiddleware(new RequestIdMiddleware());
        $kernel->addMiddleware(new SecurityHeadersMiddleware());
        $kernel->addMiddleware(new BodyLimitMiddleware(1024));
        $kernel->addMiddleware(new JsonBodyMiddleware());
        $kernel->addMiddleware(new RateLimitMiddleware(new \BsCms\Security\RateLimiter(60, 60)));

        return $kernel;
    }

    public function testHealthEndpointReturnsOkWithSecurityAndRateLimitHeaders(): void
    {
        $routes = new RouteCollection();
        $kernel = $this->createKernel($routes);

        $request = new ServerRequest('GET', '/health');
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->getHeaderLine('X-Request-ID'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertNotEmpty($response->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('60', $response->getHeaderLine('RateLimit-Limit'));
        $this->assertSame('59', $response->getHeaderLine('RateLimit-Remaining'));
        $this->assertNotEmpty($response->getHeaderLine('RateLimit-Reset'));

        $body = (string) $response->getBody();
        $this->assertJsonStringEqualsJsonString(json_encode(['status' => 'ok']) ?: '', $body);
    }

    public function testHealthEndpointRejectsNonGetMethodWith405(): void
    {
        $routes = new RouteCollection();
        $kernel = $this->createKernel($routes);

        $request = new ServerRequest('POST', '/health');
        $response = $kernel->handle($request);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET', $response->getHeaderLine('Allow'));
        $this->assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Method Not Allowed', $body);
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
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
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

    public function testBodyLimitRejects3MbJsonPayloadWith413ProblemDetails(): void
    {
        $routes = new RouteCollection();
        $routes->post('/api/v1/posts', function () {
            return \BsCms\Http\JsonResponse::create(['created' => true]);
        });

        $router = new Router($routes);
        $kernel = new Kernel($router);
        $kernel->addMiddleware(new RequestIdMiddleware());
        $kernel->addMiddleware(new SecurityHeadersMiddleware());
        $kernel->addMiddleware(new BodyLimitMiddleware(2 * 1024 * 1024)); // 2 MB limit
        $kernel->addMiddleware(new JsonBodyMiddleware());

        // Create 3 MB JSON payload
        $threeMb = 3 * 1024 * 1024;
        $request = (new ServerRequest('POST', '/api/v1/posts'))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Length', (string) $threeMb);
        $request->getBody()->write(str_repeat('X', $threeMb));

        $response = $kernel->handle($request);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame(413, $body['status']);
        $this->assertSame('Content Too Large', $body['title']);
        $this->assertStringContainsString('exceeds maximum allowed size of 2097152 bytes', $body['detail']);
    }
}
