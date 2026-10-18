<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Http\ProblemDetails;
use BsCms\Kernel\Environment;
use BsCms\Log\FileLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Catches all uncaught exceptions, logs them with request ID,
 * and formats RFC 9457 problem details response (suppressing internals in production).
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    private ?FileLogger $logger;
    private Environment $env;

    public function __construct(Environment $env, ?FileLogger $logger = null)
    {
        $this->env = $env;
        $this->logger = $logger;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            $requestId = (string) $request->getAttribute('request_id', '');
            if ($requestId === '' || $requestId === 'unknown') {
                $incoming = $request->getHeaderLine('X-Request-ID');
                $requestId = ($incoming !== '' && preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming)) ? $incoming : \BsCms\Security\Ulid::generate();
            }

            $this->logger?->error("Uncaught exception [{$requestId}]: " . (string) \BsCms\Log\Redactor::redact($e->getMessage()), [
                'request_id' => $requestId,
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => \BsCms\Log\Redactor::redact($e->getTraceAsString()),
            ]);

            $debug = $this->env->isDebug();
            return ProblemDetails::fromException($e, $requestId, $debug)->toResponse();
        }
    }
}
