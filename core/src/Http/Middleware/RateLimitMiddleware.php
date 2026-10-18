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
        $actor = $request->getAttribute('actor');
        $isAuthenticated = ($actor instanceof \BsCms\Auth\Actor) && $actor->type !== \BsCms\Auth\Actor::TYPE_ANONYMOUS;

        if ($isAuthenticated) {
            // Generous authenticated bucket: 600 requests / 60 seconds
            $bucketKey = "auth:{$actor->id}";
            $result = $this->limiter->hit($bucketKey, 600, 60);
        } else {
            // Unauthenticated routes: strict bucket for sensitive auth/oauth endpoints, standard for others
            if (preg_match('#^/api/v1/auth/(login|password/forgot|password/reset|invitations|2fa/challenge)#', $path) || preg_match('#^/oauth/#', $path)) {
                $bucketKey = "strict:{$clientIp}";
                $result = $this->limiter->hit($bucketKey, 60, 60);
            } else {
                $bucketKey = "req:{$clientIp}";
                $result = $this->limiter->hit($bucketKey, 60, 60);
            }
        }

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
