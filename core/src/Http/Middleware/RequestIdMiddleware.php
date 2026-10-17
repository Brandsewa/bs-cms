<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Security\Ulid;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Assigns or propagates X-Request-ID to request attributes and response headers.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incomingId = $request->getHeaderLine('X-Request-ID');
        $requestId = $incomingId !== '' ? $incomingId : Ulid::generate();

        $request = $request->withAttribute('request_id', $requestId);
        $response = $handler->handle($request);

        return $response->withHeader('X-Request-ID', $requestId);
    }
}
