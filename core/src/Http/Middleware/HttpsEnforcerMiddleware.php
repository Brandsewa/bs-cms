<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Kernel\Environment;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces HTTPS in production; redirects plain HTTP requests to HTTPS with 301.
 */
final class HttpsEnforcerMiddleware implements MiddlewareInterface
{
    private Environment $env;

    public function __construct(Environment $env)
    {
        $this->env = $env;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $host = $uri->getHost();

        // Localhost / dev bypass
        if ($host === 'localhost' || $host === '127.0.0.1' || !$this->env->isProduction()) {
            return $handler->handle($request);
        }

        $scheme = $uri->getScheme();
        $serverParams = $request->getServerParams();
        $isHttps = $scheme === 'https'
            || $request->getHeaderLine('X-Forwarded-Proto') === 'https'
            || (($serverParams['HTTPS'] ?? '') === 'on');

        if (!$isHttps) {
            $secureUri = (string) $uri->withScheme('https')->withPort(null);
            return (new Response(301))
                ->withHeader('Location', $secureUri)
                ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response = $handler->handle($request);
        return $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
