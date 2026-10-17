<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Http\ProblemDetails;
use BsCms\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rate limiting middleware enforcing rate limits and setting standard RateLimit-* headers.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private RateLimiter $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        $clientIp = $this->limiter->resolveClientIp($request->getServerParams(), $headers);
        $result = $this->limiter->hit("req:{$clientIp}");

        if (!$result['allowed']) {
            $problem = ProblemDetails::tooManyRequests($result['retry_after'], $path);
            return $problem->toResponse()
                ->withHeader('Retry-After', (string) $result['retry_after'])
                ->withHeader('RateLimit-Limit', (string) $result['limit'])
                ->withHeader('RateLimit-Remaining', (string) $result['remaining'])
                ->withHeader('RateLimit-Reset', (string) $result['reset']);
        }

        $response = $handler->handle($request);

        return $response
            ->withHeader('RateLimit-Limit', (string) $result['limit'])
            ->withHeader('RateLimit-Remaining', (string) $result['remaining'])
            ->withHeader('RateLimit-Reset', (string) $result['reset']);
    }
}
