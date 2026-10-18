<?php

declare(strict_types=1);

namespace BsCms\Http;

use BsCms\Routing\Router;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 Middleware Pipeline and HTTP Kernel.
 *
 * Dispatches routes, handles /admin/* SPA fallback, reserves /mcp, /oauth/*, /.well-known/*,
 * and provides post-response hook for lazy runner (litespeed_finish_request / fastcgi_finish_request).
 */
final class Kernel implements RequestHandlerInterface
{
    private Router $router;
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];
    private string $adminIndexPath;
    private int $middlewareIndex = 0;

    public function __construct(Router $router, string $adminIndexPath = '')
    {
        $this->router = $router;
        $this->adminIndexPath = $adminIndexPath;
    }

    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Handle incoming request through the middleware pipeline.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->middlewareIndex < count($this->middleware)) {
            $currentMiddleware = $this->middleware[$this->middlewareIndex];
            ++$this->middlewareIndex;

            try {
                return $currentMiddleware->process($request, $this);
            } finally {
                --$this->middlewareIndex;
            }
        }

        return $this->dispatchRoute($request);
    }

    /**
     * Dispatch route or route group fallbacks.
     */
    private function dispatchRoute(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        // Check reserved paths (Phase 8 reservation): /mcp, /oauth/*, /.well-known/*
        if ($path === '/mcp' || str_starts_with($path, '/oauth/') || str_starts_with($path, '/.well-known/')) {
            return ProblemDetails::create(
                404,
                'Not Found',
                'This endpoint is reserved for AI interface protocols (Phase 8).'
            )->toResponse();
        }

        // Health check endpoint
        if ($path === '/health') {
            if ($method !== 'GET') {
                $requestId = (string) $request->getAttribute('request_id', 'unknown');
                return ProblemDetails::create(
                    405,
                    'Method Not Allowed',
                    "Method '{$method}' is not allowed for '/health'.",
                    'https://errors.bscms.org/method-not-allowed',
                    $requestId
                )->toResponse()->withHeader('Allow', 'GET');
            }
            return JsonResponse::create(['status' => 'ok']);
        }

        // Admin SPA fallback: /admin/* serves admin/index.html
        if (str_starts_with($path, '/admin') && $method === 'GET') {
            if ($this->adminIndexPath !== '' && is_file($this->adminIndexPath)) {
                $html = (string) file_get_contents($this->adminIndexPath);
                $resp = new Response(200, ['Content-Type' => 'text/html; charset=utf-8']);
                $resp->getBody()->write($html);
                return $resp;
            }

            // Placeholder admin page until Phase 5
            $placeholder = '<!DOCTYPE html><html><head><title>BS CMS Admin</title></head><body><div id="root"><h1>BS CMS Admin</h1><p>Admin SPA placeholder.</p></div></body></html>';
            $resp = new Response(200, ['Content-Type' => 'text/html; charset=utf-8']);
            $resp->getBody()->write($placeholder);
            return $resp;
        }

        // Dispatch with router
        $match = $this->router->dispatch($method, $path);

        if ($match['status'] === 404) {
            $requestId = (string) $request->getAttribute('request_id', 'unknown');
            return ProblemDetails::notFound("The requested URL '{$path}' was not found.", $requestId)->toResponse();
        }

        if ($match['status'] === 405) {
            $requestId = (string) $request->getAttribute('request_id', 'unknown');
            /** @var list<string> $allowed */
            $allowed = $match['allowed_methods'] ?? [];
            return ProblemDetails::create(
                405,
                'Method Not Allowed',
                "Method '{$method}' is not allowed for '{$path}'.",
                'https://errors.bscms.org/method-not-allowed',
                $requestId
            )->toResponse()->withHeader('Allow', implode(', ', $allowed));
        }

        if (!isset($match['handler'])) {
            $requestId = (string) $request->getAttribute('request_id', 'unknown');
            return ProblemDetails::notFound("The requested URL '{$path}' was not found.", $requestId)->toResponse();
        }

        /** @var callable $handler */
        $handler = $match['handler'];
        /** @var array<string, string> $params */
        $params = $match['params'] ?? [];

        foreach ($params as $k => $v) {
            $request = $request->withAttribute($k, $v);
        }

        /** @var ResponseInterface $response */
        $response = $handler($request, $params);
        return $response;
    }

    /**
     * Terminate the request and invoke the lazy job runner if supported and enabled.
     */
    public static function terminate(?callable $jobRunner = null): void
    {
        // Flush fastcgi / litespeed response to client
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Run post-response job runner (max 5 seconds)
        if ($jobRunner !== null) {
            $jobRunner();
        }
    }
}
