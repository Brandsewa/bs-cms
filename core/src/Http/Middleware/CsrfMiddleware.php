<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Http\ProblemDetails;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces CSRF token check and Origin/Referer verification for mutating requests
 * authenticated via session cookies.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly ?string $allowedOrigin = null
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        if (in_array($method, self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        /** @var array<string, mixed>|null $session */
        $session = $request->getAttribute('session');

        // Only enforce CSRF for session-cookie authenticated requests.
        // Bearer API tokens / external webhooks are not vulnerable to browser CSRF.
        if ($session === null) {
            return $handler->handle($request);
        }

        // 1. Verify CSRF token from X-CSRF-Token header or csrf_token parameter
        $headerToken = $request->getHeaderLine('X-CSRF-Token');
        if ($headerToken === '') {
            $parsedBody = $request->getParsedBody();
            if (is_array($parsedBody) && isset($parsedBody['csrf_token']) && is_string($parsedBody['csrf_token'])) {
                $headerToken = $parsedBody['csrf_token'];
            } else {
                $rawBody = (string) $request->getBody();
                if ($rawBody !== '') {
                    parse_str($rawBody, $parsedQuery);
                    if (isset($parsedQuery['csrf_token']) && is_string($parsedQuery['csrf_token'])) {
                        $headerToken = $parsedQuery['csrf_token'];
                    }
                }
            }
        }

        $expectedToken = (string) ($session['csrf_secret'] ?? '');

        if ($headerToken === '' || $expectedToken === '' || !hash_equals($expectedToken, $headerToken)) {
            return ProblemDetails::forbidden('Invalid or missing CSRF token.')->toResponse();
        }

        // 2. Defense-in-depth: Origin / Referer check
        $origin = $request->getHeaderLine('Origin');
        $referer = $request->getHeaderLine('Referer');

        if ($origin !== '') {
            if (!$this->isOriginAllowed($origin, $request)) {
                return ProblemDetails::forbidden('CSRF Origin mismatch.')->toResponse();
            }
        } elseif ($referer !== '') {
            $parsed = parse_url($referer);
            $refererOrigin = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '') . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
            if (!$this->isOriginAllowed($refererOrigin, $request)) {
                return ProblemDetails::forbidden('CSRF Referer mismatch.')->toResponse();
            }
        }

        return $handler->handle($request);
    }

    private function isOriginAllowed(string $sourceOrigin, ServerRequestInterface $request): bool
    {
        $normalizedSource = rtrim(strtolower($sourceOrigin), '/');

        if ($this->allowedOrigin !== null && $this->allowedOrigin !== '') {
            $normalizedAllowed = rtrim(strtolower($this->allowedOrigin), '/');
            if ($normalizedSource === $normalizedAllowed) {
                return true;
            }
        }

        $uri = $request->getUri();
        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'http';
        $host = $request->getHeaderLine('Host');
        if ($host === '') {
            $host = $uri->getHost();
            if ($uri->getPort() !== null) {
                $host .= ':' . $uri->getPort();
            }
        }

        $expectedOrigin = rtrim(strtolower("{$scheme}://{$host}"), '/');
        return $normalizedSource === $expectedOrigin;
    }
}
