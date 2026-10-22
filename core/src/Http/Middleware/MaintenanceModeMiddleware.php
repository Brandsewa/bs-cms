<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Http\ProblemDetails;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Checks for maintenance mode lock file and responds with 503 Service Unavailable.
 */
final class MaintenanceModeMiddleware implements MiddlewareInterface
{
    private string $lockFile;

    public function __construct(string $storageDir)
    {
        $this->lockFile = rtrim(str_replace('\\', '/', $storageDir), '/') . '/maintenance.lock';
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Allow health checks through even in maintenance mode
        if ($path === '/health') {
            return $handler->handle($request);
        }

        // Keep sign-in and the maintenance controls reachable so an operator can always lift the lock.
        if ($path === '/api/v1/system/maintenance' || str_starts_with($path, '/api/v1/system/maintenance/') || str_starts_with($path, '/api/v1/auth/') || $path === '/api/v1/me') {
            return $handler->handle($request);
        }

        if (is_file($this->lockFile)) {
            $problem = ProblemDetails::create(
                503,
                'Service Unavailable',
                'The CMS is currently undergoing maintenance. Please retry in a few moments.'
            );
            return $problem->toResponse()->withHeader('Retry-After', '60');
        }

        return $handler->handle($request);
    }
}
