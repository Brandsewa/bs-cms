<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Http\ProblemDetails;
use BsCms\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Parses JSON request bodies and attaches parsed array to request attributes.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $rawBody = (string) $request->getBody();
            if (trim($rawBody) !== '') {
                try {
                    $parsed = Json::decode($rawBody);
                    $request = $request->withParsedBody($parsed);
                } catch (Throwable $e) {
                    return ProblemDetails::create(
                        400,
                        'Bad Request',
                        'Malformed JSON in request body.'
                    )->toResponse();
                }
            }
        }

        return $handler->handle($request);
    }
}
