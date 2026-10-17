<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Http\ProblemDetails;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces maximum request body size (default 2 MB).
 */
final class BodyLimitMiddleware implements MiddlewareInterface
{
    private int $maxBytes;

    public function __construct(int $maxBytes = 2 * 1024 * 1024)
    {
        $this->maxBytes = $maxBytes;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentLength = $request->getHeaderLine('Content-Length');
        if ($contentLength !== '' && (int) $contentLength > $this->maxBytes) {
            return ProblemDetails::create(
                413,
                'Content Too Large',
                "Request body exceeds maximum allowed size of {$this->maxBytes} bytes."
            )->toResponse();
        }

        $size = $request->getBody()->getSize();
        if ($size !== null && $size > $this->maxBytes) {
            return ProblemDetails::create(
                413,
                'Content Too Large',
                "Request body exceeds maximum allowed size of {$this->maxBytes} bytes."
            )->toResponse();
        }

        return $handler->handle($request);
    }
}
